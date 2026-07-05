<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolParameter::class)]
class ToolParameterPropertiesTest extends TestCase
{
    public function testWithComplexProperties(): void
    {
        $properties = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name'],
        ];

        $param = new ToolParameter('input', $properties, true);

        $this->assertSame('input', $param->name);
        $this->assertSame($properties, $param->properties);
        $this->assertTrue($param->required);
    }

    public function testWithEnumProperties(): void
    {
        $properties = [
            'type' => 'string',
            'enum' => ['option_a', 'option_b', 'option_c'],
            'default' => 'option_a',
        ];

        $param = new ToolParameter('choice', $properties);

        $this->assertSame($properties, $param->properties);
    }

    public function testTrimmingRemovesLeadingAndTrailing(): void
    {
        $param = new ToolParameter('  spaces_around  ', ['type' => 'string']);

        $this->assertSame('spaces_around', $param->name);
    }

    public function testNewlineInNameIsTrimmed(): void
    {
        $param = new ToolParameter("\nname\n", ['type' => 'string']);

        $this->assertSame('name', $param->name);
    }

    public function testPropertiesCanContainNestedArrays(): void
    {
        $properties = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                ],
            ],
        ];

        $param = new ToolParameter('list', $properties);

        $this->assertIsArray($param->properties['items']['properties']);
    }
}
