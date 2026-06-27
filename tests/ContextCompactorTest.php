<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ContextCompactor::class)]
class ContextCompactorTest extends TestCase
{
    // ─── isContextLengthError ────────────────────────────────────────────────

    #[DataProvider('contextLengthErrorProvider')]
    public function testIsContextLengthError(string $label, int $status, array $body, bool $expected): void
    {
        $e = new ErrorException($body, new Response($status));
        $this->assertSame($expected, ContextCompactor::isContextLengthError($e), $label);
    }

    /**
     * @return array<string, array{string, int, array, bool}>
     */
    public static function contextLengthErrorProvider(): array
    {
        return [
            '413 always'            => ['413 is context-length',         413, ['message' => 'x'],                            true],
            '400 context message'   => ['400 "context" in message',     400, ['message' => 'context_length_exceeded'],       true],
            '400 too many tokens'   => ['400 "too many" in message',    400, ['message' => 'too many tokens'],               true],
            '400 exceed in message' => ['400 "exceed" in message',      400, ['message' => 'max_tokens exceeded'],           true],
            '400 request too large' => ['400 "request too large"',      400, ['message' => 'Request too large'],             true],
            '400 maximum in msg'    => ['400 "maximum" in message',     400, ['message' => 'maximum context length'],        true],
            '400 limit in msg'      => ['400 "limit" in message',       400, ['message' => 'token limit reached'],           true],
            '400 token in msg'      => ['400 "token" in message',       400, ['message' => 'token limit'],                   true],
            '400 too long in msg'   => ['400 "too long" in message',    400, ['message' => 'prompt too long'],               true],
            '400 unrelated error'   => ['400 unrelated',               400, ['message' => 'invalid_api_key'],               false],
            '500 server error'      => ['500 is not context-length',    500, ['message' => 'internal error'],                false],
            '429 rate limit'        => ['429 is not context-length',    429, ['message' => 'rate limit'],                    false],
        ];
    }

    // ─── compact ─────────────────────────────────────────────────────────────

