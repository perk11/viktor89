<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantContext::class)]
class AssistantContextTest extends TestCase
{
   public function testConstructorDefaults(): void
   {
       $context = new AssistantContext();

       $this->assertNull($context->systemPrompt);
       $this->assertNull($context->responseStart);
       $this->assertSame([], $context->messages);
   }

    public function testDescribeForLogIncludesRolesAndPreviews(): void
    {
        $context = new AssistantContext();
        $context->systemPrompt = 'be helpful';
        $context->responseStart = 'Sure,';

        $u = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $u->isUser = true;
        $u->text = 'Hello there';
        $context->messages[] = $u;

        $a = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $a->isUser = false;
        $a->text = 'Hi!';
        $a->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('call_1', 'search', '{}', 'r'),
        ];
        $context->messages[] = $a;

        $log = $context->describeForLog();

        $this->assertStringContainsString('system: be helpful', $log);
        $this->assertStringContainsString('responseStart: Sure,', $log);
        $this->assertStringContainsString('#0 user: Hello there', $log);
        $this->assertStringContainsString('#1 assistant: Hi! [tool_calls: search]', $log);
    }
}
