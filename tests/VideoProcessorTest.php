<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\VideoGeneration\Txt2VideoClient;
use Perk11\Viktor89\VideoGeneration\VideoImg2VidProcessor;
use Perk11\Viktor89\VideoGeneration\VideoProcessor;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessor;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessorFactory;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(VideoProcessor::class)]
class VideoProcessorTest extends TestCase
{
    public function testImg2VidRunsPreprocessorWhenSelectedModelDeclaresIt(): void
    {
        $preprocessor = $this->preprocessorThatPrefixes('[MINIMAX] ');
        $factory = $this->factoryReturning('minimax-h3', $preprocessor);

        $img2VideoPref = $this->preferenceReturning('minimax-h3-preprocessed');
        $downloader = $this->downloaderReturning('photo-bytes');

        $capturedPrompt = '';
        $videoImg2VidProcessor = $this->createMock(VideoImg2VidProcessor::class);
        $videoImg2VidProcessor->method('respondWithImg2VidResultBasedOnPhotoInMessage')
            ->willReturnCallback(function ($_, $_cmd, string $prompt, $_cb) use (&$capturedPrompt): void {
                $capturedPrompt = $prompt;
            });

        $processor = $this->buildProcessor(
            img2VideoModelPreference: $img2VideoPref,
            img2videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            videoImg2VidProcessor: $videoImg2VidProcessor,
            telegramFileDownloader: $downloader,
            preprocessorFactory: $factory,
        );

        $result = $processor->processMessageChain($this->chainWithReplyPhoto('a dog on a beach'), $this->progressCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertSame('[MINIMAX] a dog on a beach', $capturedPrompt);
    }

    public function testImg2VidUsesRawPromptWhenSelectedModelHasNoPreprocessor(): void
    {
        // No `preprocessor` field on the selected model -> the factory is still
        // consulted (with null) but returns no preprocessor, so the raw prompt is used.
        $factory = $this->createMock(VideoPromptPreprocessorFactory::class);

        $capturedPrompt = '';
        $videoImg2VidProcessor = $this->createMock(VideoImg2VidProcessor::class);
        $videoImg2VidProcessor->method('respondWithImg2VidResultBasedOnPhotoInMessage')
            ->willReturnCallback(function ($_, $_cmd, string $prompt, $_cb) use (&$capturedPrompt): void {
                $capturedPrompt = $prompt;
            });

        $processor = $this->buildProcessor(
            img2VideoModelPreference: $this->preferenceReturning('plain-i2v'),
            img2videoModelsConfig: ['plain-i2v' => ['url' => 'http://x']],
            videoImg2VidProcessor: $videoImg2VidProcessor,
            preprocessorFactory: $factory,
        );

        $processor->processMessageChain($this->chainWithReplyPhoto('a dog on a beach'), $this->progressCallback());

        $this->assertSame('a dog on a beach', $capturedPrompt);
    }

    public function testPreprocessorFailureFallsBackToRawPrompt(): void
    {
        $preprocessor = $this->createMock(VideoPromptPreprocessor::class);
        $preprocessor->method('preprocess')->willThrowException(new \RuntimeException('llm down'));
        $factory = $this->factoryReturning('minimax-h3', $preprocessor);

        $capturedPrompt = '';
        $videoImg2VidProcessor = $this->createMock(VideoImg2VidProcessor::class);
        $videoImg2VidProcessor->method('respondWithImg2VidResultBasedOnPhotoInMessage')
            ->willReturnCallback(function ($_, $_cmd, string $prompt, $_cb) use (&$capturedPrompt): void {
                $capturedPrompt = $prompt;
            });

        $processor = $this->buildProcessor(
            img2VideoModelPreference: $this->preferenceReturning('minimax-h3-preprocessed'),
            img2videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            videoImg2VidProcessor: $videoImg2VidProcessor,
            telegramFileDownloader: $this->downloaderReturning('photo-bytes'),
            preprocessorFactory: $factory,
        );

        $processor->processMessageChain($this->chainWithReplyPhoto('a dog'), $this->progressCallback());

        $this->assertSame('a dog', $capturedPrompt);
    }

    private function buildProcessor(
        ?Txt2VideoClient $txt2VideoClient = null,
        ?VideoResponder $videoResponder = null,
        ?VideoImg2VidProcessor $videoImg2VidProcessor = null,
        ?TelegramFileDownloader $telegramFileDownloader = null,
        ?VideoPromptPreprocessorFactory $preprocessorFactory = null,
        ?UserPreferenceReaderInterface $videoModelPreference = null,
        array $videoModelsConfig = [],
        ?UserPreferenceReaderInterface $img2VideoModelPreference = null,
        array $img2videoModelsConfig = [],
    ): VideoProcessor {
        return new VideoProcessor(
            $txt2VideoClient ?? $this->createMock(Txt2VideoClient::class),
            $videoResponder ?? $this->createMock(VideoResponder::class),
            $videoImg2VidProcessor ?? $this->createMock(VideoImg2VidProcessor::class),
            $this->createMock(AltTextProvider::class),
            $telegramFileDownloader ?? $this->createMock(TelegramFileDownloader::class),
            $preprocessorFactory ?? $this->createMock(VideoPromptPreprocessorFactory::class),
            $videoModelPreference ?? $this->preferenceReturning(null),
            $videoModelsConfig,
            $img2VideoModelPreference ?? $this->preferenceReturning(null),
            $img2videoModelsConfig,
            new NullLogger(),
        );
    }

    private function preprocessorThatPrefixes(string $prefix): VideoPromptPreprocessor
    {
        $preprocessor = $this->createMock(VideoPromptPreprocessor::class);
        $preprocessor->method('preprocess')
            ->willReturnCallback(function (VideoGenerationPrompt $input) use ($prefix): string {
                return $prefix . $input->userPrompt;
            });

        return $preprocessor;
    }

    private function factoryReturning(string $key, VideoPromptPreprocessor $preprocessor): VideoPromptPreprocessorFactory
    {
        $factory = $this->createMock(VideoPromptPreprocessorFactory::class);
        $factory->method('createByConfigKey')->willReturnCallback(
            static function (?string $configKey) use ($key, $preprocessor): ?VideoPromptPreprocessor {
                return $configKey === $key ? $preprocessor : null;
            },
        );
        $factory->method('createForModelPreference')->willReturnCallback(
            // VideoProcessor only calls createForModelPreference; resolve the
            // preprocessor from the selected model's `preprocessor` field like
            // the real factory does.
            static function ($preference, array $config, int $userId) use ($key, $preprocessor): ?VideoPromptPreprocessor {
                $modelName = $preference->getCurrentPreferenceValue($userId);
                $entry = ($modelName !== null && isset($config[$modelName])) ? $config[$modelName] : (current($config) ?: []);
                return ($entry['preprocessor'] ?? null) === $key ? $preprocessor : null;
            },
        );

        return $factory;
    }

    private function preferenceReturning(?string $value): UserPreferenceReaderInterface
    {
        $preference = $this->createMock(UserPreferenceReaderInterface::class);
        $preference->method('getCurrentPreferenceValue')->willReturn($value);

        return $preference;
    }

    private function downloaderReturning(string $bytes): TelegramFileDownloader
    {
        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->method('downloadPhotoFromInternalMessage')->willReturn($bytes);

        return $downloader;
    }

    private function progressCallback(): ProgressUpdateCallback
    {
        return $this->createMock(ProgressUpdateCallback::class);
    }

    private function singleMessageChain(string $text): MessageChain
    {
        return new MessageChain([$this->message($text)]);
    }

    private function chainWithReplyPhoto(string $commandText): MessageChain
    {
        $previous = new InternalMessage();
        $previous->id = 9;
        $previous->chatId = -100;
        $previous->userId = 5;
        $previous->photoFileId = 'photo-file-id';

        return new MessageChain([$previous, $this->message($commandText)]);
    }

    private function message(string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100;
        $message->userId = 5;
        $message->messageText = $text;

        return $message;
    }
}
