<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\PrependingSystemPromptProcessor;
use Perk11\Viktor89\FixedValuePreferenceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrependingSystemPromptProcessor::class)]
class PrependingSystemPromptProcessorTest extends TestCase
{
    public function testReturnsPrependWhenUserHasNoPreference(): void
    {
        $userPreference = new FixedValuePreferenceProvider(null);
        $processor = new PrependingSystemPromptProcessor($userPreference, 'System prompt here');

        $this->assertSame('System prompt here', $processor->getCurrentPreferenceValue(123));
    }

    public function testReturnsPrependPlusUserPrompt(): void
    {
        $userPreference = new FixedValuePreferenceProvider('User custom prompt');
        $processor = new PrependingSystemPromptProcessor($userPreference, 'System prompt here');

        $this->assertSame("System prompt here\nUser custom prompt", $processor->getCurrentPreferenceValue(123));
    }

    public function testUsesNewlineSeparator(): void
    {
        $userPreference = new FixedValuePreferenceProvider('custom');
        $processor = new PrependingSystemPromptProcessor($userPreference, 'system');

        $result = $processor->getCurrentPreferenceValue(1);
        $this->assertStringContainsString("\n", $result);
    }

    public function testDifferentUsersGetSameResult(): void
    {
        $userPreference = new FixedValuePreferenceProvider('user');
        $processor = new PrependingSystemPromptProcessor($userPreference, 'sys');

        $this->assertSame(
            $processor->getCurrentPreferenceValue(100),
            $processor->getCurrentPreferenceValue(200)
        );
    }

    public function testEmptyPrependString(): void
    {
        $userPreference = new FixedValuePreferenceProvider('user');
        $processor = new PrependingSystemPromptProcessor($userPreference, '');

        $this->assertSame("\nuser", $processor->getCurrentPreferenceValue(1));
    }

    public function testEmptyUserPrompt(): void
    {
        $userPreference = new FixedValuePreferenceProvider('');
        $processor = new PrependingSystemPromptProcessor($userPreference, 'sys');

        $this->assertSame("sys\n", $processor->getCurrentPreferenceValue(1));
    }
}
