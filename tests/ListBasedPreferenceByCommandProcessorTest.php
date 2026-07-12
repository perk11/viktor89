<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\UserSettings\ListBasedPreferenceByCommandProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListBasedPreferenceByCommandProcessor::class)]
class ListBasedPreferenceByCommandProcessorTest extends TestCase
{
    private function createProcessor(array $acceptedValues): ListBasedPreferenceByCommandProcessor
    {
        $repository = $this->createMock(\Perk11\Viktor89\Repository\UserPreferenceRepository::class);
        return new ListBasedPreferenceByCommandProcessor(
            $repository,
            ['/test'],
            'test_preference',
            'testbot',
            $acceptedValues
        );
    }

    public function testAcceptsValidValue(): void
    {
        $processor = $this->createProcessor(['option_a', 'option_b', 'option_c']);

        $errors = $this->getValidationErrors($processor, 'option_a');

        $this->assertSame([], $errors);
    }

    public function testAcceptsNullValue(): void
    {
        $processor = $this->createProcessor(['a', 'b']);

        $errors = $this->getValidationErrors($processor, null);

        $this->assertSame([], $errors);
    }

    public function testRejectsInvalidValue(): void
    {
        $processor = $this->createProcessor(['model_x', 'model_y']);

        $errors = $this->getValidationErrors($processor, 'unknown_model');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('model_x', $errors[0]);
        $this->assertStringContainsString('model_y', $errors[0]);
    }

    public function testRejectsEmptyString(): void
    {
        $processor = $this->createProcessor(['value1', 'value2']);

        $errors = $this->getValidationErrors($processor, '');

        $this->assertCount(1, $errors);
    }

    public function testCaseSensitiveMatching(): void
    {
        $processor = $this->createProcessor(['Model_A', 'Model_B']);

        $errors = $this->getValidationErrors($processor, 'model_a');

        $this->assertCount(1, $errors);
    }

    public function testAcceptsValueWithSpaces(): void
    {
        $processor = $this->createProcessor(['option with spaces', 'another option']);

        $errors = $this->getValidationErrors($processor, 'option with spaces');

        $this->assertSame([], $errors);
    }

    private function getValidationErrors(ListBasedPreferenceByCommandProcessor $processor, ?string $value): array
    {
        $reflection = new \ReflectionMethod($processor, 'getValueValidationErrors');
        return $reflection->invoke($processor, $value, -1);
    }
}
