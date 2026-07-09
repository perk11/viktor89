<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\UserSettings\NumericPreferenceInRangeByCommandProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NumericPreferenceInRangeByCommandProcessor::class)]
class NumericPreferenceInRangeByCommandProcessorTest extends TestCase
{
    private function createProcessor(float $min, float $max): NumericPreferenceInRangeByCommandProcessor
    {
        $repository = $this->createMock(\Perk11\Viktor89\Repository\UserPreferenceRepository::class);
        return new NumericPreferenceInRangeByCommandProcessor(
            $repository,
            ['/test'],
            'test_preference',
            'testbot',
            $min,
            $max
        );
    }

    public function testAcceptsValueInRange(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, '50');

        $this->assertSame([], $errors);
    }

    public function testAcceptsMinValue(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, '0');

        $this->assertSame([], $errors);
    }

    public function testAcceptsMaxValue(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, '100');

        $this->assertSame([], $errors);
    }

    public function testRejectsNonNumericValue(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, 'abc');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('не число', $errors[0]);
    }

    public function testRejectsValueBelowMinimum(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, '-1');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('слишком маленькое', $errors[0]);
    }

    public function testRejectsValueAboveMaximum(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, '101');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('слишком большое', $errors[0]);
    }

    public function testAcceptsNullValue(): void
    {
        $processor = $this->createProcessor(0, 100);

        $errors = $this->getValidationErrors($processor, null);

        $this->assertSame([], $errors);
    }

    public function testAcceptsNegativeRange(): void
    {
        $processor = $this->createProcessor(-10, 10);

        $errors = $this->getValidationErrors($processor, '-5');

        $this->assertSame([], $errors);
    }

    private function getValidationErrors(NumericPreferenceInRangeByCommandProcessor $processor, ?string $value): array
    {
        $reflection = new \ReflectionMethod($processor, 'getValueValidationErrors');
        return $reflection->invoke($processor, $value);
    }
}
