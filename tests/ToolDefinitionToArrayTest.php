<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolDefinition::class)]
class ToolDefinitionToArrayTest extends TestCase
{
    private function createMockExecutor(): ToolCallExecutorInterface
    {
        return $this->createMock(ToolCallExecutorInterface::class);
    }

    public function testToArrayWithMultipleParameters(): void
    {
        $executor = $this->createMockExecutor();
        $params = [
            new ToolParameter('param1', ['type' => 'string'], true),
            new ToolParameter('param2', ['type' => 'integer'], false),
            new ToolParameter('param3', ['type' => 'boolean'], true),
        ];
        $definition = new ToolDefinition('multi_param', $executor, 'Multi param tool', $params);

        $array = $definition->toArray();

        $this->assertCount(3, $array['function']['parameters']['properties']);
        $this->assertCount(2, $array['function']['parameters']['required']);
        $this->assertContains('param1', $array['function']['parameters']['required']);
        $this->assertContains('param3', $array['function']['parameters']['required']);
    }

    public function testToArrayWithDuplicateRequired(): void
    {
        $executor = $this->createMockExecutor();
        $params = [
            new ToolParameter('a', ['type' => 'string'], true),
            new ToolParameter('b', ['type' => 'integer'], false),
            new ToolParameter('c', ['type' => 'string'], true),
        ];
        $definition = new ToolDefinition('dedup', $executor, 'test', $params);

        $array = $definition->toArray();

        $this->assertSame(['a', 'c'], $array['function']['parameters']['required']);
    }

    public function testToArrayWithNullDescription(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('no_desc', $executor, null);

        $array = $definition->toArray();

        $this->assertArrayNotHasKey('description', $array['function']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('test', $executor, 'desc');

        $json = json_encode($definition);
        $decoded = json_decode($json, true);
        $array = $definition->toArray();

        $this->assertSame($array, $decoded);
    }

    public function testToArrayWithEmptyParametersArray(): void
    {
        $executor = $this->createMockExecutor();
        $definition = new ToolDefinition('empty_params', $executor, 'no params');

        $array = $definition->toArray();

        $this->assertSame([], $array['function']['parameters']['properties']);
        $this->assertArrayNotHasKey('required', $array['function']['parameters']);
    }

    public function testRejectsNonToolParameterInParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolDefinition('test', $this->createMockExecutor(), 'desc', ['not a parameter']);
    }
}
