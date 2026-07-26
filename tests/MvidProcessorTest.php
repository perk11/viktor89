<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\VideoGeneration\AssistedVideoProcessor;
use Perk11\Viktor89\VideoGeneration\AudioImgTxt2VidClient;
use Perk11\Viktor89\VideoGeneration\MvidProcessor;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
use Perk11\Viktor89\VoiceGeneration\SingProcessor;
use Psr\Log\NullLogger;

#[CoversClass(MvidProcessor::class)]
class MvidProcessorTest extends TestCase
{
    private function buildProcessor(
        ?ImgTagExtractor $imgTagExtractor = null,
        ?TelegramFileDownloader $telegramFileDownloader = null,
        bool $generateFirstFrame = true,
        string $commandName = '/mvid',
    ): MvidProcessor {
        return new MvidProcessor(
            $this->createMock(AssistedVideoProcessor::class),
            $this->createMock(AssistantInterface::class),
            $this->createMock(SingProcessor::class),
            $this->createMock(AudioImgTxt2VidClient::class),
            $this->createMock(VideoResponder::class),
            $telegramFileDownloader ?? $this->createMock(TelegramFileDownloader::class),
            $imgTagExtractor ?? $this->createMock(ImgTagExtractor::class),
            $this->createMock(AltTextProvider::class),
            'flux-dev-720x480',
            'ltx-2-distilled',
            new NullLogger(),
            $generateFirstFrame,
            $commandName,
        );
    }

    private function singleMessageChain(string $text = ''): MessageChain
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100;
        $message->userId = 5;
        $message->messageText = $text;

        return new MessageChain([$message]);
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(MvidProcessor::class);
        $this->assertTrue($reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class));
    }

    public function testDoesNotDeclareItsOwnTriggeringCommands(): void
    {
        $reflection = new \ReflectionClass(MvidProcessor::class);
        $this->assertFalse(
            $reflection->implementsInterface(\Perk11\Viktor89\GetTriggeringCommandsInterface::class),
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(MvidProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
        $this->assertTrue($method->isPublic());
    }

    public function testReturnsUsageWhenNoImageAndNoText(): void
    {
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);
        $imgTagExtractor->method('extractImageTags')->willReturnArgument(0);

        $processor = $this->buildProcessor($imgTagExtractor);
        $result = $processor->processMessageChain(
            $this->singleMessageChain(''),
            $this->createMock(ProgressUpdateCallback::class),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('/mvid', $result->response->messageText);
    }

    public function testReturnsSavedImageNotFoundMessage(): void
    {
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);
        $imgTagExtractor->method('extractImageTags')
            ->willThrowException(new SavedImageNotFoundException('my-picture'));

        $processor = $this->buildProcessor($imgTagExtractor);
        $result = $processor->processMessageChain(
            $this->singleMessageChain('<img>my-picture</img>'),
            $this->createMock(ProgressUpdateCallback::class),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('my-picture', $result->response->messageText);
        $this->assertStringContainsString('/saveas', $result->response->messageText);
    }

    public function testRejectsMoreThanOneImage(): void
    {
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);
        $imgTagExtractor->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('prompt', ['png1', 'png2']));

        $processor = $this->buildProcessor($imgTagExtractor);
        $result = $processor->processMessageChain(
            $this->singleMessageChain('prompt'),
            $this->createMock(ProgressUpdateCallback::class),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('не более одного', $result->response->messageText);
    }

    public function testReturnsErrorWhenReplyPhotoDownloadFails(): void
    {
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);
        $imgTagExtractor->method('extractImageTags')->willReturnArgument(0);

        $telegramFileDownloader = $this->createMock(TelegramFileDownloader::class);
        $telegramFileDownloader->method('downloadPhotoFromInternalMessage')
            ->willThrowException(new \Exception('boom'));

        $previous = new InternalMessage();
        $previous->id = 9;
        $previous->chatId = -100;
        $previous->userId = 5;
        $previous->photoFileId = 'abc';

        $command = new InternalMessage();
        $command->id = 1;
        $command->chatId = -100;
        $command->userId = 5;
        $command->messageText = '';

        $processor = $this->buildProcessor($imgTagExtractor, $telegramFileDownloader);
        $result = $processor->processMessageChain(
            new MessageChain([$previous, $command]),
            $this->createMock(ProgressUpdateCallback::class),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertSame('🤔', $result->reaction);
    }

    public function testConstructorExposesMvideoOptionsDefaultingToMvid(): void
    {
        $params = (new \ReflectionClass(MvidProcessor::class))
            ->getConstructor()
            ->getParameters();

        $byName = [];
        foreach ($params as $param) {
            $byName[$param->getName()] = $param;
        }

        $this->assertArrayHasKey('generateFirstFrame', $byName);
        $this->assertTrue($byName['generateFirstFrame']->isDefaultValueAvailable());
        $this->assertTrue($byName['generateFirstFrame']->getDefaultValue());

        $this->assertArrayHasKey('commandName', $byName);
        $this->assertTrue($byName['commandName']->isDefaultValueAvailable());
        $this->assertSame('/mvid', $byName['commandName']->getDefaultValue());
    }

    public function testMvideoModeUsageMessageReferencesMvideoCommand(): void
    {
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);
        $imgTagExtractor->method('extractImageTags')->willReturnArgument(0);

        $processor = $this->buildProcessor(
            $imgTagExtractor,
            null,
            generateFirstFrame: false,
            commandName: '/mvideo',
        );
        $result = $processor->processMessageChain(
            $this->singleMessageChain(''),
            $this->createMock(ProgressUpdateCallback::class),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('/mvideo закат', $result->response->messageText);
    }
}
