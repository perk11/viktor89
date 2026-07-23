<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use JsonException;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AssistantContext
{
    /**
     * Logger shared across all AssistantContext instances. AssistantContext is a
     * plain value object created all over the codebase, so the logger is injected
     * statically from the composition root instead of through a constructor.
     */
    private static ?LoggerInterface $logger = null;

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

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
                   // A sibling photo message (e.g. the image produced by an
                   // image-generation tool call) lands here as a trailing
                   // assistant turn after the tool result. Its image must be
                   // folded in alongside its caption text, otherwise the model
                   // that supports images loses the generated photo from its
                   // own history.
                   if ($message->photo !== null) {
                       $photoPart = $this->buildPhotoContentPart($message->photo);
                       if ($photoPart !== null) {
                           $assistantContentParts[] = $photoPart;
                       }
                   }
                   if ($message->reasoning !== null) {
                       $assistantMsg['reasoning_content'] = $message->reasoning;
                   }
                   $assistantMsg['content'] = $this->collapseContentParts($assistantContentParts);
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
                $photoPart = $this->buildPhotoContentPart($message->photo);
                if ($photoPart !== null) {
                    $messageContentParts[] = $photoPart;
                }
            }

            if (empty($messageContentParts) && !$messageHasToolCalls && !$messageHasReasoning) {
                continue;
            }

            $finalContent = $this->collapseContentParts($messageContentParts);

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

    /**
     * Convert a raw photo blob into an OpenAI image_url content part (data URL),
     * converting webp to png because chat APIs reject webp data URLs. Returns
     * null when the blob cannot be encoded.
     *
     * @return array{type: string, image_url: array{url: string}}|null
     */
    private function buildPhotoContentPart(string $photo): ?array
    {
        $extension = ContentTypeGuesser::guessFileExtension($photo);
        $url = null;

        switch ($extension) {
            case 'jpg':
                $url = 'data:image/jpeg;base64,' . base64_encode($photo);
                break;
            case 'png':
                $url = 'data:image/png;base64,' . base64_encode($photo);
                break;
            case 'webp':
                $gdImageFromWebpString = imagecreatefromstring($photo);
                if ($gdImageFromWebpString === false) {
                    self::$logger?->log(LogLevel::ERROR, 'Failed to create image from webp');
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

        if ($url === null) {
            return null;
        }

        return [
            'type'      => 'image_url',
            'image_url' => ['url' => $url],
        ];
    }

    /**
     * Collapse content parts into the most compact representation: empty string
     * when there are none, a plain string for a single text part, or the parts
     * array for mixed text/image content.
     *
     * @param array<int, array{type: string, text?: string}> $contentParts
     */
    private function collapseContentParts(array $contentParts): string|array
    {
        if (empty($contentParts)) {
            return '';
        }
        if (count($contentParts) === 1 && $contentParts[0]['type'] === 'text') {
            return $contentParts[0]['text'];
        }

        return $contentParts;
    }

}
