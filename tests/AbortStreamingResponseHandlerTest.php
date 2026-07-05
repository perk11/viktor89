<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\AbortStreamingResponse\AbortStreamingResponseHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbortStreamingResponseHandler::class)]
class AbortStreamingResponseHandlerTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(AbortStreamingResponseHandler::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGetNewResponseMethod(): void
    {
        $reflection = new \ReflectionClass(AbortStreamingResponseHandler::class);
        $method = $reflection->getMethod('getNewResponse');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodParameters(): void
    {
        $reflection = new \ReflectionClass(AbortStreamingResponseHandler::class);
        $method = $reflection->getMethod('getNewResponse');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('prompt', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('currentResponse', $params[1]->getName());
        $this->assertSame('string', $params[1]->getType()->getName());
    }

    public function testMethodReturnTypeIsStringOrFalse(): void
    {
        $reflection = new \ReflectionClass(AbortStreamingResponseHandler::class);
        $method = $reflection->getMethod('getNewResponse');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $types = $returnType->getTypes();
        $this->assertCount(2, $types);
        $typeNames = array_map(fn($t) => $t->getName(), $types);
        $this->assertContains('string', $typeNames);
        $this->assertContains('false', $typeNames);
    }

    public function testConcreteImplementations(): void
    {
        $reflection = new \ReflectionClass(AbortStreamingResponseHandler::class);

        $implementations = [
            \Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler::class,
            \Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler::class,
            \Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler::class,
        ];

        foreach ($implementations as $className) {
            $classReflection = new \ReflectionClass($className);
            $this->assertTrue(
                $classReflection->implementsInterface(AbortStreamingResponseHandler::class),
                "$className should implement AbortStreamingResponseHandler"
            );
        }
    }
}
