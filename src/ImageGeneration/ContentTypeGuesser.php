<?php

namespace Perk11\Viktor89\ImageGeneration;

class ContentTypeGuesser
{
    public static function guessFileExtension(string $fileContents): string
    {
        $dataLength = strlen($fileContents);

        // JPEG: FF D8 FF
        if (
            $dataLength >= 3 &&
            $fileContents[0] === "\xFF" &&
            $fileContents[1] === "\xD8" &&
            $fileContents[2] === "\xFF"
        ) {
            return 'jpg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (
            $dataLength >= 8 &&
            $fileContents[0] === "\x89" &&
            $fileContents[1] === 'P' &&
            $fileContents[2] === 'N' &&
            $fileContents[3] === 'G' &&
            $fileContents[4] === "\r" &&
            $fileContents[5] === "\n" &&
            $fileContents[6] === "\x1A" &&
            $fileContents[7] === "\n"
        ) {
            return 'png';
        }

        // WebP: "RIFF" .... "WEBP"
        if (
            $dataLength >= 12 &&
            $fileContents[0] === 'R' &&
            $fileContents[1] === 'I' &&
            $fileContents[2] === 'F' &&
            $fileContents[3] === 'F' &&
            $fileContents[8] === 'W' &&
            $fileContents[9] === 'E' &&
            $fileContents[10] === 'B' &&
            $fileContents[11] === 'P'
        ) {
            return 'webp';
        }

        return 'unknown';
    }
}
