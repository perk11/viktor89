<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantFactory::class)]
class AssistantFactoryTest extends TestCase
{
    public function testModelWithoutAllowedChatIdsIsAllowedEverywhere(): void
    {
        $this->assertTrue(AssistantFactory::isModelAllowedInChat(['selectableByUser' => true], -100));
        $this->assertTrue(AssistantFactory::isModelAllowedInChat([], -1));
    }

    public function testModelIsAllowedOnlyInListedChats(): void
    {
        $config = ['allowedChatIds' => ['-1001804789551', '-1002114209100']];

        $this->assertTrue(AssistantFactory::isModelAllowedInChat($config, -1001804789551));
        $this->assertTrue(AssistantFactory::isModelAllowedInChat($config, -1002114209100));
        $this->assertFalse(AssistantFactory::isModelAllowedInChat($config, -999));
    }

    public function testAllowedChatIdsMatchAcrossStringAndInt(): void
    {
        // Config values are JSON strings; runtime chat ids are ints.
        $this->assertTrue(AssistantFactory::isModelAllowedInChat(['allowedChatIds' => ['-1001804789551']], -1001804789551));
    }

    public function testMcpServerKeyIsStableForTheSameConfig(): void
    {
        $config = [
            'command' => 'npx',
            'args'    => ['-y', '@z_ai/mcp-server'],
            'env'     => ['Z_AI_API_KEY' => 'secret'],
        ];

        $this->assertSame(self::mcpServerKey($config), self::mcpServerKey($config));
    }

    public function testMcpServerKeyIgnoresToolLevelOptions(): void
    {
        // Models sharing the same server but with different tool filters must
        // reuse one cached connection.
        $base = [
            'command' => 'npx',
            'args'    => ['-y', '@z_ai/mcp-server'],
        ];

        $keyWithoutFilter = self::mcpServerKey($base);
        $keyWithFilter = self::mcpServerKey($base + [
            'allowedTools' => ['analyze_video'],
            'silent'       => true,
            'silentTools'  => ['analyze_image'],
        ]);

        $this->assertSame($keyWithoutFilter, $keyWithFilter);
    }

    public function testMcpServerKeyDiffersForDifferentServers(): void
    {
        $this->assertNotSame(
            self::mcpServerKey(['command' => 'npx', 'args' => ['-y', '@z_ai/mcp-server']]),
            self::mcpServerKey(['command' => 'node', 'args' => ['server.js']]),
        );
    }

    private static function mcpServerKey(array $serverConfig): string
    {
        $method = new \ReflectionMethod(AssistantFactory::class, 'mcpServerKey');

        return $method->invoke(null, $serverConfig);
    }
}
