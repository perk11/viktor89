<?php

namespace Perk11\Viktor89\Assistant\Tool;

readonly class UploadedGeneratedImage
{
    public function __construct(
        public string $publicUrl,
    ) {
    }

    public function toRichMarkdown(string $caption = ''): string
    {
        $sanitizedCaption = str_replace(['[', ']', "\r", "\n", '"'], ['(', ')', ' ', ' ', "'"], trim($caption));

        $output = '![](' . $this->publicUrl;
        if ($sanitizedCaption !== '') {
            $output .= ' "' . $sanitizedCaption . '"';
        }
        $output .= ')';

        return $output;
    }
}
