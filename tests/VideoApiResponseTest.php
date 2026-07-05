<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\VideoGeneration\VideoApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VideoApiResponse::class)]
class VideoApiResponseTest extends TestCase
{
    private function createValidResponseData(): array
    {
        return [
            'videos' => [base64_encode('video1'), base64_encode('video2')],
            'info' => json_encode(['infotexts' => ['A generated video', 'Another video']]),
        ];
    }

    public function testFromStringWithValidData(): void
    {
        $data = $this->createValidResponseData();
        $json = json_encode($data);

        $response = VideoApiResponse::fromString($json);

        $this->assertCount(2, $response->videos);
        $this->assertSame('A generated video', $response->info['infotexts'][0]);
    }

    public function testGetFirstVideoAsMp4(): void
    {
        $videoData = 'mp4 binary content';
        $data = [
            'videos' => [base64_encode($videoData)],
            'info' => json_encode(['infotexts' => ['test']]),
        ];

        $response = new VideoApiResponse($data['videos'], json_decode($data['info'], true));

        $this->assertSame($videoData, $response->getFirstVideoAsMp4());
    }

    public function testGetCaptionReturnsFirstInfotext(): void
    {
        $data = $this->createValidResponseData();
        $json = json_encode($data);

        $response = VideoApiResponse::fromString($json);

        $this->assertSame('A generated video', $response->getCaption());
    }

    public function testGetCaptionReturnsNullWhenNoInfotexts(): void
    {
        $data = [
            'videos' => [base64_encode('video')],
            'info' => json_encode([]),
        ];

        $response = new VideoApiResponse($data['videos'], json_decode($data['info'], true));

        $this->assertNull($response->getCaption());
    }

    public function testFromStringRejectsMissingVideos(): void
    {
        $this->expectException(\RuntimeException::class);

        VideoApiResponse::fromString(json_encode(['info' => json_encode([])]));
    }

    public function testFromStringRejectsMissingInfo(): void
    {
        $this->expectException(\RuntimeException::class);

        VideoApiResponse::fromString(json_encode(['videos' => []]));
    }

    public function testFromStringRejectsNonArrayVideos(): void
    {
        $this->expectException(\RuntimeException::class);

        VideoApiResponse::fromString(json_encode([
            'videos' => 'not an array',
            'info' => json_encode([]),
        ]));
    }

    public function testFromStringRejectsNonStringInfo(): void
    {
        $this->expectException(\RuntimeException::class);

        VideoApiResponse::fromString(json_encode([
            'videos' => [],
            'info' => ['not' => 'a string'],
        ]));
    }

    public function testConstructorStoresValues(): void
    {
        $videos = [base64_encode('vid1')];
        $info = ['infotexts' => ['caption']];

        $response = new VideoApiResponse($videos, $info);

        $this->assertSame($videos, $response->videos);
        $this->assertSame($info, $response->info);
    }

    public function testFromStringRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        VideoApiResponse::fromString('not json at all');
    }
}
