<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Util\ImageNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Converts an AssistantContext into Anthropic Messages API params:
 *  - consecutive messages with the same role are merged into one turn;
 *  - tool calls become tool_use blocks, answered by tool_result blocks at the
 *    start of the following user turn;
 *  - images are only allowed in user turns and tool results, so images the bot
 *    sent itself (e.g. generated photos) are moved into the next user turn;
 *  - the conversation must start and end with a user turn, so placeholders are
 *    added where it would not.
 */
final class AnthropicMessageConverter implements MessageConverterInterface
{
    private const string ASSISTANT_IMAGE_LABEL = '[Image you sent earlier in this conversation:]';
    private const string CONVERSATION_START_PLACEHOLDER = '[Start of conversation]';
    private const string EMPTY_MESSAGE_PLACEHOLDER = '[Message without text content]';

    private readonly ImageNormalizer $imageNormalizer;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->imageNormalizer = new ImageNormalizer(['image/jpeg', 'image/png', 'image/webp'], $logger);
    }

    /**
     * Reasoning from earlier turns is not replayed: thinking blocks are only
     * accepted with the signature the API issued for them, which is not stored.
     *
     * @return array{system: ?string, messages: list<array{role: string, content: list<array<string, mixed>>}>}
     */
    public function convert(AssistantContext $context, bool $includeToolCalls): array
    {
        if ($context->responseStart !== null) {
            throw new \InvalidArgumentException('responseStart is not supported by the Anthropic API');
        }

        $messages = [];
        $pendingAssistantImages = [];

        foreach ($context->messages as $message) {
            $textBlocks = trim($message->text) === '' ? [] : [['type' => 'text', 'text' => $message->text]];
            $imageBlock = $message->photo === null ? null : $this->buildImagePart($message->photo);

            if ($message->isUser) {
                self::appendPendingAssistantImages($messages, $pendingAssistantImages);
                self::append($messages, 'user', $imageBlock === null ? $textBlocks : [...$textBlocks, $imageBlock]);
                continue;
            }

            if ($imageBlock !== null) {
                $pendingAssistantImages[] = $imageBlock;
            }
            $toolCalls = $includeToolCalls ? $message->toolCalls : [];
            self::append($messages, 'assistant', [
                ...$textBlocks,
                ...array_map(
                    static fn (ToolCall $toolCall): array => [
                        'type'  => 'tool_use',
                        'id'    => self::sanitizeToolUseId($toolCall->id),
                        'name'  => $toolCall->name,
                        'input' => self::decodeToolArguments($toolCall->arguments),
                    ],
                    $toolCalls,
                ),
            ]);
            self::append($messages, 'user', array_map(
                static fn (ToolCall $toolCall): array => self::buildToolResultBlock($toolCall),
                $toolCalls,
            ));
        }
        self::appendPendingAssistantImages($messages, $pendingAssistantImages);

        // A last user message with nothing the API accepts (e.g. a document
        // without alt text) is dropped above; a request ending with an
        // assistant turn is rejected as a prefill, and an empty one is invalid.
        if ($messages === [] || $messages[array_key_last($messages)]['role'] === 'assistant') {
            $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => self::EMPTY_MESSAGE_PLACEHOLDER]]];
        }
        if ($messages[0]['role'] === 'assistant') {
            array_unshift($messages, [
                'role'    => 'user',
                'content' => [['type' => 'text', 'text' => self::CONVERSATION_START_PLACEHOLDER]],
            ]);
        }

        return [
            'system'   => $context->systemPrompt === null || trim($context->systemPrompt) === '' ? null : $context->systemPrompt,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{type: string, source: array{type: string, mediaType: string, data: string}}|null
     */
    public function buildImagePart(string $image): ?array
    {
        $normalizedImage = $this->imageNormalizer->normalize($image);
        if ($normalizedImage === null) {
            return null;
        }

        return [
            'type'   => 'image',
            'source' => [
                'type'      => 'base64',
                'mediaType' => $normalizedImage->mediaType,
                'data'      => $normalizedImage->toBase64(),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $blocks
     */
    private static function append(array &$messages, string $role, array $blocks): void
    {
        if ($blocks === []) {
            return;
        }
        $lastKey = array_key_last($messages);
        if ($lastKey !== null && $messages[$lastKey]['role'] === $role) {
            array_push($messages[$lastKey]['content'], ...$blocks);
            return;
        }
        $messages[] = ['role' => $role, 'content' => $blocks];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $pendingAssistantImages
     */
    private static function appendPendingAssistantImages(array &$messages, array &$pendingAssistantImages): void
    {
        if ($pendingAssistantImages === []) {
            return;
        }
        self::append($messages, 'user', [
            ['type' => 'text', 'text' => self::ASSISTANT_IMAGE_LABEL],
            ...$pendingAssistantImages,
        ]);
        $pendingAssistantImages = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildToolResultBlock(ToolCall $toolCall): array
    {
        $block = [
            'type'      => 'tool_result',
            'toolUseID' => self::sanitizeToolUseId($toolCall->id),
        ];
        if ($toolCall->result !== null && $toolCall->result !== '') {
            $block['content'] = $toolCall->result;
        }

        return $block;
    }

    /**
     * Tool call ids from history may come from other backends; the Anthropic
     * API only accepts [a-zA-Z0-9_-].
     */
    private static function sanitizeToolUseId(string $id): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? '';

        return $sanitized === '' ? 'toolu_' . md5($id) : $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeToolArguments(string $arguments): array
    {
        if (trim($arguments) === '') {
            return [];
        }
        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['arguments' => $arguments];
        }

        return is_array($decoded) ? $decoded : ['arguments' => $decoded];
    }
}
