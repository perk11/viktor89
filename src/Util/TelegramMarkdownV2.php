<?php

namespace Perk11\Viktor89\Util;

class TelegramMarkdownV2
{
    public static function escape(string $text): string
    {
        return str_replace(
            [
                '_',
                '*',
                '[',
                ']',
                '(',
                ')',
                '~',
                '`',
                '>',
                '#',
                '+',
                '-',
                '=',
                '|',
                '{',
                '}',
                '.',
                '!',
            ],
            [
                '\_',
                '\*',
                '\[',
                '\]',
                '\(',
                '\)',
                '\~',
                '\`',
                '\>',
                '\#',
                '\+',
                '\-',
                '\=',
                '\|',
                '\{',
                '\}',
                '\.',
                '\!',
            ],
            $text,
        );
    }
}
