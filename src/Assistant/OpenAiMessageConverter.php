<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use JsonException;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Util\ImageNormalizer;
use Psr\Log\LoggerInterface;

/** Converts between AssistantContext and OpenAI chat completion messages. */
final class OpenAiMessageConverter implements MessageConverterInterface
{
    private readonly ImageNormalizer $imageNormalizer;

    public function __construct(?LoggerInterface $logger = null)
    {
        // Chat completion servers reject webp data URLs.
        $this->imageNormalizer = new ImageNormalizer(['image/jpeg', 'image/png'], $logger);
    }

    public static function contextFromJson(string $json): AssistantContext
    {
        try {
            $parsedJson = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAiContextParsingException($e->getMessage());
        }
        $context = new AssistantContext();
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

    /**
     * @return list<array<string, mixed>>
     */
    public function convert(AssistantContext $context, bool $includeToolCalls = true): array
    {
        if ($context->responseStart !== null) {
            throw new \InvalidArgumentException('responseStart specified, but it can not be converted to OpenAi array');
        }
        $openAiMessages = [];
        if ($context->systemPrompt !== null) {
            $openAiMessages[] = [
                'role'    => 'system',
                'content' => $context->systemPrompt,
            ];
        }

        foreach ($context->messages as $message) {
            $role = $message->isUser ? 'user' : 'assistant';
            $messageContentParts = [];
            $messageHasToolCalls = $includeToolCalls && !$message->isUser && count($message->toolCalls) > 0;
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
                self::foldContentIntoParts($previousMessage, $messageContentParts);
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
                    self::foldContentIntoParts($assistantMsg, $assistantContentParts);
                    $messageText = trim($message->text ?? '');
                    if ($messageText !== '') {
                        $assistantContentParts[] = ['type' => 'text', 'text' => $messageText];
                    }
                    // A sibling photo message (e.g. the image produced by an
                    // image-generation tool call) lands here as a trailing
                    // assistant turn after the tool result. Its image must be
                    // folded in, otherwise the model that supports images loses
                    // the generated photo from its own history. (A bot photo's
                    // caption is already stripped upstream, so only the image is
                    // carried over here.)
                    if ($message->photo !== null) {
                        $photoPart = $this->buildImagePart($message->photo);
                        if ($photoPart !== null) {
                            $assistantContentParts[] = $photoPart;
                        }
                    }
                    if ($message->reasoning !== null) {
                        $assistantMsg['reasoning_content'] = $message->reasoning;
                    }
                    $assistantMsg['content'] = self::collapseContentParts($assistantContentParts);
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
                $photoPart = $this->buildImagePart($message->photo);
                if ($photoPart !== null) {
                    $messageContentParts[] = $photoPart;
                }
            }

            if (empty($messageContentParts) && !$messageHasToolCalls && !$messageHasReasoning) {
                continue;
            }

            $openAiMessage = [
                'role'    => $role,
                'content' => self::collapseContentParts($messageContentParts),
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
     * @return array{type: string, image_url: array{url: string}}|null
     */
    public function buildImagePart(string $image): ?array
    {
        $normalizedImage = $this->imageNormalizer->normalize($image);
        if ($normalizedImage === null) {
            return null;
        }

        return [
            'type'      => 'image_url',
            'image_url' => ['url' => $normalizedImage->toDataUrl()],
        ];
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

    /**
     * Fold the content of an already-emitted OpenAI message into a content-parts
     * array, so it can be merged with another message content.
     *
     * @param array{content: string|array} $message
     * @param array<int, array{type: string, text?: string}> $contentParts
     */
    private static function foldContentIntoParts(array $message, array &$contentParts): void
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
     * Collapse content parts into the most compact representation: empty string
     * when there are none, a plain string for a single text part, or the parts
     * array for mixed text/image content.
     *
     * @param array<int, array{type: string, text?: string}> $contentParts
     */
    private static function collapseContentParts(array $contentParts): string|array
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
