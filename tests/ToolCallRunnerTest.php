<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\ResponseContentAccumulator;
use Perk11\Viktor89\Assistant\Tool\ToolArgumentException;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Assistant\Tool\ToolCallContext;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunner;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ProgressReportingToolCallExecutorInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Test\Support\RecordingProgressUpdateCallback;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

require_once __DIR__ . '/Support/AssistantTestFixtures.php';

#[CoversClass(ToolCallRunner::class)]
class ToolCallRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    public function testInternalExceptionIsReplacedByErrorIdAndLogged(): void
    {
        $secret = 'https://api.telegram.org/file/bot123:SECRET/videos/file_1.mp4';
        $executor = $this->createStub(ToolCallExecutorInterface::class);
        $executor->method('executeToolCall')->willThrowException(
            new \RuntimeException("Client error: `GET $secret` resulted in a `404 Not Found`"),
        );

        [$result, $accumulator] = $this->runTool($executor);

        $this->assertTrue($result->failed);
        $shown = $accumulator->telegramDisplayedContent . $result->toolCall->result;
        $this->assertStringNotContainsString('SECRET', $shown);
        $this->assertStringNotContainsString('api.telegram.org', $shown);
        $this->assertMatchesRegularExpression('/error id ([0-9a-f]{8})/', $accumulator->telegramDisplayedContent);
        preg_match('/error id ([0-9a-f]{8})/', $accumulator->telegramDisplayedContent, $matches);
        $this->assertStringContainsString($matches[1], $result->toolCall->result);

        $log = implode("\n", $this->logLines);
        $this->assertStringContainsString($matches[1], $log);
        $this->assertStringContainsString($secret, $log);
        $this->assertStringContainsString('RuntimeException', $log);
    }

    public function testUserFacingExceptionMessageIsShown(): void
    {
        $executor = $this->createStub(ToolCallExecutorInterface::class);
        $executor->method('executeToolCall')->willThrowException(
            new ToolArgumentException('Missing required argument: url'),
        );

        [$result, $accumulator] = $this->runTool($executor);

        $this->assertTrue($result->failed);
        $this->assertStringContainsString('Error in get_url: Missing required argument: url', $accumulator->telegramDisplayedContent);
        $this->assertStringContainsString('Missing required argument: url', $result->toolCall->result);
        $this->assertStringNotContainsString('error id', $accumulator->telegramDisplayedContent);
        $this->assertStringContainsString('Missing required argument: url', implode("\n", $this->logLines));
    }

    /** @return array{\Perk11\Viktor89\Assistant\Tool\ToolCallRunResult, ResponseContentAccumulator} */
    public function testRunningStatusAndChatActionOfTheToolAreReported(): void
    {
        $progress = new RecordingProgressUpdateCallback();
        $definition = new ToolDefinition(
            'generate_voice_xtts',
            $this->createStub(ToolCallExecutorInterface::class),
            'tts',
            runningStatus: '[xtts_v2] Generating audio',
            runningChatAction: ChatActionEnum::record_voice,
        );
        $chain = new MessageChain([self::message(chatId: -100)]);
        $context = new ToolCallContext(new ResponseContentAccumulator(), null, $chain, $progress);

        (new ToolCallRunner($this->createStub(AltTextProvider::class)))
            ->run(new ToolCall('call-1', 'generate_voice_xtts', '{}'), $definition, $context, null);

        $this->assertSame([[ToolCallRunner::class, '[xtts_v2] Generating audio']], $progress->updates);
        $this->assertSame(-100, $progress->chatActions[0]?->chatId);
        $this->assertSame(ChatActionEnum::record_voice, $progress->chatActions[0]?->action);
    }

    public function testProgressReportingToolGetsTheCallsCallback(): void
    {
        $progress = new RecordingProgressUpdateCallback();
        $executor = new class implements ProgressReportingToolCallExecutorInterface {
            public function executeToolCall(array $arguments, MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): array
            {
                $progressUpdateCallback('Generator', '[model-x] Generating image');

                return ['status' => 'ok'];
            }
        };
        $context = new ToolCallContext(new ResponseContentAccumulator(), null, new MessageChain([self::message()]), $progress);

        $result = (new ToolCallRunner($this->createStub(AltTextProvider::class)))
            ->run(new ToolCall('call-1', 'image_gen_tool', '{}'), new ToolDefinition('image_gen_tool', $executor, 'gen'), $context, null);

        $this->assertFalse($result->failed);
        $this->assertSame([
            [ToolCallRunner::class, 'Executing image_gen_tool'],
            ['Generator', '[model-x] Generating image'],
        ], $progress->updates);
    }

    private static function message(int $chatId = 1): InternalMessage
    {
        $message = new InternalMessage();
        $message->chatId = $chatId;
        $message->userId = 1;
        $message->messageText = 'hi';

        return $message;
    }

    private function runTool(ToolCallExecutorInterface $executor): array
    {
        $logLines = &$this->logLines;
        $logger = new class($logLines) extends AbstractLogger {
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = "$level: $message";
            }
        };
        $runner = new ToolCallRunner($this->createStub(AltTextProvider::class), $logger);
        $accumulator = new ResponseContentAccumulator();
        $context = new ToolCallContext($accumulator, null, null, null);
        $definition = new ToolDefinition('get_url', $executor, 'fetch');

        $result = $runner->run(new ToolCall('call-1', 'get_url', '{"url":"http://x"}'), $definition, $context, null);

        return [$result, $accumulator];
    }
}
