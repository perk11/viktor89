<?php

namespace Perk11\Viktor89\Util;

class Utf8Sanitizer
{
    /**
     * Return $string with invalid UTF-8 byte sequences removed or substituted,
     * so the result always encodes cleanly (json_encode, Telegram API, logs).
     */
    public static function sanitize(string $string): string
    {
        if (mb_check_encoding($string, 'UTF-8')) {
            return $string;
        }

        $sanitized = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        if (mb_check_encoding($sanitized, 'UTF-8')) {
            return $sanitized;
        }

        // mb_convert_encoding can still leave invalid bytes for some inputs
        return htmlspecialchars_decode(
            htmlspecialchars($string, ENT_SUBSTITUTE, 'UTF-8'),
            ENT_QUOTES | ENT_HTML5,
        );
    }

    public static function sanitizeNullable(?string $string): ?string
    {
        return $string === null ? null : self::sanitize($string);
    }
}
