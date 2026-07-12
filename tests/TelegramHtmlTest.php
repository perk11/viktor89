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
        // '&' is replaced first, so the '&' introduced by escaping '<', '>' or '"'
        // is not itself re-escaped (which previously produced '&amp;lt;' etc.).
        return [
            'less than'        => ['<', '&lt;', 'less than sign'],
            'greater than'     => ['>', '&gt;', 'greater than sign'],
            'ampersand'        => ['&', '&amp;', 'ampersand'],
            'double quote'     => ['"', '&quot;', 'double quote'],
            'plain text'       => ['Hello World', 'Hello World', 'no special chars'],
            'html tag'         => ['<div>text</div>', '&lt;div&gt;text&lt;/div&gt;', 'html tag'],
            'url with amp'     => ['a&b=c', 'a&amp;b=c', 'URL parameter'],
            'all combined'     => ['<>&"', '&lt;&gt;&amp;&quot;', 'all special chars'],
            'unicode'          => ['Привет 🌍', 'Привет 🌍', 'unicode text'],
            'empty string'     => ['', '', 'empty string'],
            'whitespace'       => ['  \t\n  ', '  \t\n  ', 'whitespace only'],
        ];
    }

    public function testEscapeEscapesEmbeddedAmpersand(): void
    {
        // A literal '&' — even inside something that looks like an entity — must
        // be escaped; there is no way to know the caller "already escaped" it.
        $result = TelegramHtml::escape('&lt;script&gt;');
        $this->assertSame('&amp;lt;script&amp;gt;', $result);
    }
}
