<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Mcp\Client;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Perk11\Viktor89\Assistant\Tool\ZaiWebSearchToolCallExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZaiWebSearchToolCallExecutor::class)]
class ZaiWebSearchToolCallExecutorTest extends TestCase
{
    public function testExecutesSearchAndNormalizesJsonResults(): void
    {
        $resultsJson = json_encode([
            ['title' => 'First', 'content' => 'one', 'link' => 'https://example.com/1'],
            ['title' => 'Second', 'content' => 'two', 'link' => 'https://example.com/2'],
        ], JSON_THROW_ON_ERROR);

        $executor = $this->buildExecutor($this->clientReturningText($resultsJson));

        $result = $executor->executeToolCall(['query' => 'latest php news']);

        $expected = ['results' => [
            ['title' => 'First', 'url' => 'https://example.com/1', 'content' => 'one'],
            ['title' => 'Second', 'url' => 'https://example.com/2', 'content' => 'two'],
        ]];
        $this->assertSame($expected, $result);
    }

    /**
     * Regression test for "MCP error -400: search_query cannot be empty": the
     * Z.ai MCP tool reads the query from the `search_query` argument, not
     * `query`, so the shared tool's `query` argument must be mapped to it.
     */
    public function testCallsMcpToolWithSearchQueryParameter(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('callTool')
            ->with('web_search_prime', ['search_query' => 'my search'])
            ->willReturn(CallToolResult::success([new TextContent('[]')]));

        $executor = $this->buildExecutor($client);

        $executor->executeToolCall(['query' => 'my search']);
    }

    public function testDoesNotConnectUntilTheToolIsUsed(): void
    {
        $connections = 0;
        $executor = new ZaiWebSearchToolCallExecutor(
            'test-key',
            clientProvider: function () use (&$connections): Client {
                $connections++;
                return $this->createMock(Client::class);
            },
        );

        $this->assertSame(0, $connections, 'Executor must not connect at construction time');
    }

    public function testConnectsLazilyAndCachesTheClient(): void
    {
        $connections = 0;
        $client = $this->clientReturningText('[]');

        $executor = new ZaiWebSearchToolCallExecutor(
            'test-key',
            clientProvider: function () use ($client, &$connections): Client {
                $connections++;
                return $client;
            },
        );

        $executor->executeToolCall(['query' => 'a']);
        $executor->executeToolCall(['query' => 'b']);

        $this->assertSame(1, $connections, 'Client must be created once and reused');
    }

