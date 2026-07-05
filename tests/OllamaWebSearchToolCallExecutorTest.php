<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\Assistant\Tool\OllamaWebSearchToolCallExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OllamaWebSearchToolCallExecutor::class)]
class OllamaWebSearchToolCallExecutorTest extends TestCase
{
    public function testExecutesSearchAndReturnsResults(): void
    {
        $apiResponse = [
            'results' => [
                ['title' => 'First', 'url' => 'https://example.com/1', 'content' => 'one'],
                ['title' => 'Second', 'url' => 'https://example.com/2', 'content' => 'two'],
            ],
        ];

        [$executor, $mock] = self::buildExecutor([$apiResponse]);

        $result = $executor->executeToolCall(['query' => 'php tutorial']);

        $this->assertSame($apiResponse, $result);

        $request = $mock->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://ollama.com/api/web_search', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('php tutorial', $body['query']);
        $this->assertSame(5, $body['max_results']);
    }

    public function testPassesThroughMaxResults(): void
    {
        [$executor, $mock] = self::buildExecutor([['results' => []]]);

        $executor->executeToolCall(['query' => 'x', 'max_results' => 8]);

        $body = json_decode((string) $mock->getLastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(8, $body['max_results']);
    }

    public function testUsesDefaultMaxResultsOfFive(): void
    {
        [$executor, $mock] = self::buildExecutor([['results' => []]]);

        $executor->executeToolCall(['query' => 'x']);

        $body = json_decode((string) $mock->getLastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(5, $body['max_results']);
    }

    public function testRejectsMissingQuery(): void
    {
        [$executor] = self::buildExecutor([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required argument: query');
        $executor->executeToolCall([]);
    }

    public function testRejectsNonStringQuery(): void
    {
        [$executor] = self::buildExecutor([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid argument type: query must be a string');
        $executor->executeToolCall(['query' => 42]);
    }

    public function testRejectsUnsupportedArgument(): void
    {
        [$executor] = self::buildExecutor([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported argument: foo');
        $executor->executeToolCall(['query' => 'x', 'foo' => 'bar']);
    }

    public function testRejectsInvalidMaxResults(): void
    {
        [$executor] = self::buildExecutor([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_results must be an integer between 1 and 10');
        $executor->executeToolCall(['query' => 'x', 'max_results' => 0]);
    }

    public function testRejectsMaxResultsAboveTen(): void
    {
        [$executor] = self::buildExecutor([]);
        $this->expectException(\InvalidArgumentException::class);
        $executor->executeToolCall(['query' => 'x', 'max_results' => 11]);
    }

    public function testConvertsHttpErrorToRuntimeException(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'server error'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $executor = new OllamaWebSearchToolCallExecutor('test-key', 64000, $client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Web search request failed:');
        $executor->executeToolCall(['query' => 'x']);
    }

    public function testRejectsInvalidMaxResponseSizeBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxResponseSizeBytes must be an integer greater than 0');
        new OllamaWebSearchToolCallExecutor('test-key', 0);
    }

    public function testLimitsOversizedResponse(): void
    {
        $apiResponse = ['results' => [
            ['title' => str_repeat('a', 1000), 'url' => 'u', 'content' => 'c'],
        ]];

        $mock = new MockHandler([new Response(200, [], json_encode($apiResponse, JSON_THROW_ON_ERROR))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $executor = new OllamaWebSearchToolCallExecutor('test-key', 80, $client);

        $limited = $executor->executeToolCall(['query' => 'x']);

        $this->assertTrue($limited['truncated']);
        $this->assertLessThanOrEqual(
            80,
            strlen(json_encode($limited, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @param array<int, array<string, mixed>|Response> $queue Responses to enqueue. Arrays are JSON-encoded.
     * @return array{OllamaWebSearchToolCallExecutor, MockHandler}
     */
    private static function buildExecutor(array $queue): array
    {
        $responses = array_map(
            static function (array $body): Response {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
            },
            $queue,
        );
        $mock = new MockHandler($responses);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return [new OllamaWebSearchToolCallExecutor('test-key', 64000, $client), $mock];
    }
}
