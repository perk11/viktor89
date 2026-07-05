<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolParameter::class)]
class ToolParameterTest extends TestCase
{
    public function testConstructorWithValidParameters(): void
    {
        $param = new ToolParameter('prompt', ['type' => 'string', 'description' => 'Input text'], true);

        $this->assertSame('prompt', $param->name);
        $this->assertSame(['type' => 'string', 'description' => 'Input text'], $param->properties);
        $this->assertTrue($param->required);
    }

    public function testConstructorTrimsName(): void
    {
        $param = new ToolParameter('  name  ', ['type' => 'string']);

        $this->assertSame('name', $param->name);
    }

    public function testConstructorRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter name cannot be empty.');

        new ToolParameter('', ['type' => 'string']);
    }

    public function testConstructorRejectsWhitespaceOnlyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter name cannot be empty.');

        new ToolParameter('   ', ['type' => 'string']);
    }

    public function testConstructorRejectsEmptyProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Properties definition cannot be empty.');

        new ToolParameter('valid_name', []);
    }

    public function testConstructorDefaultRequiredIsFalse(): void
    {
        $param = new ToolParameter('optional', ['type' => 'string']);

        $this->assertFalse($param->required);
    }

    public function testNameIsPrivateSet(): void
    {
        // name has private(set) so it can only be mutated from within the class
        $param = new ToolParameter('test_name', ['type' => 'string']);
        $this->assertSame('test_name', $param->name);
    }

    public function testRequiredIsReadonly(): void
    {
        // required is readonly so it cannot be changed after construction
        $param = new ToolParameter('field', ['type' => 'string'], true);
        $this->assertTrue($param->required);
    }

    public function testPropertiesAreReadonly(): void
    {
        $param = new ToolParameter('field', ['type' => 'string']);

        $this->expectException(\Error::class);

        $param->properties = [];
    }
}
