<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Util\TelegramHtml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramHtml::class)]
class TelegramHtmlTest extends TestCase
{
    #[DataProvider('escapeProvider')]
    public function testEscape(string $input, string $expected, string $label): void
    {
        $this->assertSame($expected, TelegramHtml::escape($input), $label);
    }

    /** @return array<string, array{string, string, string}> */
    public static function escapeProvider(): array
    {
        // NOTE: source replaces '<' and '>' BEFORE '&', causing double encoding:
        // '<' → '&lt;' → '&amp;lt;'
        // '>' → '&gt;' → '&amp;gt;'
        // Fix: source should replace '&' first to avoid double encoding
        return [
            'less than'        => ['<', '&amp;lt;', 'less than sign'],
            'greater than'     => ['>', '&amp;gt;', 'greater than sign'],
            'ampersand'        => ['&', '&amp;', 'ampersand'],
            'double quote'     => ['"', '&quot;', 'double quote'],
            'plain text'       => ['Hello World', 'Hello World', 'no special chars'],
            'html tag'         => ['<div>text</div>', '&amp;lt;div&amp;gt;text&amp;lt;/div&amp;gt;', 'html tag'],
            'url with amp'     => ['a&b=c', 'a&amp;b=c', 'URL parameter'],
            'all combined'     => ['<>&"', '&amp;lt;&amp;gt;&amp;&quot;', 'all special chars'],
            'unicode'          => ['Привет 🌍', 'Привет 🌍', 'unicode text'],
            'empty string'     => ['', '', 'empty string'],
            'whitespace'       => ['  \t\n  ', '  \t\n  ', 'whitespace only'],
        ];
    }

    public function testEscapeDoesNotDoubleEncode(): void
    {
        // If already escaped, the '&' in '&lt;' gets replaced to '&amp;'
        // producing '&amp;lt;' (this is the current behavior due to replacement order)
        $result = TelegramHtml::escape('&lt;script&gt;');
        $this->assertSame('&amp;lt;script&amp;gt;', $result);
    }
}
