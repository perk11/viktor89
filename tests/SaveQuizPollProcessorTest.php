<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor::class)]
class SaveQuizPollProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsPreResponseProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor::class);
        $interfaces = $reflection->getInterfaceNames();
        $this->assertContains(\Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor::class, $interfaces);
    }

    public function testHasProcessMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor::class);
        $method = $reflection->getMethod('process');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Longman\TelegramBot\Entities\Message::class, $params[0]->getType()->getName());
    }

    public function testConstructorTakesQuestionRepository(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Perk11\Viktor89\Quiz\QuestionRepository::class, $params[0]->getType()->getName());
    }
}