    public function testFewerMessagesThanThresholdReturnsUnchanged(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            100, // maxRecentCharacters – well above total serialized length
        );
        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'Hello'],
            ['isUser' => false, 'text' => 'Hi'],
            ['isUser' => true,  'text' => 'How are you?'],
        ]);
        $result = $compactor->compact($ctx);
        $this->assertCount(3, $result->messages);
        $this->assertSame('Hello', $result->messages[0]->text);
    }

    public function testExactlyAtThresholdReturnsUnchanged(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            100, // maxRecentCharacters – 10 x "User: x" = 80 chars total
        );
        $ctx = self::makeContext(array_fill(0, 10, ['isUser' => true, 'text' => 'x']));
        $result = $compactor->compact($ctx);
        $this->assertCount(10, $result->messages);
    }

    public function testCompactionReducesMessageCount(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'compacted summary',
            new NullLogger(),
            135, // maxRecentCharacters – 10 x "User: Recent" (13) = 130; 11th would push to 143, old would push to 164
        );
        $ctx = self::makeContext(
            array_merge(
                array_fill(0, 5, ['isUser' => true, 'text' => 'Old message']),
                array_fill(0, 10, ['isUser' => true, 'text' => 'Recent']),
            ),
        );
        $result = $compactor->compact($ctx);
        // 1 summary message + 10 recent = 11
        $this->assertCount(11, $result->messages);
        $this->assertStringContainsString('compacted summary', $result->messages[0]->text ?? '');
    }

    public function testRecentMessagesPreservedVerbatim(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            95, // maxRecentCharacters – keeps Msg 4-9 (90 chars), Msg 3 would push to 103
        );
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = ['isUser' => $i % 2 === 0, 'text' => "Msg $i"];
        }
        $ctx = self::makeContext($messages);
        $result = $compactor->compact($ctx);
        // Kept: Msg 4-9 (6 recent)
        $this->assertCount(7, $result->messages); // summary + 6 recent
        $this->assertSame('Msg 4', $result->messages[1]->text);
        $this->assertSame('Msg 9', $result->messages[6]->text);
    }

    public function testSystemPromptAndResponseStartPreserved(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            5,
        );
        $ctx = self::makeContext(
            array_merge(
                [['isUser' => true, 'text' => 'old']],
                array_fill(0, 8, ['isUser' => true, 'text' => 'recent']),
            ),
            'system prompt text',
            'response start text',
        );
        $result = $compactor->compact($ctx);
        $this->assertSame('system prompt text', $result->systemPrompt);
        $this->assertSame('response start text', $result->responseStart);
    }

    public function testSummaryMessageIsAssistantMessage(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            3,
        );
        $ctx = self::makeContext(array_fill(0, 6, ['isUser' => false, 'text' => 'x']));
        $result = $compactor->compact($ctx);
        // Summary is generated by the assistant, so it should be an assistant message (isUser = false)
        $this->assertFalse($result->messages[0]->isUser);
    }

    public function testSummaryGeneratorReceivesOldMessagesOnly(): void
    {
        $capturedPrompt = '';
        $compactor = new ContextCompactor(
            function (string $p) use (&$capturedPrompt): string {
                $capturedPrompt = $p;
                return 'summary';
            },
            new NullLogger(),
            45, // maxRecentCharacters – "Keep1-3" = 42 chars, "Old4" would push to 56
        );
        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'Old1'],
            ['isUser' => false, 'text' => 'Old2'],
            ['isUser' => true,  'text' => 'Old3'],
            ['isUser' => false, 'text' => 'Old4'],
            ['isUser' => true,  'text' => 'Keep1'],
            ['isUser' => false, 'text' => 'Keep2'],
            ['isUser' => true,  'text' => 'Keep3'],
        ]);
        $compactor->compact($ctx);
        $this->assertStringContainsString('Old1', $capturedPrompt);
        $this->assertStringContainsString('Old4', $capturedPrompt);
        $this->assertStringNotContainsString('Keep1', $capturedPrompt);
    }

    public function testEmptyMessagesSkippedInSummaryPrompt(): void
    {
        $capturedPrompt = '';
        $compactor = new ContextCompactor(
            function (string $p) use (&$capturedPrompt): string {
                $capturedPrompt = $p;
                return 'summary';
            },
            new NullLogger(),
            3,
        );
        $ctx = self::makeContext([
            ['isUser' => true,  'text' => ''],
            ['isUser' => true,  'text' => ''],
            ['isUser' => true,  'text' => 'Valid'],
            ['isUser' => false, 'text' => 'Reply'],
            ['isUser' => true,  'text' => 'Final'],
            ['isUser' => false, 'text' => 'Recent1'],
            ['isUser' => true,  'text' => 'Recent2'],
        ]);
        $compactor->compact($ctx);
        // With maxRecentMessages=3, old messages = 7-3 = 4 messages ('' '' 'Valid' 'Reply')
        // Empty messages are skipped, so 'Valid' and 'Reply' should appear
        $this->assertStringContainsString('Valid', $capturedPrompt);
        $this->assertStringContainsString('Reply', $capturedPrompt);
        // Empty messages should NOT produce lines like 'User: ' (without text)
        // Only non-empty messages should have lines
        $this->assertStringNotContainsString("User: \n", $capturedPrompt);
        $this->assertStringNotContainsString("Assistant: \n", $capturedPrompt);
    }

    public function testSummaryGeneratorReturnValueInsertedIntoContext(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'CUSTOM_SUMMARY_TEXT',
            new NullLogger(),
            3,
        );
        $ctx = self::makeContext(array_fill(0, 8, ['isUser' => true, 'text' => 'x']));
        $result = $compactor->compact($ctx);
        $this->assertStringContainsString('CUSTOM_SUMMARY_TEXT', $result->messages[0]->text ?? '');
    }

    // ─── tool call handling ──────────────────────────────────────────────────

    public function testToolCallWithoutTextIsIncludedInSummaryPrompt(): void
    {
        $capturedPrompt = '';
        $compactor = new ContextCompactor(
            function (string $p) use (&$capturedPrompt): string {
                $capturedPrompt = $p;
                return 'summary';
            },
            new NullLogger(),
            3,
        );

        $toolMsg = new AssistantContextMessage();
        $toolMsg->isUser = false;
        $toolMsg->text   = '';
        $toolMsg->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('call_1', 'search_web', '{"query":"latest news"}', null),
        ];

        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'Search for news'],
        ]);
        // Insert the tool-call message as the second message
        array_unshift($ctx->messages, $toolMsg);
        // Add more messages so compaction happens
        $ctx->messages[] = self::makeMsg(false, 'Here are the results');
        $ctx->messages[] = self::makeMsg(true,  'Thanks');
        $ctx->messages[] = self::makeMsg(false, 'You are welcome');
        $ctx->messages[] = self::makeMsg(true,  'Keep1');
        $ctx->messages[] = self::makeMsg(false, 'Keep2');
        $ctx->messages[] = self::makeMsg(true,  'Keep3');

        $compactor->compact($ctx);

        $this->assertStringContainsString('search_web', $capturedPrompt);
        $this->assertStringContainsString('latest news', $capturedPrompt);
    }

    public function testToolCallIncludedAlongsideTextInSummaryPrompt(): void
    {
        $capturedPrompt = '';
        $compactor = new ContextCompactor(
            function (string $p) use (&$capturedPrompt): string {
                $capturedPrompt = $p;
                return 'summary';
            },
            new NullLogger(),
            3,
        );

        $msgWithToolAndText = new AssistantContextMessage();
        $msgWithToolAndText->isUser = false;
        $msgWithToolAndText->text   = 'Let me look that up for you';
        $msgWithToolAndText->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('call_2', 'calculator', '{"expression":"2+2"}', null),
        ];

        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'What is 2+2?'],
        ]);
        array_unshift($ctx->messages, $msgWithToolAndText);
        $ctx->messages[] = self::makeMsg(true,  'Tell me more');
        $ctx->messages[] = self::makeMsg(false, 'Sure');
        $ctx->messages[] = self::makeMsg(true,  'Keep1');
        $ctx->messages[] = self::makeMsg(false, 'Keep2');
        $ctx->messages[] = self::makeMsg(true,  'Keep3');

        $compactor->compact($ctx);

        $this->assertStringContainsString('calculator', $capturedPrompt);
        $this->assertStringContainsString('2+2', $capturedPrompt);
        $this->assertStringContainsString('Let me look that up for you', $capturedPrompt);
    }

    public function testToolCallResultIncludedInSummaryPrompt(): void
    {
        $capturedPrompt = '';
        $compactor = new ContextCompactor(
            function (string $p) use (&$capturedPrompt): string {
                $capturedPrompt = $p;
                return 'summary';
            },
            new NullLogger(),
            3,
        );

        $toolMsg = new AssistantContextMessage();
        $toolMsg->isUser = false;
        $toolMsg->text   = '';
        $toolMsg->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall(
                'call_3',
                'search_web',
                '{"query":"weather"}',
                '{"temperature": 25, "conditions": "sunny"}',
            ),
        ];

        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'What is the weather?'],
        ]);
        array_unshift($ctx->messages, $toolMsg);
        $ctx->messages[] = self::makeMsg(false, 'The weather is sunny');
        $ctx->messages[] = self::makeMsg(true,  'Keep1');
        $ctx->messages[] = self::makeMsg(false, 'Keep2');
        $ctx->messages[] = self::makeMsg(true,  'Keep3');

        $compactor->compact($ctx);

        $this->assertStringContainsString('search_web', $capturedPrompt);
        $this->assertStringContainsString('weather', $capturedPrompt);
        $this->assertStringContainsString('sunny', $capturedPrompt);
    }

    public function testToolCallInRecentMessagePreservedAfterCompaction(): void
    {
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            137, // maxRecentCharacters – Recent5(15)+Recent4(19)+Recent3(15)+toolMsg(88)=137; Old4(15) would exceed
        );

        $toolMsg = new AssistantContextMessage();
        $toolMsg->isUser = false;
        $toolMsg->text   = 'Tool result: 42';
        $toolMsg->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('call_4', 'calculator', '{"expression":"6*7"}', '42'),
        ];

        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'Old1'],
            ['isUser' => false, 'text' => 'Old2'],
            ['isUser' => true,  'text' => 'Old3'],
            ['isUser' => false, 'text' => 'Old4'],
        ]);
        // Add the tool-call message as a recent message
        $ctx->messages[] = $toolMsg;
        $ctx->messages[] = self::makeMsg(true,  'Recent3');
        $ctx->messages[] = self::makeMsg(false, 'Recent4');
        $ctx->messages[] = self::makeMsg(true,  'Recent5');

        $result = $compactor->compact($ctx);

        // Result: summary + toolMsg + Recent3 + Recent4 + Recent5 = 5 messages
        // toolMsg is at index 1
        $keptToolMsg = $result->messages[count($result->messages) - 4];
        $this->assertCount(1, $keptToolMsg->toolCalls);
        $this->assertSame('calculator', $keptToolMsg->toolCalls[0]->name);
        $this->assertSame('42', $keptToolMsg->toolCalls[0]->result);
        $this->assertSame('Tool result: 42', $keptToolMsg->text);
    }

    public function testMultipleToolCallsInOneMessageIncludedInSummaryPrompt(): void
    {
        $capturedPrompt = '';
        $compactor = new ContextCompactor(
            function (string $p) use (&$capturedPrompt): string {
                $capturedPrompt = $p;
                return 'summary';
            },
            new NullLogger(),
            3,
        );

        $toolMsg = new AssistantContextMessage();
        $toolMsg->isUser = false;
        $toolMsg->text   = '';
        $toolMsg->toolCalls = [
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('c1', 'search_web', '{"query":"news"}', 'news results'),
            new \Perk11\Viktor89\Assistant\Tool\ToolCall('c2', 'calculator', '{"expression":"1+1"}', '2'),
        ];

        $ctx = self::makeContext([
            ['isUser' => true,  'text' => 'Give me news and calculate 1+1'],
        ]);
        array_unshift($ctx->messages, $toolMsg);
        $ctx->messages[] = self::makeMsg(false, 'Here you go');
        $ctx->messages[] = self::makeMsg(true,  'Keep1');
        $ctx->messages[] = self::makeMsg(false, 'Keep2');
        $ctx->messages[] = self::makeMsg(true,  'Keep3');

        $compactor->compact($ctx);

        $this->assertStringContainsString('search_web', $capturedPrompt);
        $this->assertStringContainsString('calculator', $capturedPrompt);
        $this->assertStringContainsString('news results', $capturedPrompt);
        $this->assertStringContainsString('1+1', $capturedPrompt);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private static function makeMsg(bool $isUser, string $text): AssistantContextMessage
    {
        $msg = new AssistantContextMessage();
        $msg->isUser = $isUser;
        $msg->text   = $text;
        return $msg;
    }

    /**
     * @param array<array{isUser: bool, text: string}> $messages
     */
    private static function makeContext(
        array $messages,
        ?string $systemPrompt = null,
        ?string $responseStart = null,
    ): AssistantContext {
        $ctx = new AssistantContext();
        $ctx->systemPrompt  = $systemPrompt;
        $ctx->responseStart = $responseStart;
        foreach ($messages as $m) {
            if ($m instanceof AssistantContextMessage) {
                $ctx->messages[] = $m;
            } else {
                $msg = new AssistantContextMessage();
                $msg->isUser = $m['isUser'];
                $msg->text   = $m['text'] ?? '';
                $ctx->messages[] = $msg;
            }
        }
        return $ctx;
    }
}
