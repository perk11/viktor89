<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\UnknownAssistantException;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\MiniMaxH3\MiniMaxH3VideoPromptPreprocessor;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(VideoPromptPreprocessorFactory::class)]
class VideoPromptPreprocessorFactoryTest extends TestCase
{
    public function testReturnsNullForNullOrEmptyKey(): void
    {
        $factory = new VideoPromptPreprocessorFactory($this->assistantFactoryStub(), $this->altTextProviderStub(), new NullLogger());
        $this->assertNull($factory->createByConfigKey(null));
        $this->assertNull($factory->createByConfigKey(''));
    }

    public function testReturnsNullForUnknownKey(): void
    {
        $factory = new VideoPromptPreprocessorFactory($this->assistantFactoryStub(), $this->altTextProviderStub(), new NullLogger());
        $this->assertNull($factory->createByConfigKey('some-future-model'));
    }

    public function testCreatesMiniMaxH3Preprocessor(): void
    {
        $assistantFactory = $this->assistantFactoryReturning('minimax-h3-video-prompt', $this->createStub(AssistantInterface::class));

        $factory = new VideoPromptPreprocessorFactory($assistantFactory, $this->altTextProviderStub(), new NullLogger());

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createByConfigKey(VideoPromptPreprocessorFactory::MINIMAX_H3),
        );
    }

    public function testFallsBackToAltTextAssistantWhenDedicatedOneIsMissing(): void
    {
        $fallback = $this->createStub(AssistantInterface::class);
        $assistantFactory = $this->createStub(AssistantFactory::class);
        $assistantFactory->method('getAssistantInstanceByName')->willReturnCallback(
            static function (string $name) use ($fallback): AssistantInterface {
                if ($name === 'vision-for-alt-text') {
                    return $fallback;
                }
                throw new UnknownAssistantException($name);
            },
        );

        $factory = new VideoPromptPreprocessorFactory($assistantFactory, $this->altTextProviderStub(), new NullLogger());

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createByConfigKey(VideoPromptPreprocessorFactory::MINIMAX_H3),
        );
    }

    public function testCreateForModelPreferenceReturnsPreprocessorForSelectedModel(): void
    {
        $factory = new VideoPromptPreprocessorFactory($this->assistantFactoryStub(), $this->altTextProviderStub(), new NullLogger());
        $preference = $this->preferenceReturning('minimax-h3-preprocessed');
        $config = [
            'plain' => ['url' => 'http://x'],
            'minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3'],
        ];

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createForModelPreference($preference, $config, 7),
        );
    }

    public function testCreateForModelPreferenceReturnsNullWhenSelectedModelHasNoPreprocessor(): void
    {
        $factory = new VideoPromptPreprocessorFactory($this->assistantFactoryStub(), $this->altTextProviderStub(), new NullLogger());
        $preference = $this->preferenceReturning('plain');
        $config = ['plain' => ['url' => 'http://x']];

        $this->assertNull($factory->createForModelPreference($preference, $config, 7));
    }

    public function testCreateForModelPreferenceDefaultsToFirstConfigEntryWhenNoPreference(): void
    {
        $factory = new VideoPromptPreprocessorFactory($this->assistantFactoryStub(), $this->altTextProviderStub(), new NullLogger());
        $preference = $this->preferenceReturning(null);
        $config = [
            'minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3'],
            'plain' => ['url' => 'http://x'],
        ];

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createForModelPreference($preference, $config, 7),
        );
    }

    public function testCreateForModelPreferenceDefaultsToFirstConfigEntryForUnknownPreference(): void
    {
        $factory = new VideoPromptPreprocessorFactory($this->assistantFactoryStub(), $this->altTextProviderStub(), new NullLogger());
        $preference = $this->preferenceReturning('does-not-exist');
        $config = [
            'minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3'],
        ];

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createForModelPreference($preference, $config, 7),
        );
    }

    private function assistantFactoryStub(): AssistantFactory
    {
        return $this->createStub(AssistantFactory::class);
    }

    private function altTextProviderStub(): AltTextProvider
    {
        return $this->createStub(AltTextProvider::class);
    }

    private function assistantFactoryReturning(string $expectedName, AssistantInterface $assistant): AssistantFactory
    {
        $assistantFactory = $this->createStub(AssistantFactory::class);
        $assistantFactory->method('getAssistantInstanceByName')->willReturnCallback(
            static function (string $name) use ($expectedName, $assistant): AssistantInterface {
                if ($name === $expectedName) {
                    return $assistant;
                }
                throw new UnknownAssistantException($name);
            },
        );

        return $assistantFactory;
    }

    private function preferenceReturning(?string $value): UserPreferenceReaderInterface
    {
        $preference = $this->createMock(UserPreferenceReaderInterface::class);
        $preference->method('getCurrentPreferenceValue')->willReturn($value);

        return $preference;
    }
}
