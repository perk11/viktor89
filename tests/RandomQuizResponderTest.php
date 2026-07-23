<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Quiz\RandomQuizResponder::class)]
class RandomQuizResponderTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\RandomQuizResponder::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\RandomQuizResponder::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasConstructorWithQuizRepo(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\RandomQuizResponder::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(\Perk11\Viktor89\Quiz\QuestionRepository::class, $params[0]->getType()->getName());
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\RandomQuizResponder::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }
}
