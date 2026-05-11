<?php

namespace Perk11\Viktor89\Util;

class TelegramMarkdownV2
{
    private const ENCODING = 'UTF-8';

    private const RESERVED_CHARACTERS = '_*[]()~`>#+-=|{}.!';

    private const FORMATTING_MARKERS = [
        '||' => '||',
        '__' => '__',
        '~~' => '~',
        '**' => '*',
        '~' => '~',
        '*' => '*',
        '_' => '_',
    ];

    public static function escape(string $text): string
    {
        return self::escapeMarkdownText($text);
    }

    public static function makeValid(string $text): string
    {
        $result = '';
        $offset = 0;
        $length = self::length($text);
        $codeBlockMarkerLength = self::length('```');

        while ($offset < $length) {
            $openingPosition = self::findUnescaped($text, '```', $offset);

            if ($openingPosition === null) {
                $result .= self::processTextPart(self::slice($text, $offset));
                break;
            }

            $closingPosition = self::findUnescaped($text, '```', $openingPosition + $codeBlockMarkerLength);

            if ($closingPosition === null) {
                $result .= self::processTextPart(self::slice($text, $offset));
                break;
            }

            $result .= self::processTextPart(self::slice($text, $offset, $openingPosition - $offset));
            $result .= '```' . self::escapeCodeContent(self::slice($text, $openingPosition + $codeBlockMarkerLength, $closingPosition - $openingPosition - $codeBlockMarkerLength)) . '```';
            $offset = $closingPosition + $codeBlockMarkerLength;
        }

        return $result;
    }

