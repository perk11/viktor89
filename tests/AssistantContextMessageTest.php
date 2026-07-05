<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantContextMessage::class)]
class AssistantContextMessageTest extends TestCase
{
    public function testDefaultProperties(): void
    {
        $msg = new AssistantContextMessage();

        $this->assertNull($msg->photo);
        $this->assertNull($msg->reasoning);
        $this->assertSame([], $msg->toolCalls);
    }

    public function testIsUserProperty(): void
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = true;
        $msg->text = 'Hello';

        $this->assertTrue($msg->isUser);
        $this->assertSame('Hello', $msg->text);
    }

    public function testAssistantMessage(): void
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = false;
        $msg->text = 'Answer to your question';

        $this->assertFalse($msg->isUser);
        $this->assertSame('Answer to your question', $msg->text);
    }

    public function testWithPhoto(): void
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = true;
        $msg->photo = "\x89PNG\r\n\x1A\n" . str_repeat('x', 50);
        $msg->text = 'Look at this image';

        $this->assertNotNull($msg->photo);
        $this->assertStringStartsWith("\x89PNG", $msg->photo);
    }

    public function testWithReasoning(): void
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = false;
        $msg->reasoning = 'I analyzed the question carefully...';
        $msg->text = 'The answer is 42';

        $this->assertSame('I analyzed the question carefully...', $msg->reasoning);
    }

    public function testWithToolCalls(): void
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = false;
        $msg->toolCalls = [
            new ToolCall('call_1', 'get_url', '{"url": "https://example.com"}'),
            new ToolCall('call_2', 'search', '{"query": "test"}'),
        ];

        $this->assertCount(2, $msg->toolCalls);
        $this->assertSame('get_url', $msg->toolCalls[0]->name);
    }

    public function testWithAllProperties(): void
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = false;
        $msg->text = 'Here is the result';
        $msg->reasoning = 'I processed the request';
        $msg->photo = "\xFF\xD8\xFF" . str_repeat('x', 50);
        $msg->toolCalls = [new ToolCall('call_1', 'generate', '{}')];

        $this->assertFalse($msg->isUser);
        $this->assertSame('Here is the result', $msg->text);
        $this->assertNotNull($msg->reasoning);
        $this->assertNotNull($msg->photo);
        $this->assertCount(1, $msg->toolCalls);
    }

    public function testEmptyTextIsAllowed(): void
    {
        $msg = new AssistantContextMessage();
        $msg->text = '';

        $this->assertSame('', $msg->text);
    }
}
