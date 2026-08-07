<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Mcp\Client;
use Mcp\Schema\Result\ListToolsResult;
use Perk11\Viktor89\Assistant\Tool\McpServerConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpServerConnection::class)]
class McpServerConnectionTest extends TestCase
{
    public function testGetToolsCachesResultSoListToolsRunsOnce(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('listTools')->willReturn(new ListToolsResult([]));

        $connection = new McpServerConnection(static fn () => $client);

        $connection->getTools();
        $connection->getTools();
    }

    public function testReconnectRebuildsClientAndClearsToolCache(): void
    {
        $first = $this->createMock(Client::class);
        $first->method('listTools')->willReturn(new ListToolsResult([]));
        $second = $this->createMock(Client::class);
        // listTools on the rebuilt client must run exactly once after reconnect,
        // proving the cached tool list was dropped.
        $second->expects($this->once())->method('listTools')->willReturn(new ListToolsResult([]));

        $next = $first;
        $connect = function () use (&$next, $second): Client {
            $client = $next;
            $next = $second;

            return $client;
        };

        $connection = new McpServerConnection($connect);
        $this->assertSame($first, $connection->getClient());
        $connection->getTools(); // populates cache from $first

        $connection->reconnect();
        $this->assertSame($second, $connection->getClient());

        $connection->getTools(); // refetches from $second
        $connection->getTools(); // served from cache again
    }
}