    public function testWrapsConnectionOrCallFailureInRuntimeException(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('callTool')
            ->willThrowException(new \RuntimeException('MCP error -400: boom'));

        $executor = $this->buildExecutor($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Z.ai MCP web search request failed: MCP error -400: boom');
        $executor->executeToolCall(['query' => 'x']);
    }

    public function testCapsResultsToMaxResults(): void
    {
        $raw = [];
        for ($i = 1; $i <= 8; $i++) {
            $raw[] = ['title' => "T$i", 'content' => "C$i", 'link' => "https://example.com/$i"];
        }
        $executor = $this->buildExecutor($this->clientReturningText(json_encode($raw, JSON_THROW_ON_ERROR)));

        $result = $executor->executeToolCall(['query' => 'x', 'max_results' => 3]);

        $this->assertCount(3, $result['results']);
        $this->assertSame('T1', $result['results'][0]['title']);
        $this->assertSame('T3', $result['results'][2]['title']);
    }

    public function testAcceptsUrlFieldAsFallbackToLink(): void
    {
        $executor = $this->buildExecutor($this->clientReturningText(json_encode([
            ['title' => 'T', 'content' => 'C', 'url' => 'https://via-url-field'],
        ], JSON_THROW_ON_ERROR)));

        $result = $executor->executeToolCall(['query' => 'x']);

        $this->assertSame('https://via-url-field', $result['results'][0]['url']);
    }

    public function testWrapsNonJsonTextAsRawContent(): void
    {
        $executor = $this->buildExecutor($this->clientReturningText('This is plain markdown text, not JSON.'));

        $result = $executor->executeToolCall(['query' => 'x']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('This is plain markdown text, not JSON.', $result['results'][0]['content']);
        $this->assertNull($result['results'][0]['title']);
        $this->assertNull($result['results'][0]['url']);
    }

    public function testReturnsEmptyResultsWhenNoTextContent(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('callTool')->willReturn(CallToolResult::success([]));

        $executor = $this->buildExecutor($client);

        $this->assertSame(['results' => []], $executor->executeToolCall(['query' => 'x']));
    }

    public function testUsesStructuredContentWhenAvailable(): void
    {
        $structured = ['results' => [
            ['title' => 'SC', 'content' => 'sc-content', 'link' => 'https://example.com/sc'],
        ]];
        $client = $this->createMock(Client::class);
        $client->method('callTool')->willReturn(new CallToolResult([], false, $structured));

        $executor = $this->buildExecutor($client);
        $result = $executor->executeToolCall(['query' => 'x']);

        $this->assertSame('SC', $result['results'][0]['title']);
        $this->assertSame('https://example.com/sc', $result['results'][0]['url']);
    }

    public function testThrowsWhenToolResultIsError(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('callTool')->willReturn(
            CallToolResult::error([new TextContent('rate limited')])
        );

        $executor = $this->buildExecutor($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Z.ai MCP web search returned an error: rate limited');
        $executor->executeToolCall(['query' => 'x']);
    }

    public function testRejectsMissingQuery(): void
    {
        $executor = $this->buildExecutor($this->createMock(Client::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required argument: query');
        $executor->executeToolCall([]);
    }

    public function testRejectsNonStringQuery(): void
    {
        $executor = $this->buildExecutor($this->createMock(Client::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid argument type: query must be a string');
        $executor->executeToolCall(['query' => 42]);
    }

    public function testRejectsUnsupportedArgument(): void
    {
        $executor = $this->buildExecutor($this->createMock(Client::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported argument: foo');
        $executor->executeToolCall(['query' => 'x', 'foo' => 'bar']);
    }

    public function testRejectsInvalidMaxResults(): void
    {
        $executor = $this->buildExecutor($this->createMock(Client::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_results must be an integer between 1 and 10');
        $executor->executeToolCall(['query' => 'x', 'max_results' => 99]);
    }

    public function testRejectsInvalidMaxResponseSizeBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxResponseSizeBytes must be an integer greater than 0');
        new ZaiWebSearchToolCallExecutor('test-key', maxResponseSizeBytes: 0);
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zAiSearchApiKey cannot be empty');
        new ZaiWebSearchToolCallExecutor('   ');
    }

    public function testLimitsOversizedResponse(): void
    {
        $raw = [['title' => str_repeat('a', 2000), 'content' => 'c', 'link' => 'u']];
        $client = $this->createMock(Client::class);
        $client->method('callTool')
            ->willReturn(CallToolResult::success([new TextContent(json_encode($raw, JSON_THROW_ON_ERROR))]));

        $executor = new ZaiWebSearchToolCallExecutor('test-key', maxResponseSizeBytes: 80, clientProvider: fn () => $client);

        $limited = $executor->executeToolCall(['query' => 'x']);

        $this->assertTrue($limited['truncated']);
        $this->assertLessThanOrEqual(
            80,
            strlen(json_encode($limited, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * Build an executor that uses (and reuses) the given mock MCP client.
     */
    private function buildExecutor(Client $client, int $maxResponseSizeBytes = 64000): ZaiWebSearchToolCallExecutor
    {
        return new ZaiWebSearchToolCallExecutor(
            'test-key',
            maxResponseSizeBytes: $maxResponseSizeBytes,
            clientProvider: fn (): Client => $client,
        );
    }

    private function clientReturningText(string $text): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('callTool')->willReturn(CallToolResult::success([new TextContent($text)]));

        return $client;
    }
}
