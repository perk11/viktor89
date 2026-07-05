<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreResponseProcessor::class)]
class PreResponseProcessorTest extends TestCase
{
    public function testInterfaceHasProcessMethod(): void
    {
        $reflection = new \ReflectionClass(PreResponseProcessor::class);
        $method = $reflection->getMethod('process');

        $this->assertTrue($method->isAbstract());
    }

    public function testInterfaceReturnTypes(): void
    {
        $reflection = new \ReflectionClass(PreResponseProcessor::class);
        $method = $reflection->getMethod('process');
        $returnType = $method->getReturnType();

        // Should return false|string|null
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testConcreteImplementations(): void
    {
        // RateLimitProcessor is a known implementation of PreResponseProcessor
        $className = \Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class;
        $this->assertTrue(class_exists($className), "$className should exist");

        $classReflection = new \ReflectionClass($className);
        $this->assertTrue(
            $classReflection->implementsInterface(PreResponseProcessor::class),
            "$className should implement PreResponseProcessor"
        );
    }

    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(PreResponseProcessor::class);
        $this->assertTrue($reflection->isInterface());
    }
}
