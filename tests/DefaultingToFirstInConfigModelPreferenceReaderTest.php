<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\DefaultingToFirstInConfigModelPreferenceReader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultingToFirstInConfigModelPreferenceReader::class)]
class DefaultingToFirstInConfigModelPreferenceReaderTest extends TestCase
{
    private function createMockPreference(?string $value): UserPreferenceReaderInterface
    {
        $mock = $this->createMock(UserPreferenceReaderInterface::class);
        $mock->method('getCurrentPreferenceValue')->willReturn($value);
        return $mock;
    }

    public function testReturnsOriginalPreferenceWhenValid(): void
    {
        $original = $this->createMockPreference('model_a');
        $reader = new DefaultingToFirstInConfigModelPreferenceReader(
            $original,
            ['model_a' => [], 'model_b' => []]
        );

        $this->assertSame('model_a', $reader->getCurrentPreferenceValue(123));
    }

    public function testReturnsFirstModelWhenOriginalIsNull(): void
    {
        $original = $this->createMockPreference(null);
        $reader = new DefaultingToFirstInConfigModelPreferenceReader(
            $original,
            ['model_a' => [], 'model_b' => []]
        );

        $this->assertSame('model_a', $reader->getCurrentPreferenceValue(123));
    }

    public function testReturnsFirstModelWhenOriginalNotInConfig(): void
    {
        $original = $this->createMockPreference('unknown_model');
        $reader = new DefaultingToFirstInConfigModelPreferenceReader(
            $original,
            ['model_a' => [], 'model_b' => []]
        );

        $this->assertSame('model_a', $reader->getCurrentPreferenceValue(123));
    }

    public function testReturnsSecondModelWhenConfigStartsWithIt(): void
    {
        $original = $this->createMockPreference(null);
        $reader = new DefaultingToFirstInConfigModelPreferenceReader(
            $original,
            ['model_x' => [], 'model_y' => []]
        );

        $this->assertSame('model_x', $reader->getCurrentPreferenceValue(1));
    }

    public function testReturnsOriginalForDifferentUserIds(): void
    {
        $original = $this->createMockPreference('model_b');
        $reader = new DefaultingToFirstInConfigModelPreferenceReader(
            $original,
            ['model_a' => [], 'model_b' => []]
        );

        $this->assertSame('model_b', $reader->getCurrentPreferenceValue(100));
        $this->assertSame('model_b', $reader->getCurrentPreferenceValue(200));
    }

    public function testWithSingleModelConfig(): void
    {
        $original = $this->createMockPreference('nonexistent');
        $reader = new DefaultingToFirstInConfigModelPreferenceReader(
            $original,
            ['only_model' => ['url' => 'http://localhost']]
        );

        $this->assertSame('only_model', $reader->getCurrentPreferenceValue(1));
    }
}
