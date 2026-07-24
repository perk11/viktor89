<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Longman\TelegramBot\Entities\Audio;
use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use Perk11\Viktor89\VoiceGeneration\AudioSuperResolutionApiClient;
use Perk11\Viktor89\VoiceGeneration\AudioUpscaleProcessor;
use Perk11\Viktor89\VoiceGeneration\TtsApiResponse;
use Perk11\Viktor89\VoiceGeneration\VoiceResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(AudioUpscaleProcessor::class)]
class AudioUpscaleProcessorTest extends TestCase
{
    use TelegramRecordingTrait;

    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    /** Builds a processor with a stubbed seed preference. */
    private function buildProcessor(
        AudioSuperResolutionApiClient $client,
        VoiceResponder $responder,
        TelegramFileDownloader $downloader,
        ?string $seed = null,
    ): AudioUpscaleProcessor {
        return new AudioUpscaleProcessor(
            $client,
            $responder,
            $downloader,
            new FixedValuePreferenceProvider($seed),
            new \Psr\Log\NullLogger(),
        );
    }

    private function audioMessage(): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 10;
        $message->chatId = -100;
        $message->audio = new Audio(['file_id' => 'file123', 'file_unique_id' => 'uid', 'duration' => 60]);

        return $message;
    }

    private function commandMessage(string $text = ''): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 20;
        $message->userId = 777;
        $message->chatId = -100;
        // CommandBasedResponderTrigger strips "/audioupscale" + trims before the processor runs.
        $message->messageText = $text;

        return $message;
    }

    public function testRejectsWhenNotAReply(): void
    {
        $client = $this->createMock(AudioSuperResolutionApiClient::class);
        $client->expects($this->never())->method('enhance');
        $processor = $this->buildProcessor($client, $this->createStub(VoiceResponder::class), $this->createStub(TelegramFileDownloader::class));

        $chain = new MessageChain([$this->commandMessage()]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertNotEmpty($result->response->messageText);
    }

    public function testRejectsWhenRepliedMessageHasNoAudio(): void
    {
        $client = $this->createMock(AudioSuperResolutionApiClient::class);
        $client->expects($this->never())->method('enhance');
        $processor = $this->buildProcessor($client, $this->createStub(VoiceResponder::class), $this->createStub(TelegramFileDownloader::class));

        $previousNoAudio = new InternalMessage();
        $previousNoAudio->id = 5;
        $previousNoAudio->chatId = -100;
        $chain = new MessageChain([$previousNoAudio, $this->commandMessage()]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
    }

    public function testDownloadsAudioCallsEnhanceWithoutSeedWhenUnsetAndSendsVoice(): void
    {
        $this->installRecordingTelegramClient();

        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->expects($this->once())->method('downloadFile')
            ->with('file123')
            ->willReturn('audio-bytes');

        $client = $this->createMock(AudioSuperResolutionApiClient::class);
        $client->expects($this->once())->method('enhance')
            ->with('audio-bytes', null)
            ->willReturn(new TtsApiResponse('enhanced-output', []));

        $responder = $this->createMock(VoiceResponder::class);
        $responder->expects($this->once())->method('sendVoice')
            ->with($this->callback(fn (InternalMessage $m): bool => $m->id === 20), 'enhanced-output');

        $processor = $this->buildProcessor($client, $responder, $downloader);

        $chain = new MessageChain([$this->audioMessage(), $this->commandMessage()]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNull($result->response);
        $this->assertSame('😎', $result->reaction);
    }

    public function testForwardsConfiguredSeed(): void
    {
        $this->installRecordingTelegramClient();

        $downloader = $this->createStub(TelegramFileDownloader::class);
        $downloader->method('downloadFile')->willReturn('audio-bytes');

        $client = $this->createMock(AudioSuperResolutionApiClient::class);
        $client->expects($this->once())->method('enhance')
            ->with('audio-bytes', 123)
            ->willReturn(new TtsApiResponse('out', []));

        $processor = $this->buildProcessor($client, $this->createStub(VoiceResponder::class), $downloader, seed: '123');

        $chain = new MessageChain([$this->audioMessage(), $this->commandMessage()]);

        $processor->processMessageChain($chain, $this->createMockCallback());
    }

    public function testReturnsFailureReactionWhenEnhanceThrows(): void
    {
        $this->installRecordingTelegramClient();

        $downloader = $this->createStub(TelegramFileDownloader::class);
        $downloader->method('downloadFile')->willReturn('audio-bytes');

        $client = $this->createStub(AudioSuperResolutionApiClient::class);
        $client->method('enhance')
            ->willThrowException(new \RuntimeException('server down'));

        $responder = $this->createMock(VoiceResponder::class);
        $responder->expects($this->never())->method('sendVoice');

        $processor = $this->buildProcessor($client, $responder, $downloader);

        $chain = new MessageChain([$this->audioMessage(), $this->commandMessage()]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNull($result->response);
        $this->assertSame('🤔', $result->reaction);
    }
}
