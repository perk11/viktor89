<?php

namespace Perk11\Viktor89\Util;

class TelegramRichMarkdown
{
    /**
     * Markdown image syntax: ![alt](url) or ![alt](url "caption").
     */
    private const string IMAGE_REGEX = '/!\[([^]]*)]\(([^)]+)\)/';

    /**
     * Replace all markdown images with a placeholder.
     * LLMs should never generate image markdown directly; images are only
     * inserted by tools (e.g. ImageGeneratorInlineToolCallExecutor) after
     * generation is complete and go through a separate code path.
     */
    public static function removeImages(string $text): string
    {
        return preg_replace_callback(
            self::IMAGE_REGEX,
            static fn(array $matches) => "![{$matches[1]}](<invalid image>)",
            $text,
        );
    }
}
