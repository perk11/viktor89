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

   public function testConsecutiveSameRoleMessagesWithReasoningAreMerged(): void
   {
       $context = new AssistantContext();
       $context->systemPrompt = 'System';

       $msg1 = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $msg1->isUser = false;
       $msg1->text = 'First assistant reply';
       $msg1->reasoning = 'some reasoning';
       $context->messages[] = $msg1;

       $msg2 = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $msg2->isUser = false;
       $msg2->text = 'Second assistant reply';
       $msg2->reasoning = 'more reasoning';
       $context->messages[] = $msg2;

       $array = $context->toOpenAiMessagesArray();

       // system + 1 merged assistant message (not 2 separate ones)
       $this->assertCount(2, $array);
       $this->assertSame('assistant', $array[1]['role']);
       $this->assertStringContainsString('First assistant reply', json_encode($array[1]['content']));
       $this->assertStringContainsString('Second assistant reply', json_encode($array[1]['content']));
   }

   public function testConsecutiveUserMessagesAreMerged(): void
   {
       $context = new AssistantContext();

       $msg1 = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $msg1->isUser = true;
       $msg1->text = 'User A says hi';
       $context->messages[] = $msg1;

       $msg2 = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $msg2->isUser = true;
       $msg2->text = 'User B says hello';
       $context->messages[] = $msg2;

       $array = $context->toOpenAiMessagesArray();

       // Two user messages merged into one
       $this->assertCount(1, $array);
       $this->assertSame('user', $array[0]['role']);
       $this->assertStringContainsString('User A says hi', json_encode($array[0]['content']));
       $this->assertStringContainsString('User B says hello', json_encode($array[0]['content']));
   }

   public function testRolesAlternateAfterMerge(): void
   {
       $context = new AssistantContext();
       $context->systemPrompt = 'System';

       foreach (['user', 'user', 'assistant', 'assistant', 'user'] as $role) {
           $msg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
           $msg->isUser = $role === 'user';
           $msg->text = $role . ' message';
           $context->messages[] = $msg;
       }

       $array = $context->toOpenAiMessagesArray();

       // After merging: system, user(merged), assistant(merged), user
       $this->assertCount(4, $array);
       $roles = array_column($array, 'role');
       $this->assertSame(['system', 'user', 'assistant', 'user'], $roles);
   }

   public function testAssistantAfterToolResultsFoldsIntoToolCallMessage(): void
   {
       $context = new AssistantContext();
       $context->systemPrompt = 'System';

       $userMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $userMsg->isUser = true;
       $userMsg->text = 'Search for cats';
       $context->messages[] = $userMsg;

       $toolMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $toolMsg->isUser = false;
       $toolMsg->text = '';
       $toolMsg->toolCalls = [
           new \Perk11\Viktor89\Assistant\Tool\ToolCall('call_1', 'search', '{"q":"cats"}', 'cats are great'),
       ];
       $context->messages[] = $toolMsg;

       $answerMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $answerMsg->isUser = false;
       $answerMsg->text = 'Here are the search results for cats';
       $context->messages[] = $answerMsg;

       $array = $context->toOpenAiMessagesArray();

       // system, user, assistant(with tool_calls + folded answer text), tool(result)
       // The trailing assistant must NOT create a separate assistant message.
       $roles = array_column($array, 'role');
       $this->assertSame(['system', 'user', 'assistant', 'tool'], $roles);

       // The folded answer text should be in the assistant message's content
       $assistantContent = json_encode($array[2]['content']);
       $this->assertStringContainsString('Here are the search results for cats', $assistantContent);
   }

   /**
     * Reproduces the image-generation scenario that triggered
     * "Jinja Exception: Conversation roles must alternate": the generated
     * photo is logged as one assistant message and the text reply (carrying
     * the tool_calls) as another. These two consecutive assistant messages
     * must be merged so the roles keep alternating.
     */
   public function testConsecutiveAssistantWhereSecondHasToolCallsAreMerged(): void
   {
       $context = new AssistantContext();
       $context->systemPrompt = 'System';

       $userMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $userMsg->isUser = true;
       $userMsg->text = 'Draw a cat';
       $context->messages[] = $userMsg;

       // Assistant message for the separately-logged photo (no tool_calls).
       $photoMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $photoMsg->isUser = false;
       $photoMsg->text = 'Here is the cat you asked for';
       $context->messages[] = $photoMsg;

       // Assistant text reply that carries the tool_calls.
       $replyMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $replyMsg->isUser = false;
       $replyMsg->text = '';
       $replyMsg->toolCalls = [
           new \Perk11\Viktor89\Assistant\Tool\ToolCall(
               'call_1',
               'generate_image',
               '{"prompt":"a cat"}',
               '{"status":"image_generated"}',
           ),
       ];
       $context->messages[] = $replyMsg;

       $array = $context->toOpenAiMessagesArray();

       // system, user, assistant(merged photo text + tool_calls), tool(result)
       $roles = array_column($array, 'role');
       $this->assertSame(['system', 'user', 'assistant', 'tool'], $roles);

       $mergedContent = json_encode($array[2]['content']);
       $this->assertStringContainsString('Here is the cat you asked for', $mergedContent);
       $this->assertArrayHasKey('tool_calls', $array[2]);
   }

   /**
     * A compaction summary is a user-role message. When the kept tail of the
     * conversation also starts with a user message, the two must merge so the
     * compacted request still alternates user/assistant strictly.
     */
   public function testCompactionSummaryMergesWithFollowingUserMessage(): void
   {
       $context = new AssistantContext();
       $context->systemPrompt = 'System';

       $summary = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $summary->isUser = true;
       $summary->text = '[Summary of earlier conversation: user likes cats.]';
       $context->messages[] = $summary;

       $userMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $userMsg->isUser = true;
       $userMsg->text = 'Tell me more about cats';
       $context->messages[] = $userMsg;

       $assistantMsg = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
       $assistantMsg->isUser = false;
       $assistantMsg->text = 'Cats are great';
       $context->messages[] = $assistantMsg;

       $array = $context->toOpenAiMessagesArray();

       $roles = array_column($array, 'role');
       $this->assertSame(['system', 'user', 'assistant'], $roles);

       $mergedContent = json_encode($array[1]['content']);
       $this->assertStringContainsString('user likes cats.', $mergedContent);
       $this->assertStringContainsString('Tell me more about cats', $mergedContent);
   }

    /**
     * A model without tool definitions must not be sent `tool`-role messages or
     * `tool_calls` fields: strict chat templates (Gemma/Llama/Qwen) only know
     * user/assistant and reject the tool role with "Conversation roles must
     * alternate". Tool-call turns from history (e.g. a previous tool-capable
     * model) are emitted as plain assistant text instead.
     */
    public function testToOpenAiMessagesArrayOmitsToolMessagesWhenDisabled(): void
    {
        $context = new AssistantContext();
        $context->systemPrompt = 'System';

        $u = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $u->isUser = true;
        $u->text = 'search for cats';
        $context->messages[] = $u;

        $a = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $a->isUser = false;
        $a->text = 'Let me look that up';
        $a->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('call_1', 'search', '{}', 'cats are great'),
        ];
        $context->messages[] = $a;

        $followUp = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $followUp->isUser = true;
        $followUp->text = 'thanks';
        $context->messages[] = $followUp;

        // With tools (default): assistant carries tool_calls and a tool result follows.
        $withTools = $context->toOpenAiMessagesArray(true);
        $this->assertSame(['system', 'user', 'assistant', 'tool', 'user'], array_column($withTools, 'role'));
        $this->assertArrayHasKey('tool_calls', $withTools[2]);

        // Without tools: no tool role, no tool_calls field, strictly alternating.
        $withoutTools = $context->toOpenAiMessagesArray(false);
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($withoutTools, 'role'));
        $this->assertArrayNotHasKey('tool_calls', $withoutTools[2]);
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

    public function testSummarizeRoleSequence(): void
    {
        $sequence = AssistantContext::summarizeRoleSequence([
            ['role' => 'system', 'content' => 's'],
            ['role' => 'user', 'content' => 'u'],
            ['role' => 'assistant', 'content' => 'a'],
            ['role' => 'tool', 'tool_call_id' => 'x', 'content' => 'r'],
        ]);

        $this->assertSame('system → user → assistant → tool', $sequence);
    }
}
