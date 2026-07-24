<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\VoiceGeneration\CoverApiClient;
use Perk11\Viktor89\VoiceGeneration\TtsApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoverApiClient::class)]
class CoverApiClientTest extends TestCase
{
    /** @param array<string, array{url: string}> $modelConfig */
    private function client(Client $httpClient, array $modelConfig = ['Ace-Step-1.5-XL' => ['url' => 'http://localhost:8213']]): CoverApiClient
    {
        return new CoverApiClient($modelConfig, $httpClient);
    }

    private function okResponse(string $audio = 'cover-audio'): Response
    {
        return new Response(
            200,
            [],
            json_encode(
                ['voice_data' => base64_encode($audio), 'info' => ['dit_model' => 'acestep-v15-xl-base']],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testIsConcreteClass(): void
    {
        $reflection = new \ReflectionClass(CoverApiClient::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testConstructorAcceptsOptionalHttpClientForTesting(): void
    {
        $params = (new \ReflectionClass(CoverApiClient::class))->getConstructor()->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('modelConfig', $params[0]->getName());
        $this->assertSame('httpClient', $params[1]->getName());
        $this->assertTrue($params[1]->allowsNull());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
    }

    public function testCoverUsesFirstConfiguredModelAndHardcodesKnobsAndParsesResponse(): void
    {
        $captured = [];
        $http = $this->createMock(Client::class);
        $http->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $uri, array $options) use (&$captured): Response {
                $captured['uri'] = $uri;
                $captured['json'] = $options['json'];

                return $this->okResponse();
            });

        $response = $this->client($http)->cover(
            'raw-bytes',
            "[Verse 1]\nnew lyrics",
        );

        // The single updatelyricsModels entry's URL is used.
        $this->assertSame('http://localhost:8213/cover', $captured['uri']);
        $this->assertSame(base64_encode('raw-bytes'), $captured['json']['audio']);
        // Lyrics are always sent.
        $this->assertSame("[Verse 1]\nnew lyrics", $captured['json']['lyrics']);
        // The genre/caption is intentionally blank: the server requires a non-empty `prompt`, so a
        // single space is sent (a truthy, genre-less caption).
        $this->assertSame(' ', $captured['json']['prompt']);
        // cover_strength is pinned to 0.0 — /updatelyrics only swaps lyrics.
        $this->assertSame(0.0, $captured['json']['cover_strength']);
        // cover_noise is pinned to the Gradio UI cover default.
        $this->assertSame(0.2, $captured['json']['cover_noise']);
        // Optional params are omitted when not supplied.
        $this->assertArrayNotHasKey('duration', $captured['json']);
        $this->assertArrayNotHasKey('seed', $captured['json']);
        // Response is decoded into a TtsApiResponse.
        $this->assertInstanceOf(TtsApiResponse::class, $response);
        $this->assertSame('cover-audio', $response->voiceFileContents);
    }

    public function testCoverUsesFirstModelWhenMultipleConfigured(): void
    {
        $captured = [];
        $http = $this->createStub(Client::class);
        $http->method('post')
            ->willReturnCallback(function (string $uri, array $options) use (&$captured): Response {
                $captured['uri'] = $uri;
                $captured['json'] = $options['json'];

                return $this->okResponse();
            });

        $this->client(
            $http,
            ['First' => ['url' => 'http://first:1'], 'Second' => ['url' => 'http://second:2']],
        )->cover('audio', 'lyrics');

        // No model selection anymore: the first configured entry wins.
        $this->assertSame('http://first:1/cover', $captured['uri']);
    }

    public function testCoverIncludesOptionalParamsWhenProvided(): void
    {
        $captured = [];
        $http = $this->createStub(Client::class);
        $http->method('post')
            ->willReturnCallback(function (string $uri, array $options) use (&$captured): Response {
                $captured['json'] = $options['json'];

                return $this->okResponse();
            });

        $this->client($http)->cover('audio', 'lyrics', 180000, 12345);

        $this->assertSame(0.0, $captured['json']['cover_strength']);
        $this->assertSame(0.2, $captured['json']['cover_noise']);
        $this->assertSame('lyrics', $captured['json']['lyrics']);
        $this->assertSame(180000, $captured['json']['duration']);
        $this->assertSame(12345, $captured['json']['seed']);
    }

    public function testCoverThrowsWhenNoModelConfigured(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects($this->never())->method('post');

        $api = $this->client($http, []);

        $this->expectException(\InvalidArgumentException::class);
        $api->cover('audio', 'lyrics');
    }
}
