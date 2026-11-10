<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\Util\Telegram\ChatActionEnum;

/**
 * The model and kind of media an MCP tool generates, published by the
 * inference MCP server in the tool's _meta so the bot can show which model is
 * working while the tool runs.
 */
final readonly class McpGeneratedMedia
{
    public const string META_MODEL = 'viktor89/model';
    public const string META_MEDIA = 'viktor89/media';

    private function __construct(
        public string $model,
        public string $media,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function toToolMeta(string $model, string $media): array
    {
        return [self::META_MODEL => $model, self::META_MEDIA => $media];
    }

    public static function fromToolMeta(?array $meta): ?self
    {
        $model = $meta[self::META_MODEL] ?? null;
        $media = $meta[self::META_MEDIA] ?? null;
        if (!is_string($model) || $model === '' || !is_string($media) || $media === '') {
            return null;
        }

        return new self($model, $media);
    }

    public function runningStatus(): string
    {
        return "[$this->model] Generating $this->media";
    }

    public function chatAction(): ?ChatActionEnum
    {
        return match ($this->media) {
            'image' => ChatActionEnum::upload_photo,
            'video' => ChatActionEnum::record_video,
            'audio' => ChatActionEnum::record_voice,
            default => null,
        };
    }
}
