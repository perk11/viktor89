<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Util\Utf8Sanitizer;

#[CoversClass(Utf8Sanitizer::class)]
class Utf8SanitizerTest extends TestCase
{
    public function testValidUtf8IsUnchanged(): void
    {
        $this->assertSame('привет 👍 héllo', Utf8Sanitizer::sanitize('привет 👍 héllo'));
        $this->assertSame('', Utf8Sanitizer::sanitize(''));
    }

    public function testInvalidUtf8IsSanitized(): void
    {
        // 👍 followed by an invalid 2-byte sequence, valid text, then a truncated 3-byte sequence
        $invalid = "ok\xF0\x9F\x91\x8D\xC3\x28mid\xE2\x82";

        $sanitized = Utf8Sanitizer::sanitize($invalid);

        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
        $this->assertStringContainsString('ok', $sanitized);
        $this->assertStringContainsString('👍', $sanitized);
        $this->assertStringContainsString('mid', $sanitized);
    }

    public function testSanitizedResultAlwaysEncodesToJson(): void
    {
        $sanitized = Utf8Sanitizer::sanitize("bad\xC3\x28\xFF");

        $this->assertNotFalse(json_encode($sanitized));
    }

    public function testNullablePassthrough(): void
    {
        $this->assertNull(Utf8Sanitizer::sanitizeNullable(null));
        $this->assertSame('ok', Utf8Sanitizer::sanitizeNullable('ok'));
        $this->assertTrue(mb_check_encoding(Utf8Sanitizer::sanitizeNullable("bad\xFF"), 'UTF-8'));
    }
}
