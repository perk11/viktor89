<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolDefinition::class)]
class ToolDefinitionTest extends TestCase
{
    private function createMockExecutor(): ToolCallExecutorInterface
    {
        return $this->createMock(ToolCallExecutorInterface::class);
    }

    public function testConstructorWithValidParameters(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('test_tool', $executor, 'A test tool');

        $array = $definition->toArray();

        $this->assertSame('function', $array['type']);
        $this->assertSame('test_tool', $array['function']['name']);
        $this->assertSame('A test tool', $array['function']['description']);
    }

    public function testConstructorRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool name cannot be empty.');

        new ToolDefinition('', $this->createMockExecutor(), null);
    }

    public function testConstructorRejectsNonFunctionType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only "function" tools are supported.');

        new ToolDefinition('valid_name', $this->createMockExecutor(), 'desc', [], 'invalid');
    }

    public function testToArrayWithParameters(): void
    {
        $executor = $this->createMockExecutor();
        $param = new ToolParameter('prompt', ['type' => 'string', 'description' => 'The prompt'], true);
        $definition = new ToolDefinition('generate', $executor, 'Generate content', [$param]);

        $array = $definition->toArray();

        $this->assertArrayHasKey('prompt', $array['function']['parameters']['properties']);
        $this->assertSame(['type' => 'string', 'description' => 'The prompt'], $array['function']['parameters']['properties']['prompt']);
        $this->assertSame(['prompt'], $array['function']['parameters']['required']);
    }

    public function testToArrayWithoutDescription(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('simple', $executor, null);

        $array = $definition->toArray();

        $this->assertArrayNotHasKey('description', $array['function']);
    }

    public function testToArrayWithoutRequiredParameters(): void
    {
        $executor = $this->createMockExecutor();
        $param = new ToolParameter('prompt', ['type' => 'string'], false);
        $definition = new ToolDefinition('generate', $executor, 'Generate content', [$param]);

        $array = $definition->toArray();

        $this->assertArrayNotHasKey('required', $array['function']['parameters']);
    }

    public function testJsonSerialize(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('test', $executor, 'A test');

        $json = json_encode($definition);
        $decoded = json_decode($json, true);

        $this->assertSame('function', $decoded['type']);
        $this->assertSame('test', $decoded['function']['name']);
    }

    public function testToArrayWithEmptyDescription(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('test', $executor, '');

        $array = $definition->toArray();

        $this->assertArrayNotHasKey('description', $array['function']);
    }

    public function testSilentProperty(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('quiet', $executor, 'Quiet tool', [], 'function', true);

        // Verify silent is set (via reflection since it's not in toArray output)
        $reflection = new \ReflectionProperty(ToolDefinition::class, 'silent');
        $reflection->setAccessible(true);
        $this->assertTrue($reflection->getValue($definition));
    }
}