    private static function processTextPart(string $text): string
    {
        $result = '';
        $plainTextStart = 0;
        $index = 0;
        $length = self::length($text);

        while ($index < $length) {
            if (self::isEscapedAt($text, $index)) {
                $index++;
                continue;
            }

            if (self::isBlockquoteStart($text, $index)) {
                $lineEndPosition = self::position($text, "\n", $index);
                $lineEndPosition = $lineEndPosition === null ? $length : $lineEndPosition;

                $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                $result .= self::processBlockquoteLine(self::slice($text, $index, $lineEndPosition - $index));

                if ($lineEndPosition < $length) {
                    $result .= "\n";
                    $index = $lineEndPosition + 1;
                } else {
                    $index = $lineEndPosition;
                }

                $plainTextStart = $index;
                continue;
            }

            if (self::charAt($text, $index) === '`') {
                $inlineCode = self::parseInlineCode($text, $index);

                if ($inlineCode !== null) {
                    $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                    $result .= $inlineCode['text'];
                    $index = $inlineCode['end'];
                    $plainTextStart = $index;
                    continue;
                }
            }

            if (self::charAt($text, $index) === '[') {
                $link = self::parseLink($text, $index);

                if ($link !== null) {
                    $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                    $result .= $link['text'];
                    $index = $link['end'];
                    $plainTextStart = $index;
                    continue;
                }
            }

            $formattingEntity = self::parseFormattingEntity($text, $index);

            if ($formattingEntity !== null) {
                $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                $result .= $formattingEntity['text'];
                $index = $formattingEntity['end'];
                $plainTextStart = $index;
                continue;
            }

            $index++;
        }

        $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart));

        return $result;
    }

    /** @return array{text: string, end: int}|null */
    private static function parseInlineCode(string $text, int $openingPosition): ?array
    {
        $markerLength = self::countRepeatedCharacter($text, $openingPosition, '`');

        if ($markerLength === 1 && !self::canOpenInlineCode($text, $openingPosition)) {
            return null;
        }

        $marker = str_repeat('`', $markerLength);
        $closingPosition = self::findClosingInlineCodeMarker($text, $marker, $openingPosition + $markerLength);

        if ($closingPosition === null) {
            return null;
        }

        $content = self::slice($text, $openingPosition + $markerLength, $closingPosition - $openingPosition - $markerLength);

        if (!self::hasNonWhitespace($content)) {
            return null;
        }

        if ($markerLength > 1) {
            $content = self::normalizeCommonMarkCodeSpanContent($content);
        }

        return [
            'text' => '`' . self::escapeCodeContent($content) . '`',
            'end' => $closingPosition + $markerLength,
        ];
    }

    private static function findClosingInlineCodeMarker(string $text, string $marker, int $offset): ?int
    {
        $lineEndPosition = self::position($text, "\n", $offset);
        $searchLimit = $lineEndPosition ?? self::length($text);
        $searchOffset = $offset;
        $markerLength = self::length($marker);

        while (true) {
            $closingPosition = self::findUnescaped($text, $marker, $searchOffset);

            if ($closingPosition === null || $closingPosition >= $searchLimit) {
                return null;
            }

            if ($markerLength > 1 || self::canCloseInlineCode($text, $closingPosition)) {
                return $closingPosition;
            }

            $searchOffset = $closingPosition + 1;
        }
    }

    private static function canOpenInlineCode(string $text, int $openingPosition): bool
    {
        $nextCharacter = self::charAt($text, $openingPosition + 1);

        return $nextCharacter !== '' && !self::isWhitespace($nextCharacter);
    }

    private static function canCloseInlineCode(string $text, int $closingPosition): bool
    {
        $previousCharacter = self::charAt($text, $closingPosition - 1);

        return $previousCharacter !== '' && !self::isWhitespace($previousCharacter);
    }

    /** @return array{text: string, end: int}|null */
    private static function parseLink(string $text, int $openingBracketPosition): ?array
    {
        $closingBracketPosition = self::findClosingLinkBracket($text, $openingBracketPosition + 1);

        if ($closingBracketPosition === null || self::charAt($text, $closingBracketPosition + 1) !== '(') {
            return null;
        }

        $closingParenthesisPosition = self::findClosingLinkParenthesis($text, $closingBracketPosition + 2);

        if ($closingParenthesisPosition === null) {
            return null;
        }

        $linkText = self::slice($text, $openingBracketPosition + 1, $closingBracketPosition - $openingBracketPosition - 1);
        $linkUrl = self::slice($text, $closingBracketPosition + 2, $closingParenthesisPosition - $closingBracketPosition - 2);

        return [
            'text' => '[' . self::processTextPart($linkText) . '](' . self::escapeLinkUrl($linkUrl) . ')',
            'end' => $closingParenthesisPosition + 1,
        ];
    }

    private static function findClosingLinkBracket(string $text, int $offset): ?int
    {
        $length = self::length($text);
        $nestedBrackets = 0;

        for ($index = $offset; $index < $length; $index++) {
            if (self::isEscapedAt($text, $index)) {
                continue;
            }

            $character = self::charAt($text, $index);

            if ($character === '[') {
                $nestedBrackets++;
                continue;
            }

            if ($character !== ']') {
                continue;
            }

            if ($nestedBrackets === 0) {
                return $index;
            }

            $nestedBrackets--;
        }

        return null;
    }

    private static function findClosingLinkParenthesis(string $text, int $offset): ?int
    {
        $length = self::length($text);
        $nestedParentheses = 0;

        for ($index = $offset; $index < $length; $index++) {
            if (self::isEscapedAt($text, $index)) {
                continue;
            }

            $character = self::charAt($text, $index);

            if ($character === '(') {
                $nestedParentheses++;
                continue;
            }

            if ($character !== ')') {
                continue;
            }

            if ($nestedParentheses === 0) {
                return $index;
            }

            $nestedParentheses--;
        }

        return null;
    }

    /** @return array{text: string, end: int}|null */
    private static function parseFormattingEntity(string $text, int $openingPosition): ?array
    {
        foreach (self::FORMATTING_MARKERS as $sourceMarker => $telegramMarker) {
            if (!self::startsWithAt($text, $sourceMarker, $openingPosition)) {
                continue;
            }

            $sourceMarkerLength = self::length($sourceMarker);

            if (!self::canOpenFormattingEntity($text, $openingPosition, $sourceMarker)) {
                continue;
            }

            $closingPosition = self::findClosingFormattingMarker($text, $sourceMarker, $openingPosition + $sourceMarkerLength);

            if ($closingPosition === null) {
                continue;
            }

            $content = self::slice($text, $openingPosition + $sourceMarkerLength, $closingPosition - $openingPosition - $sourceMarkerLength);

            return [
                'text' => $telegramMarker . self::processTextPart($content) . $telegramMarker,
                'end' => $closingPosition + $sourceMarkerLength,
            ];
        }

        return null;
    }

    private static function findClosingFormattingMarker(string $text, string $sourceMarker, int $offset): ?int
    {
        $lineEndPosition = self::position($text, "\n", $offset);
        $searchLimit = $lineEndPosition ?? self::length($text);
        $searchOffset = $offset;

        while (true) {
            $closingPosition = self::findUnescaped($text, $sourceMarker, $searchOffset);

            if ($closingPosition === null || $closingPosition >= $searchLimit) {
                return null;
            }

            $content = self::slice($text, $offset, $closingPosition - $offset);

            if (self::hasNonWhitespace($content) && self::canCloseFormattingEntity($text, $closingPosition, $sourceMarker)) {
                return $closingPosition;
            }

            $searchOffset = $closingPosition + 1;
        }
    }

    private static function canOpenFormattingEntity(string $text, int $openingPosition, string $sourceMarker): bool
    {
        $sourceMarkerLength = self::length($sourceMarker);
        $previousCharacter = self::charAt($text, $openingPosition - 1);
        $nextCharacter = self::charAt($text, $openingPosition + $sourceMarkerLength);

        if ($nextCharacter === '' || self::isWhitespace($nextCharacter)) {
            return false;
        }

        if ($sourceMarkerLength === 1 && ($previousCharacter === $sourceMarker || $nextCharacter === $sourceMarker)) {
            return false;
        }

        if ($sourceMarker === '_' && self::isAsciiAlphanumeric($previousCharacter) && self::isAsciiAlphanumeric($nextCharacter)) {
            return false;
        }

        return true;
    }

    private static function canCloseFormattingEntity(string $text, int $closingPosition, string $sourceMarker): bool
    {
        $sourceMarkerLength = self::length($sourceMarker);
        $previousCharacter = self::charAt($text, $closingPosition - 1);

        if ($previousCharacter === '' || self::isWhitespace($previousCharacter)) {
            return false;
        }

        if ($sourceMarkerLength === 1 && $previousCharacter === $sourceMarker) {
            return false;
        }

        return true;
    }

    private static function processBlockquoteLine(string $line): string
    {
        $length = self::length($line);
        $markerLength = 0;

        while ($markerLength < $length && self::charAt($line, $markerLength) === '>') {
            $markerLength++;
        }

        return self::slice($line, 0, $markerLength) . self::processTextPart(self::slice($line, $markerLength));
    }

    private static function escapeMarkdownText(string $text): string
    {
        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);

            if ($character === '\\') {
                $nextCharacter = self::charAt($text, $index + 1);

                if ($nextCharacter !== '' && self::isAlreadyEscapedCharacter($nextCharacter)) {
                    $result .= '\\' . $nextCharacter;
                    $index++;
                    continue;
                }

                $result .= '\\\\';
                continue;
            }

            if (self::isReservedCharacter($character)) {
                $result .= '\\' . $character;
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private static function escapeCodeContent(string $text): string
    {
        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);

            if ($character === '\\') {
                $nextCharacter = self::charAt($text, $index + 1);

                if ($nextCharacter === '\\' || $nextCharacter === '`') {
                    $result .= '\\' . $nextCharacter;
                    $index++;
                    continue;
                }

                $result .= '\\\\';
                continue;
            }

            if ($character === '`') {
                $result .= '\\`';
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private static function escapeLinkUrl(string $url): string
    {
        $result = '';
        $length = self::length($url);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($url, $index);

            if ($character === '\\') {
                $nextCharacter = self::charAt($url, $index + 1);

                if ($nextCharacter !== '' && self::isAlreadyEscapedCharacter($nextCharacter)) {
                    $result .= '\\' . $nextCharacter;
                    $index++;
                    continue;
                }

                $result .= '\\\\';
                continue;
            }

            if ($character === ')') {
                $result .= '\\)';
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private static function findUnescaped(string $text, string $needle, int $offset): ?int
    {
        $position = self::position($text, $needle, $offset);

        while ($position !== null) {
            if (!self::isEscapedAt($text, $position)) {
                return $position;
            }

            $position = self::position($text, $needle, $position + 1);
        }

        return null;
    }

    private static function startsWithAt(string $text, string $needle, int $position): bool
    {
        return self::slice($text, $position, self::length($needle)) === $needle;
    }

    private static function isBlockquoteStart(string $text, int $position): bool
    {
        return self::charAt($text, $position) === '>' && ($position === 0 || self::charAt($text, $position - 1) === "\n");
    }

    private static function isEscapedAt(string $text, int $position): bool
    {
        $backslashCount = 0;

        for ($index = $position - 1; $index >= 0 && self::charAt($text, $index) === '\\'; $index--) {
            $backslashCount++;
        }

        return $backslashCount % 2 === 1;
    }

    private static function isReservedCharacter(string $character): bool
    {
        return self::position(self::RESERVED_CHARACTERS, $character, 0) !== null;
    }

    private static function isAlreadyEscapedCharacter(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        $unicodeCodePoint = mb_ord($character, self::ENCODING);

        if ($unicodeCodePoint === false || $unicodeCodePoint < 33 || $unicodeCodePoint > 126) {
            return false;
        }

        $isDigit = $unicodeCodePoint >= 48 && $unicodeCodePoint <= 57;
        $isUppercaseLetter = $unicodeCodePoint >= 65 && $unicodeCodePoint <= 90;
        $isLowercaseLetter = $unicodeCodePoint >= 97 && $unicodeCodePoint <= 122;

        return !$isDigit && !$isUppercaseLetter && !$isLowercaseLetter;
    }

    private static function isAsciiAlphanumeric(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        $unicodeCodePoint = mb_ord($character, self::ENCODING);

        if ($unicodeCodePoint === false) {
            return false;
        }

        return ($unicodeCodePoint >= 48 && $unicodeCodePoint <= 57)
            || ($unicodeCodePoint >= 65 && $unicodeCodePoint <= 90)
            || ($unicodeCodePoint >= 97 && $unicodeCodePoint <= 122);
    }

    private static function isWhitespace(string $character): bool
    {
        return $character !== '' && preg_match('/^\s$/u', $character) === 1;
    }

    private static function hasNonWhitespace(string $text): bool
    {
        return preg_match('/\S/u', $text) === 1;
    }

    private static function normalizeCommonMarkCodeSpanContent(string $content): string
    {
        if (!self::hasNonWhitespace($content)) {
            return $content;
        }

        if (self::charAt($content, 0) !== ' ' || self::charAt($content, self::length($content) - 1) !== ' ') {
            return $content;
        }

        return self::slice($content, 1, self::length($content) - 2);
    }

    private static function countRepeatedCharacter(string $text, int $position, string $character): int
    {
        $count = 0;
        $length = self::length($text);

        for ($index = $position; $index < $length && self::charAt($text, $index) === $character; $index++) {
            $count++;
        }

        return $count;
    }

    private static function length(string $text): int
    {
        return mb_strlen($text, self::ENCODING);
    }

    private static function slice(string $text, int $start, ?int $length = null): string
    {
        return mb_substr($text, $start, $length, self::ENCODING);
    }

    private static function position(string $text, string $needle, int $offset): ?int
    {
        $position = mb_strpos($text, $needle, $offset, self::ENCODING);

        return $position === false ? null : $position;
    }

    private static function charAt(string $text, int $position): string
    {
        if ($position < 0 || $position >= self::length($text)) {
            return '';
        }

        return self::slice($text, $position, 1);
    }
}
