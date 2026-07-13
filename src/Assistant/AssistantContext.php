<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use JsonException;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;

class AssistantContext
{
    public ?string $systemPrompt = null;

    public ?string $responseStart = null;

    /** @var AssistantContextMessage[] */
    public array $messages = [];

    public static function fromOpenAiMessagesJson(string $json): self
    {
        try {
            $parsedJson = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAiContextParsingException($e->getMessage());
        }
        $context = new self();
        foreach ($parsedJson as $item) {
            if (!is_array($item)) {
                throw new OpenAiContextParsingException("Non-array item encountered while parsing json\n$json");
            }
            if (!array_key_exists('content', $item)) {
                throw new OpenAiContextParsingException("Item is missing content\n$json");
            }
            $message = new AssistantContextMessage();
            if (array_key_exists('role', $item)) {
                switch ($item['role']) {
                    case 'user':
                        $message->isUser = true;
                        break;
                    case 'assistant':
                        $message->isUser = false;
                        break;
                    case 'system':
                        $context->systemPrompt = $item['content'];
                        continue 2;
                    default:
                        throw new OpenAiContextParsingException("Unknown message role: " . $item['role']);
                }
            } else {
                $message->isUser = true;
            }
            $message->text = $item['content'];
            $context->messages[] = $message;
        }

        return $context;
    }
    public function toOpenAiMessagesArray(bool $includeToolMessages = true): array
    {
        if ($this->responseStart !== null) {
            throw new Exception('responseStart specified, but it can not be converted to OpenAi array');
        }
        $openAiMessages = [];
        if ($this->systemPrompt !== null) {
            $openAiMessages[] = [
                'role'    => 'system',
                'content' => $this->systemPrompt,
            ];
        }

       foreach ($this->messages as $message) {
           $role = $message->isUser ? 'user' : 'assistant';
           $messageContentParts = [];
           $messageHasToolCalls = $includeToolMessages && !$message->isUser && count($message->toolCalls) > 0;
           $messageHasReasoning = $message->reasoning !== null;

           $previousMessageKey = array_key_last($openAiMessages);
           $previousMessage = $previousMessageKey !== null ? $openAiMessages[$previousMessageKey] : null;

           // Merge consecutive messages with the same conversational role so
           // the final array alternates user/assistant, which strict chat
           // templates (Qwen, Llama, Gemma, …) require. Two cases are handled:
           //
           //  1. Directly consecutive same-role messages (common in group
           //     chats where multiple users post, or when the bot sends
           //     consecutive replies — e.g. an image-generation turn logs the
           //     photo as one assistant message and the text reply, carrying
           //     the tool_calls, as another). We pop the previous message and
           //     fold its content into this one. The previous message must not
           //     carry tool_calls (otherwise its tool-result messages would be
           //     orphaned), but the current one may — its tool_calls and the
           //     resulting tool messages are emitted right after the merge.
           //
           //  2. assistant(tool_calls) → tool(result…) → assistant(text): the
           //     trailing assistant would create a second consecutive assistant
           //     message. We append its content directly to the originating
           //     assistant message that carries the tool_calls, leaving the
           //     tool-result messages in place.
           //
           // reasoning_content from the older message is stale historical
           // context and is not carried forward.
           if (
               $previousMessage !== null
               && $previousMessage['role'] === $role
               && !isset($previousMessage['tool_calls'])
           ) {
               // Case 1: pop and fold.
               array_pop($openAiMessages);
               $this->foldContentIntoParts($previousMessage, $messageContentParts);
           } elseif (
               $role === 'assistant'
               && !$messageHasToolCalls
               && $previousMessage !== null
               && $previousMessage['role'] === 'tool'
           ) {
               // Case 2: walk back past tool messages to the originating
               // assistant and append this message's content to it.
               $toolMessageCount = 0;
               for ($back = $previousMessageKey; $back >= 0; $back--) {
                   if ($openAiMessages[$back]['role'] !== 'tool') {
                       break;
                   }
                   $toolMessageCount++;
               }
               $assistantKey = $previousMessageKey - $toolMessageCount;
               if (
                   $assistantKey >= 0
                   && ($openAiMessages[$assistantKey]['role'] ?? null) === 'assistant'
               ) {
                   $assistantMsg = &$openAiMessages[$assistantKey];
                   $assistantContentParts = [];
                   $this->foldContentIntoParts($assistantMsg, $assistantContentParts);
                   $messageText = trim($message->text ?? '');
                   if ($messageText !== '') {
                       $assistantContentParts[] = ['type' => 'text', 'text' => $messageText];
                   }
                   if ($message->reasoning !== null) {
                       $assistantMsg['reasoning_content'] = $message->reasoning;
                   }
                   $assistantMsg['content'] = count($assistantContentParts) === 1
                       ? $assistantContentParts[0]['text']
                       : $assistantContentParts;
                   unset($assistantMsg);
                   // This message has been folded into the earlier assistant
                   // message; skip emitting it as a separate message.
                   continue;
               }
           }

           $messageText = $message->text ?? '';
            if (is_string($messageText) && trim($messageText) !== '') {
                $messageContentParts[] = [
                    'type' => 'text',
                    'text' => $messageText,
                ];
            }

            if ($message->photo !== null) {
                $extension = ContentTypeGuesser::guessFileExtension($message->photo);
                $url = null;

                switch ($extension) {
                    case 'jpg':
                        $url = 'data:image/jpeg;base64,' . base64_encode($message->photo);
                        break;
                    case 'png':
                        $url = 'data:image/png;base64,' . base64_encode($message->photo);
                        break;
                    case 'webp':
                        $gdImageFromWebpString = imagecreatefromstring($message->photo);
                        if ($gdImageFromWebpString === false) {
                            echo "Failed to create image from webp\n";
                            $url = null;
                        } else {
                            ob_start();
                            imagepng($gdImageFromWebpString);
                            $pngImageBinaryString = ob_get_clean();
                            imagedestroy($gdImageFromWebpString);
                            $url = 'data:image/png;base64,' . base64_encode($pngImageBinaryString);
                        }
                        break;
                }
                if ($url !== null) {
                    $messageContentParts[] = [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => $url,
                        ],
                    ];
                }
            }

            if (empty($messageContentParts) && !$messageHasToolCalls && !$messageHasReasoning) {
                continue;
            }

            if (empty($messageContentParts)) {
                $finalContent = '';
            } elseif (count($messageContentParts) === 1 && $messageContentParts[0]['type'] === 'text') {
                $finalContent = $messageContentParts[0]['text'];
            } else {
                $finalContent = $messageContentParts;
            }

            $openAiMessage = [
                'role'    => $role,
                'content' => $finalContent,
            ];

            if ($messageHasReasoning) {
                $openAiMessage['reasoning_content'] = $message->reasoning;
            }

            if ($messageHasToolCalls) {
                $openAiMessage['tool_calls'] = array_map(
                    static fn(ToolCall $toolCall): array => [
                        'id' => $toolCall->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolCall->name,
                            'arguments' => $toolCall->arguments,
                        ],
                    ],
                    $message->toolCalls
                );
            }

