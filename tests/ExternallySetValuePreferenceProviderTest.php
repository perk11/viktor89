<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ExternallySetValuePreferenceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternallySetValuePreferenceProvider::class)]
class ExternallySetValuePreferenceProviderTest extends TestCase
{
    public function testGetCurrentPreferenceValueReturnsCurrentValue(): void
    {
        $provider = new ExternallySetValuePreferenceProvider();
        $provider->value = 'set_value';

        $this->assertSame('set_value', $provider->getCurrentPreferenceValue(123));
    }

    public function testValueCanBeChangedDynamically(): void
    {
        $provider = new ExternallySetValuePreferenceProvider();

        $provider->value = 'first';
        $this->assertSame('first', $provider->getCurrentPreferenceValue(1));

        $provider->value = 'second';
        $this->assertSame('second', $provider->getCurrentPreferenceValue(1));
    }

    public function testGetCurrentPreferenceValueWithNullValue(): void
    {
        $provider = new ExternallySetValuePreferenceProvider();
        $provider->value = null;
        $this->assertNull($provider->getCurrentPreferenceValue(123));
    }

    public function testGetCurrentPreferenceValueWithEmptyString(): void
    {
        $provider = new ExternallySetValuePreferenceProvider();
        $provider->value = '';

        $this->assertSame('', $provider->getCurrentPreferenceValue(789));
    }

    public function testValueIgnoredForUserId(): void
    {
        $provider = new ExternallySetValuePreferenceProvider();
        $provider->value = 'shared_value';

        $this->assertSame('shared_value', $provider->getCurrentPreferenceValue(1));
        $this->assertSame('shared_value', $provider->getCurrentPreferenceValue(999));
    }
}
