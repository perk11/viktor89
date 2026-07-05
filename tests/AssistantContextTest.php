<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\OpenAiContextParsingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantContext::class)]
class AssistantContextTest extends TestCase
{
    public function testFromOpenAiMessagesJsonWithUserAndAssistant(): void
    {
        $json = json_encode([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ]);

        $context = AssistantContext::fromOpenAiMessagesJson($json);

        $this->assertCount(2, $context->messages);
        $this->assertTrue($context->messages[0]->isUser);
        $this->assertFalse($context->messages[1]->isUser);
        $this->assertSame('Hello', $context->messages[0]->text);
        $this->assertSame('Hi there!', $context->messages[1]->text);
    }

    public function testFromOpenAiMessagesJsonWithSystemPrompt(): void
    {
        $json = json_encode([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $context = AssistantContext::fromOpenAiMessagesJson($json);

        $this->assertSame('You are helpful.', $context->systemPrompt);
        $this->assertCount(1, $context->messages);
        $this->assertTrue($context->messages[0]->isUser);
    }

    public function testFromOpenAiMessagesJsonWithoutRole(): void
    {
        $json = json_encode([
            ['content' => 'Hello without role'],
        ]);

        $context = AssistantContext::fromOpenAiMessagesJson($json);

        $this->assertCount(1, $context->messages);
        $this->assertTrue($context->messages[0]->isUser); // defaults to user
    }

    public function testFromOpenAiMessagesJsonRejectsInvalidJson(): void
    {
        $this->expectException(OpenAiContextParsingException::class);

        AssistantContext::fromOpenAiMessagesJson('not json');
    }

    public function testFromOpenAiMessagesJsonRejectsNonArrayItems(): void
    {
        $this->expectException(OpenAiContextParsingException::class);

        AssistantContext::fromOpenAiMessagesJson(json_encode(['string']));
    }

    public function testFromOpenAiMessagesJsonRejectsMissingContent(): void
    {
        $this->expectException(OpenAiContextParsingException::class);

        AssistantContext::fromOpenAiMessagesJson(json_encode([['role' => 'user']]));
    }

    public function testFromOpenAiMessagesJsonRejectsUnknownRole(): void
    {
        $this->expectException(OpenAiContextParsingException::class);

        AssistantContext::fromOpenAiMessagesJson(json_encode([
            ['role' => 'unknown_role', 'content' => 'test'],
        ]));
    }

    public function testToOpenAiMessagesArray(): void
    {
        $context = new AssistantContext();
        $context->systemPrompt = 'System prompt';

        $msg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $msg->isUser = true;
        $msg->text = 'User message';
        $context->messages[] = $msg;

        $array = $context->toOpenAiMessagesArray();

        $this->assertCount(2, $array);
        $this->assertSame('system', $array[0]['role']);
        $this->assertSame('System prompt', $array[0]['content']);
        $this->assertSame('user', $array[1]['role']);
        $this->assertSame('User message', $array[1]['content']);
    }

    public function testToOpenAiMessagesArrayWithResponseStartThrowsException(): void
    {
        $context = new AssistantContext();
        $context->responseStart = 'partial response';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('responseStart specified, but it can not be converted to OpenAi array');

        $context->toOpenAiMessagesArray();
    }

    public function testToOpenAiMessagesArrayWithoutSystemPrompt(): void
    {
        $context = new AssistantContext();
        $msg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $msg->isUser = true;
        $msg->text = 'Hello';
        $context->messages[] = $msg;

        $array = $context->toOpenAiMessagesArray();

        $this->assertCount(1, $array);
        $this->assertSame('user', $array[0]['role']);
    }

    public function testConstructorDefaults(): void
    {
        $context = new AssistantContext();

        $this->assertNull($context->systemPrompt);
        $this->assertNull($context->responseStart);
        $this->assertSame([], $context->messages);
    }
}