            $openAiMessages[] = $openAiMessage;

            if ($messageHasToolCalls) {
                foreach ($message->toolCalls as $toolCall) {
                    $openAiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall->id,
                        'content' => $toolCall->result,
                    ];
                }
            }
        }

        return $openAiMessages;
    }

    /**
     * Compact, human-readable description of the context's messages. Independent
     * of toOpenAiMessagesArray(), so it works even when responseStart is set.
     * Intended for diagnostic logging when an LLM request fails.
     */
    public function describeForLog(): string
    {
        $lines = [];
        if ($this->systemPrompt !== null) {
            $lines[] = 'system: ' . $this->preview($this->systemPrompt);
        }
        if ($this->responseStart !== null) {
            $lines[] = 'responseStart: ' . $this->preview($this->responseStart);
        }
        foreach ($this->messages as $i => $message) {
            $role = $message->isUser ? 'user' : 'assistant';
            $parts = [];
            if (trim($message->text ?? '') !== '') {
                $parts[] = $this->preview($message->text);
            }
            if ($message->photo !== null) {
                $parts[] = '[image]';
            }
            if (count($message->toolCalls) > 0) {
                $names = array_map(static fn(ToolCall $tc) => $tc->name, $message->toolCalls);
                $parts[] = '[tool_calls: ' . implode(', ', $names) . ']';
            }
            if ($message->reasoning !== null) {
                $parts[] = '[reasoning]';
            }
            $lines[] = sprintf('#%d %s: %s', $i, $role, implode(' ', $parts));
        }

        return implode("\n", $lines);
    }

    /**
     * Role-only summary of an OpenAI-format messages array, e.g.
     * "system → user → assistant → tool". Useful as a one-line diagnostic.
     *
     * @param array<int, array<string, mixed>> $openAiMessages
     */
    public static function summarizeRoleSequence(array $openAiMessages): string
    {
        $roles = [];
        foreach ($openAiMessages as $message) {
            $roles[] = $message['role'] ?? '?';
        }

        return implode(' → ', $roles);
    }

    private function preview(string $text): string
    {
        $collapsed = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($collapsed) > 120) {
            $collapsed = mb_substr($collapsed, 0, 120) . '…';
        }

        return $collapsed;
    }

    /**
     * Fold the content of an already-emitted OpenAI message into a content-parts
     * array, so it can be merged with another message content.
     *
     * @param array{content: string|array} $message
     * @param array<int, array{type: string, text?: string}> $contentParts
     */
    private function foldContentIntoParts(array $message, array &$contentParts): void
    {
        if (is_string($message['content'])) {
            if (trim($message['content']) !== '') {
                $contentParts[] = [
                    'type' => 'text',
                    'text' => $message['content'],
                ];
            }
        } elseif (is_array($message['content'])) {
            foreach ($message['content'] as $part) {
                $contentParts[] = $part;
            }
        }
    }

}
