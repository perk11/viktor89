<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Mcp\Client;
use Perk11\Viktor89\Assistant\Tool\McpConnectionCache;
use Perk11\Viktor89\Assistant\Tool\McpServerConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpConnectionCache::class)]
class McpConnectionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        McpConnectionCache::clear();
    }

    public function testGetInvokesFactoryOnceAndReturnsSameConnection(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): McpServerConnection {
            ++$calls;

            return new McpServerConnection(static fn () => Client::builder()->build());
        };

        $first = McpConnectionCache::get('key', $factory);
        $second = McpConnectionCache::get('key', $factory);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function testDifferentKeysGetDifferentConnections(): void
    {
        $make = static fn (): McpServerConnection => new McpServerConnection(static fn () => Client::builder()->build());

        $this->assertNotSame(
            McpConnectionCache::get('alpha', $make),
            McpConnectionCache::get('beta', $make),
        );
    }

    public function testInvalidateForcesRebuildOnNextGet(): void
    {
        $make = static fn (): McpServerConnection => new McpServerConnection(static fn () => Client::builder()->build());

        $first = McpConnectionCache::get('k', $make);
        $this->assertSame($first, McpConnectionCache::get('k', $make));

        McpConnectionCache::invalidate('k');

        $this->assertNotSame($first, McpConnectionCache::get('k', $make));
    }
}
