<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\ComplimentProcessor;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\RoastProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoastProcessor::class)]
#[CoversClass(ComplimentProcessor::class)]
#[CoversClass(\Perk11\Viktor89\AbstractUserHistoryBasedResponder::class)]
class RoastComplimentProcessorTest extends TestCase
{
    private Database $database;
    private MessageRepository $repository;
    private const DB_NAME = 'test_roast_compliment.db';

    protected function setUp(): void
    {
        $this->database = new Database(123, self::DB_NAME);
        $this->repository = new MessageRepository($this->database);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/' . self::DB_NAME;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testRoastTargetsRepliedUserAndOnlyUsesTheirMessages(): void
    {
        $this->logMessage(1, -100, 500, 'Alice', 'I love knitting socks');
        $this->logMessage(2, -100, 600, 'Bob', 'crypto will moon tomorrow');

        $captured = $this->createCapturingAssistant($context);
        $processor = new RoastProcessor($this->repository, $captured, logger: new \Psr\Log\NullLogger());

        $repliedTo = $this->buildMessage(10, -100, 500, 'Alice', '');
        $command = $this->buildMessage(11, -100, 999, 'Caller', 'savage');
        $result = $processor->processMessageChain(new MessageChain([$repliedTo, $command]), $this->mockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('savage', $result->response->messageText);
        // Transcript is built from the replied-to user (Alice) only.
        $this->assertNotNull($context);
        $this->assertStringContainsString('knitting', $context->messages[0]->text);
        $this->assertStringNotContainsString('crypto', $context->messages[0]->text);
        // Savage heat shapes the system prompt.
        $this->assertStringContainsString('brutal', $context->systemPrompt);
    }

    public function testRoastDefaultsToSelfWhenNotAReply(): void
    {
        $this->logMessage(1, -100, 999, 'Caller', 'self authored this');

        $captured = $this->createCapturingAssistant($context);
        $processor = new RoastProcessor($this->repository, $captured, logger: new \Psr\Log\NullLogger());

        $command = $this->buildMessage(11, -100, 999, 'Caller', '');
        $processor->processMessageChain(new MessageChain([$command]), $this->mockCallback());

        $this->assertNotNull($context);
        $this->assertStringContainsString('self authored this', $context->messages[0]->text);
        // No heat argument => medium prompt.
        $this->assertStringContainsString('funny and clever', $context->systemPrompt);
    }

    public function testRoastMildHeat(): void
    {
        $this->logMessage(1, -100, 500, 'Alice', 'hello');

        $captured = $this->createCapturingAssistant($context);
        $processor = new RoastProcessor($this->repository, $captured, logger: new \Psr\Log\NullLogger());

        $repliedTo = $this->buildMessage(10, -100, 500, 'Alice', '');
        $command = $this->buildMessage(11, -100, 999, 'Caller', 'mild');
        $result = $processor->processMessageChain(new MessageChain([$repliedTo, $command]), $this->mockCallback());

        $this->assertNotNull($context);
        $this->assertStringContainsString('gentle', $context->systemPrompt);
        $this->assertStringContainsString('mild', $result->response->messageText);
    }

    public function testRoastUnknownHeatFallsBackToMedium(): void
    {
        $this->logMessage(1, -100, 500, 'Alice', 'hello');

        $captured = $this->createCapturingAssistant($context);
        $processor = new RoastProcessor($this->repository, $captured, logger: new \Psr\Log\NullLogger());

        $repliedTo = $this->buildMessage(10, -100, 500, 'Alice', '');
        $command = $this->buildMessage(11, -100, 999, 'Caller', 'banana');
        $result = $processor->processMessageChain(new MessageChain([$repliedTo, $command]), $this->mockCallback());

        $this->assertStringContainsString('funny and clever', $context->systemPrompt);
        $this->assertStringContainsString('medium', $result->response->messageText);
    }

    public function testNoMessagesReturnsNoMessagesMessageWithoutCallingAssistant(): void
    {
        $assistant = $this->createMock(AssistantInterface::class);
        $assistant->expects($this->never())->method('getCompletionBasedOnContext');

        $processor = new RoastProcessor($this->repository, $assistant, logger: new \Psr\Log\NullLogger());

        $command = $this->buildMessage(11, -100, 404, 'Ghost', '');
        $result = $processor->processMessageChain(new MessageChain([$command]), $this->mockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertSame('🤷 Не нашёл сообщений от Ghost — не из чего готовить рост.', $result->response->messageText);
    }

    public function testAssistantFailureReturnsFailureMessage(): void
    {
        $this->logMessage(1, -100, 500, 'Alice', 'hello');

        $assistant = $this->createMock(AssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willThrowException(new \Exception('boom'));

        $processor = new RoastProcessor($this->repository, $assistant, logger: new \Psr\Log\NullLogger());

        $command = $this->buildMessage(11, -100, 500, 'Alice', '');
        $result = $processor->processMessageChain(new MessageChain([$command]), $this->mockCallback());

        $this->assertSame('🤔 Не получилось сочинить рост для Alice, попробуйте ещё раз.', $result->response->messageText);
    }

    public function testComplimentAnalyzesUpTo500Messages(): void
    {
        // 150 messages for the target.
        for ($i = 1; $i <= 150; $i++) {
            $this->logMessage($i, -100, 500, 'Alice', "alice msg $i");
        }

        $captured = $this->createCapturingAssistant($context);
        $processor = new ComplimentProcessor($this->repository, $captured, logger: new \Psr\Log\NullLogger());

        $command = $this->buildMessage(200, -100, 500, 'Alice', '');
        $processor->processMessageChain(new MessageChain([$command]), $this->mockCallback());

        $this->assertNotNull($context);
        // All 150 fit; compliment limit is 500 so none are dropped.
        $lines = array_filter(explode("\n", $context->messages[0]->text), fn (string $l) => $l !== '' && $l !== 'Recent messages written by Alice (newest last):');
        $this->assertCount(150, $lines);
        // Compliment ignores any arguments.
        $this->assertStringContainsString('sincere', $context->systemPrompt);
    }

    public function testRoastCapsAt100Messages(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->logMessage($i, -100, 500, 'Alice', "alice msg $i");
        }

        $captured = $this->createCapturingAssistant($context);
        $processor = new RoastProcessor($this->repository, $captured, logger: new \Psr\Log\NullLogger());

        $command = $this->buildMessage(200, -100, 500, 'Alice', '');
        $processor->processMessageChain(new MessageChain([$command]), $this->mockCallback());

        $this->assertNotNull($context);
        $lines = array_filter(explode("\n", $context->messages[0]->text), fn (string $l) => $l !== '' && $l !== 'Recent messages written by Alice (newest last):');
        $this->assertCount(100, $lines);
        // Only the most recent 100 (ids 51..150) should be present.
        $this->assertStringContainsString('alice msg 150', $context->messages[0]->text);
        $this->assertStringNotContainsString('alice msg 50', $context->messages[0]->text);
    }

    private function mockCallback(): ProgressUpdateCallback
    {
        return $this->createMock(ProgressUpdateCallback::class);
    }

    /**
     * A mock assistant that records the context it was called with via the
     * by-reference variable and returns a canned completion.
     */
    private function createCapturingAssistant(?AssistantContext &$captured): AssistantInterface
    {
        $assistant = $this->createMock(AssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')
            ->willReturnCallback(function (AssistantContext $context) use (&$captured): CompletionResponse {
                $captured = $context;

                return new CompletionResponse('canned response');
            });

        return $assistant;
    }

    private function buildMessage(int $id, int $chatId, int $userId, string $userName, string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = $id;
        $message->chatId = $chatId;
        $message->userId = $userId;
        $message->userName = $userName;
        $message->messageText = $text;
        $message->type = 'text';

        return $message;
    }

    private function logMessage(int $id, int $chatId, int $userId, string $userName, string $text): void
    {
        $message = new InternalMessage();
        $message->id = $id;
        $message->chatId = $chatId;
        $message->userId = $userId;
        $message->userName = $userName;
        $message->messageText = $text;
        $message->type = 'text';
        $message->date = $id;

        $this->repository->logInternalMessage($message);
    }
}
