<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\VoiceGeneration\AudioSuperResolutionApiClient;
use Perk11\Viktor89\VoiceGeneration\TtsApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AudioSuperResolutionApiClient::class)]
class AudioSuperResolutionApiClientTest extends TestCase
{
    private function client(Client $httpClient, string $url = 'http://localhost:8240'): AudioSuperResolutionApiClient
    {
        return new AudioSuperResolutionApiClient($url, $httpClient);
    }

    private function okResponse(string $audio = 'enhanced-audio'): Response
    {
        return new Response(
            200,
            [],
            json_encode(
                ['voice_data' => base64_encode($audio), 'info' => ['model_name' => 'basic']],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testIsConcreteClass(): void
    {
        $reflection = new \ReflectionClass(AudioSuperResolutionApiClient::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testConstructorAcceptsOptionalHttpClientForTesting(): void
    {
        $params = (new \ReflectionClass(AudioSuperResolutionApiClient::class))->getConstructor()->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('url', $params[0]->getName());
        $this->assertSame('httpClient', $params[1]->getName());
        $this->assertTrue($params[1]->allowsNull());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
    }

    public function testEnhancePostsToEnhanceEndpointWithAudioAndParsesResponse(): void
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

        $response = $this->client($http)->enhance('raw-bytes');

        $this->assertSame('http://localhost:8240/enhance', $captured['uri']);
        $this->assertSame(base64_encode('raw-bytes'), $captured['json']['audio']);
        // Optional params are omitted when not supplied.
        $this->assertArrayNotHasKey('seed', $captured['json']);
        $this->assertArrayNotHasKey('ddim_steps', $captured['json']);
        $this->assertArrayNotHasKey('guidance_scale', $captured['json']);
        // Response is decoded into a TtsApiResponse.
        $this->assertInstanceOf(TtsApiResponse::class, $response);
        $this->assertSame('enhanced-audio', $response->voiceFileContents);
    }

    public function testEnhanceIncludesOptionalParamsWhenProvided(): void
    {
        $captured = [];
        $http = $this->createStub(Client::class);
        $http->method('post')
            ->willReturnCallback(function (string $uri, array $options) use (&$captured): Response {
                $captured['json'] = $options['json'];

                return $this->okResponse();
            });

        $this->client($http)->enhance('audio', 12345, 50, 3.5);

        $this->assertSame(12345, $captured['json']['seed']);
        $this->assertSame(50, $captured['json']['ddim_steps']);
        $this->assertSame(3.5, $captured['json']['guidance_scale']);
    }

    public function testEnhanceStripsTrailingSlashFromUrl(): void
    {
        $captured = [];
        $http = $this->createStub(Client::class);
        $http->method('post')
            ->willReturnCallback(function (string $uri, array $options) use (&$captured): Response {
                $captured['uri'] = $uri;

                return $this->okResponse();
            });

        $this->client($http, 'http://localhost:8240/')->enhance('audio');

        $this->assertSame('http://localhost:8240/enhance', $captured['uri']);
    }
}
