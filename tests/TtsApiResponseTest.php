<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\VoiceGeneration\TtsApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtsApiResponse::class)]
class TtsApiResponseTest extends TestCase
{
    private function createValidResponseData(string $voiceContent = 'hello'): array
    {
        return [
            'voice_data' => base64_encode($voiceContent),
            'info' => ['duration' => 1.5, 'model' => 'test'],
        ];
    }

    public function testFromStringWithValidData(): void
    {
        $data = $this->createValidResponseData('audio_data');
        $json = json_encode($data);

        $response = TtsApiResponse::fromString($json);

        $this->assertSame('audio_data', $response->voiceFileContents);
        $this->assertSame(['duration' => 1.5, 'model' => 'test'], $response->info);
    }

    public function testFromStringWithEmptyVoiceData(): void
    {
        $data = $this->createValidResponseData('');
        $json = json_encode($data);

        $response = TtsApiResponse::fromString($json);

        $this->assertSame('', $response->voiceFileContents);
    }

    public function testFromStringRejectsMissingVoiceData(): void
    {
        $this->expectException(\RuntimeException::class);

        TtsApiResponse::fromString(json_encode(['info' => []]));
    }

    public function testFromStringRejectsMissingInfo(): void
    {
        $this->expectException(\RuntimeException::class);

        TtsApiResponse::fromString(json_encode(['voice_data' => base64_encode('test')]));
    }

    public function testFromStringRejectsNonStringVoiceData(): void
    {
        $this->expectException(\RuntimeException::class);

        TtsApiResponse::fromString(json_encode([
            'voice_data' => 123,
            'info' => [],
        ]));
    }

    public function testFromStringRejectsNonArrayInfo(): void
    {
        $this->expectException(\RuntimeException::class);

        TtsApiResponse::fromString(json_encode([
            'voice_data' => base64_encode('test'),
            'info' => 'not an array',
        ]));
    }

    public function testConstructorStoresValues(): void
    {
        $response = new TtsApiResponse('raw_audio', ['model' => 'tts-v1']);

        $this->assertSame('raw_audio', $response->voiceFileContents);
        $this->assertSame(['model' => 'tts-v1'], $response->info);
    }

    public function testFromStringDecodesBase64(): void
    {
        $original = 'binary audio content';
        $data = $this->createValidResponseData($original);
        $json = json_encode($data);

        $response = TtsApiResponse::fromString($json);

        $this->assertSame($original, $response->voiceFileContents);
    }

    public function testFromStringRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        TtsApiResponse::fromString('not json');
    }
}
