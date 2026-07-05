<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\PreResponseProcessor\PreResponseSupportingGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreResponseSupportingGenerator::class)]
class PreResponseSupportingGeneratorTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(PreResponseSupportingGenerator::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasAddPreResponseProcessorMethod(): void
    {
        $reflection = new \ReflectionClass(PreResponseSupportingGenerator::class);
        $method = $reflection->getMethod('addPreResponseProcessor');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodTakesMessageChainProcessorParameter(): void
    {
        $reflection = new \ReflectionClass(PreResponseSupportingGenerator::class);
        $method = $reflection->getMethod('addPreResponseProcessor');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(MessageChainProcessor::class, $params[0]->getType()->getName());
    }

    public function testMethodHasVoidReturn(): void
    {
        $reflection = new \ReflectionClass(PreResponseSupportingGenerator::class);
        $method = $reflection->getMethod('addPreResponseProcessor');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }
}
