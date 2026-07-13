<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\GetTriggeringCommandsInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\VibeCheckProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VibeCheckProcessor::class)]
class VibeCheckProcessorTest extends TestCase
{
    public function testIsConcreteClass(): void
    {
        $reflection = new \ReflectionClass(VibeCheckProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsProcessorAndTriggerInterfaces(): void
    {
        $reflection = new \ReflectionClass(VibeCheckProcessor::class);
        $this->assertTrue($reflection->implementsInterface(MessageChainProcessor::class));
        $this->assertTrue($reflection->implementsInterface(GetTriggeringCommandsInterface::class));
    }

    public function testTriggeringCommandIsVibecheck(): void
    {
        $processor = new VibeCheckProcessor($this->createStub(MessageRepository::class), $this->createStub(AssistantInterface::class));
        $this->assertSame(['/vibecheck'], $processor->getTriggeringCommands());
    }

    public function testConstructorTakesMessageRepositoryAndAssistant(): void
    {
        $reflection = new \ReflectionClass(VibeCheckProcessor::class);
        $params = $reflection->getConstructor()->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(MessageRepository::class, $params[0]->getType()->getName());
        $this->assertSame(AssistantInterface::class, $params[1]->getType()->getName());
    }

    public function testRendersBarChartAndVerdictFromJsonCompletion(): void
    {
        $repo = $this->repoExpectingLimit(50);
        $assistant = $this->assistantReturning(
            '{"chaos":7,"wholesome":3,"brainrot":9,"thirst":2,"drama":4,"verdict":"Тут полный хаос и брейнрот, спасите."}',
        );
        $result = $this->runProcessor($repo, $assistant, '');

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertSame('HTML', $result->response->parseMode);
        $this->assertSame(42, $result->response->replyToMessageId);

        $text = $result->response->messageText;
        $this->assertStringContainsString('Vibe check', $text);
        $this->assertStringContainsString('на основе 3 сообщений', $text);
        $this->assertStringContainsString('Chaos      ███████░░░ 7', $text);
        $this->assertStringContainsString('Wholesome  ███░░░░░░░ 3', $text);
        $this->assertStringContainsString('Brainrot   █████████░ 9', $text);
        $this->assertStringContainsString('Thirst     ██░░░░░░░░ 2', $text);
        $this->assertStringContainsString('Drama      ████░░░░░░ 4', $text);
        $this->assertStringContainsString('Тут полный хаос и брейнрот, спасите.', $text);
    }

    public function testAcceptsNumericArgumentAndPassesItAsLimit(): void
    {
        $repo = $this->repoExpectingLimit(120);
        $result = $this->runProcessor($repo, $this->assistantReturningJson(), '120');
        $this->assertNotNull($result->response);
    }

    public function testClampsArgumentToMaximum(): void
    {
        $this->runProcessor($this->repoExpectingLimit(200), $this->assistantReturningJson(), '99999');
    }

    public function testIgnoresNonNumericArgumentAndUsesDefault(): void
    {
        $this->runProcessor($this->repoExpectingLimit(50), $this->assistantReturningJson(), 'banana');
    }

    public function testParsesJsonWrappedInCodeFences(): void
    {
        $assistant = $this->assistantReturning(
            "Here you go:\n```json\n{\"chaos\":5,\"wholesome\":5,\"brainrot\":5,\"thirst\":5,\"drama\":5,\"verdict\":\"Норм.\"}\n```\n",
        );
        $result = $this->runProcessor($this->repoStub(), $assistant, '');
        $this->assertStringContainsString('█████░░░░░ 5', $result->response->messageText);
        $this->assertStringContainsString('Норм.', $result->response->messageText);
    }

    public function testFallsBackToRawTextWhenCompletionIsNotJson(): void
    {
        $assistant = $this->assistantReturning('Просто сплошной <b>хаос</b> & немного драмы.');
        $result = $this->runProcessor($this->repoStub(), $assistant, '');
        $this->assertStringNotContainsString('░', $result->response->messageText);
        // HTML special chars from the model must be escaped, not rendered as markup.
        $this->assertStringContainsString('&lt;b&gt;хаос&lt;/b&gt;', $result->response->messageText);
        $this->assertStringContainsString('&amp;', $result->response->messageText);
    }

    public function testEscapesHtmlInVerdict(): void
    {
        $assistant = $this->assistantReturning(
            '{"chaos":1,"wholesome":1,"brainrot":1,"thirst":1,"drama":1,"verdict":"5 < 10 & > 0"}',
        );
        $result = $this->runProcessor($this->repoStub(), $assistant, '');
        $this->assertStringContainsString('5 &lt; 10 &amp; &gt; 0', $result->response->messageText);
    }

    public function testReturnsQuietMessageWhenNoTextInHistory(): void
    {
        $repo = $this->repoReturning([$this->message(''), $this->message('   ')]);
        // No assistant should be consulted when there is nothing to analyze.
        $result = $this->runProcessor($repo, $this->createStub(AssistantInterface::class), '');
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('тихо', $result->response->messageText);
    }

    public function testReturnsApologyMessageWhenAssistantThrows(): void
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willThrowException(new \RuntimeException('boom'));
        $result = $this->runProcessor($this->repoStub(), $assistant, '');
        $this->assertStringContainsString('Не получилось', $result->response->messageText);
    }

    private function runProcessor(MessageRepository $repo, AssistantInterface $assistant, string $argument): ProcessingResult
    {
        $trigger = $this->message($argument);
        $trigger->chatId = -100;
        $trigger->id = 42;
        $trigger->userId = 7;
        $trigger->userName = 'Asker';

        $processor = new VibeCheckProcessor($repo, $assistant);

        return $processor->processMessageChain(new MessageChain([$trigger]), $this->createStub(ProgressUpdateCallback::class));
    }

    private function assistantReturning(string $completion): AssistantInterface
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willReturn(new CompletionResponse($completion));

        return $assistant;
    }

    private function assistantReturningJson(): AssistantInterface
    {
        return $this->assistantReturning('{"chaos":1,"wholesome":1,"brainrot":1,"thirst":1,"drama":1,"verdict":"ok"}');
    }

    private function repoStub(): MessageRepository
    {
        return $this->repoReturning($this->sampleMessages());
    }

    private function repoReturning(array $messages): MessageRepository
    {
        $repo = $this->createStub(MessageRepository::class);
        $repo->method('findNPreviousMessagesInChat')->willReturn($messages);

        return $repo;
    }

    private function repoExpectingLimit(int $limit): MessageRepository
    {
        $repo = $this->createMock(MessageRepository::class);
        $repo->expects($this->once())
            ->method('findNPreviousMessagesInChat')
            ->with(-100, 42, $limit, [])
            ->willReturn($this->sampleMessages());

        return $repo;
    }

    private function sampleMessages(): array
    {
        return [
            $this->message('нормально'),
            $this->message('а вы как?'),
            $this->message('просто чиллю'),
        ];
    }

    private function message(string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->messageText = $text;
        $message->userName = 'User' . random_int(1, 1000);
        $message->userId = random_int(1, 1000);

        return $message;
    }
}
