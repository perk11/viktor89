<?php

namespace Perk11\Viktor89\Util;

class TelegramHtml
{
    public static function escape(string $text): string
    {
        return str_replace(
            ['&', '<', '>', '"'],
            ['&amp;', '&lt;', '&gt;', '&quot;'],
            $text,
        );
    }
}
