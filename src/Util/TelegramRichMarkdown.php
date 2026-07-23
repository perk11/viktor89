<?php

namespace Perk11\Viktor89\Util;

class TelegramRichMarkdown
{
    /**
     * Markdown image syntax: ![alt](url) or ![alt](url "caption").
     */
    private const string IMAGE_REGEX = '/!\[([^]]*)]\(([^)]+)\)/';

    /**
     * Paired <img ...>content</img> tags: the inner content is meaningful, so
     * only the tags themselves are stripped and the content is preserved.
     */
    private const string HTML_IMG_PAIRED_REGEX = '/<img\b[^>]*>(.*?)<\/img>/is';

    /**
     * Void <img ...> tags (no closing tag), e.g. <img src="url" alt="text">.
     */
    private const string HTML_IMG_REGEX = '/<img\b[^>]*>/i';

    /**
     * Inline code spans and fenced code blocks. Their contents are literal
     * text (e.g. tool-call arguments shown to the user, which legitimately
     * contain <img> tags) and must never be transformed.
     */
    private const string CODE_SPAN_REGEX = '/(```.*?```|`[^`]*`)/s';

    /**
     * Replace image markup outside of code spans/blocks with a placeholder.
     * LLMs should never generate image markdown directly; images are only
     * inserted by tools (e.g. ImageGeneratorInlineToolCallExecutor) after
     * generation is complete and go through a separate code path. Image
     * markdown/HTML is replaced with a code block so Telegram can never try to
     * render it as an image. Markup inside code spans/blocks is left intact,
     * since there it is literal text rather than renderable markup.
     */
    public static function removeImages(string $text): string
    {
        $segments = preg_split(self::CODE_SPAN_REGEX, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        foreach ($segments as $i => $segment) {
            $result .= $i % 2 === 1 ? $segment : self::stripImages($segment);
        }

        return $result;
    }

    private static function stripImages(string $text): string
    {
        $text = preg_replace_callback(
            self::IMAGE_REGEX,
            static fn(array $matches) => self::invalidImagePlaceholder($matches[1]),
            $text,
        );

        $text = preg_replace_callback(
            self::HTML_IMG_PAIRED_REGEX,
            static fn(array $matches) => $matches[1],
            $text,
        );

        return preg_replace_callback(
            self::HTML_IMG_REGEX,
            static function (array $matches): string {
                $alt = preg_match('/\balt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $matches[0], $altMatches)
                    ? trim($altMatches[1], '"\'')
                    : '';

                return self::invalidImagePlaceholder($alt);
            },
            $text,
        );
    }

    private static function invalidImagePlaceholder(string $alt): string
    {
        return $alt === '' ? '`<invalid image>`' : "`<invalid image: {$alt}>`";
    }
}
