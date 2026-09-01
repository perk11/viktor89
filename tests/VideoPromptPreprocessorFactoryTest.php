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
        $factory = $this->factory();
        $this->assertNull($factory->createByConfigKey(null));
        $this->assertNull($factory->createByConfigKey(''));
    }

    public function testReturnsNullForUnknownKey(): void
    {
        $factory = $this->factory();
        $this->assertNull($factory->createByConfigKey('some-future-preprocessor'));
    }

    public function testCreatesMiniMaxH3Preprocessor(): void
    {
        $assistantFactory = $this->assistantFactoryReturning('minimax-h3-video-prompt', $this->createStub(AssistantInterface::class));

        $factory = $this->factory($assistantFactory);

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

        $factory = $this->factory($assistantFactory);

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createByConfigKey(VideoPromptPreprocessorFactory::MINIMAX_H3),
        );
    }

    public function testCreatesPreprocessorFromConfiguredAssistant(): void
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistantFactory = $this->createStub(AssistantFactory::class);
        $assistantFactory->method('getAssistantInstanceByName')->willReturnCallback(
            static function (string $name) use ($assistant): AssistantInterface {
                if ($name === 'glm-5.3-flash') {
                    return $assistant;
                }
                throw new UnknownAssistantException($name);
            },
        );

        $factory = $this->factory($assistantFactory, [
            'minimax-h3-flash' => ['assistant' => 'glm-5.3-flash'],
        ]);

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createByConfigKey('minimax-h3-flash'),
        );
    }

    public function testConfiguredAssistantOverridesBuiltInKey(): void
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistantFactory = $this->assistantFactoryReturning('glm-5.3-flash', $assistant);

        $factory = $this->factory($assistantFactory, [
            VideoPromptPreprocessorFactory::MINIMAX_H3 => ['assistant' => 'glm-5.3-flash'],
        ]);

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createByConfigKey(VideoPromptPreprocessorFactory::MINIMAX_H3),
        );
    }

    public function testReturnsNullWhenConfiguredAssistantIsUnknown(): void
    {
        $assistantFactory = $this->createStub(AssistantFactory::class);
        $assistantFactory->method('getAssistantInstanceByName')
            ->willThrowException(new UnknownAssistantException('nope'));

        $factory = $this->factory($assistantFactory, [
            'minimax-h3-broken' => ['assistant' => 'nope'],
        ]);

        $this->assertNull($factory->createByConfigKey('minimax-h3-broken'));
    }

    public function testReturnsNullWhenConfigEntryHasNoAssistant(): void
    {
        $factory = $this->factory(null, ['minimax-h3-incomplete' => []]);

        $this->assertNull($factory->createByConfigKey('minimax-h3-incomplete'));
    }

    public function testCreateForModelPreferenceReturnsPreprocessorForSelectedModel(): void
    {
        $factory = $this->factory();
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

    public function testCreateForModelPreferenceReturnsPreprocessorForConfiguredAssistantVariant(): void
    {
        $assistantFactory = $this->assistantFactoryReturning('glm-5.3-flash', $this->createStub(AssistantInterface::class));
        $factory = $this->factory($assistantFactory, [
            'minimax-h3-flash' => ['assistant' => 'glm-5.3-flash'],
        ]);
        $preference = $this->preferenceReturning('minimax-h3-preprocessed');
        $config = [
            'minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3-flash'],
        ];

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createForModelPreference($preference, $config, 7),
        );
    }

    public function testCreateForModelPreferenceReturnsNullWhenSelectedModelHasNoPreprocessor(): void
    {
        $factory = $this->factory();
        $preference = $this->preferenceReturning('plain');
        $config = [
            'preprocessed' => ['preprocessor' => 'minimax-h3'],
            'plain' => ['url' => 'http://x'],
        ];

        $this->assertNull($factory->createForModelPreference($preference, $config, 7));
    }

    public function testCreateForModelPreferenceDefaultsToFirstConfigEntryWhenNoPreference(): void
    {
        $factory = $this->factory();
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
        $factory = $this->factory();
        $preference = $this->preferenceReturning('does-not-exist');
        $config = [
            'minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3'],
        ];

        $this->assertInstanceOf(
            MiniMaxH3VideoPromptPreprocessor::class,
            $factory->createForModelPreference($preference, $config, 7),
        );
    }

    private function factory(?AssistantFactory $assistantFactory = null, array $preprocessorsConfig = []): VideoPromptPreprocessorFactory
    {
        return new VideoPromptPreprocessorFactory(
            $assistantFactory ?? $this->assistantFactoryStub(),
            $this->altTextProviderStub(),
            $preprocessorsConfig,
            new NullLogger(),
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
