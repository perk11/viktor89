<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\FixedValuePreferenceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixedValuePreferenceProvider::class)]
class FixedValuePreferenceProviderTest extends TestCase
{
    public function testGetCurrentPreferenceValueReturnsFixedValue(): void
    {
        $provider = new FixedValuePreferenceProvider('preferred_model');

        $this->assertSame('preferred_model', $provider->getCurrentPreferenceValue(123));
        $this->assertSame('preferred_model', $provider->getCurrentPreferenceValue(456));
    }

    public function testGetCurrentPreferenceValueIgnoresUserId(): void
    {
        $provider = new FixedValuePreferenceProvider('llama3');

        // All users get the same value
        $this->assertSame('llama3', $provider->getCurrentPreferenceValue(1));
        $this->assertSame('llama3', $provider->getCurrentPreferenceValue(999));
    }

    public function testConstructorWithNullValue(): void
    {
        $provider = new FixedValuePreferenceProvider(null);

        $this->assertNull($provider->getCurrentPreferenceValue(123));
    }

    public function testConstructorWithEmptyString(): void
    {
        $provider = new FixedValuePreferenceProvider('');

        $this->assertSame('', $provider->getCurrentPreferenceValue(123));
    }

    public function testValueIsPublicProperty(): void
    {
        $provider = new FixedValuePreferenceProvider('initial');
        $provider->value = 'changed';

        $this->assertSame('changed', $provider->getCurrentPreferenceValue(1));
    }
}
