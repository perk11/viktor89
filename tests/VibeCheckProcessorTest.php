<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\CompletionResponse;
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

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(VibeCheckProcessor::class);
        $this->assertTrue($reflection->implementsInterface(MessageChainProcessor::class));
    }

    public function testDoesNotDeclareItsOwnTriggeringCommands(): void
    {
        // Command routing is delegated to a wrapping CommandBasedResponderTrigger;
        // the processor itself must not implement GetTriggeringCommandsInterface.
        $reflection = new \ReflectionClass(VibeCheckProcessor::class);
        $this->assertFalse($reflection->implementsInterface(\Perk11\Viktor89\GetTriggeringCommandsInterface::class));
    }

    public function testConstructorTakesMessageRepositoryAndAssistant(): void
    {
        $reflection = new \ReflectionClass(VibeCheckProcessor::class);
        $params = $reflection->getConstructor()->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(MessageRepository::class, $params[0]->getType()->getName());
        $this->assertSame(AssistantInterface::class, $params[1]->getType()->getName());
    }

    public function testRendersBarChartAndVerdictFromFlatJsonCompletion(): void
    {
        // Legacy flat schema (axes at top level) must still parse.
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
        $this->assertStringContainsString('VIBE CHECK', $text);
        $this->assertStringContainsString('на основе 3 сообщений', $text);
        $this->assertStringContainsString('Chaos      ███████░░░ 7', $text);
        $this->assertStringContainsString('Wholesome  ███░░░░░░░ 3', $text);
        $this->assertStringContainsString('Brainrot   █████████░ 9', $text);
        $this->assertStringContainsString('Thirst     ██░░░░░░░░ 2', $text);
        $this->assertStringContainsString('Drama      ████░░░░░░ 4', $text);
        $this->assertStringContainsString('Тут полный хаос и брейнрот, спасите.', $text);
    }

    public function testRendersAllCreativeFieldsFromFullJson(): void
    {
        $assistant = $this->assistantReturning(
            '{"energy":82,"tier":"A","title":"Сертифицированные гоблины","emoji":"🎲🤡🔥",'
            . '"soundtrack":"Psycho Killer — Talking Heads","forecast":"К ночи будет ещё хуже.",'
            . '"haiku":"Чат не спит\\nклава в огне\\nкто-то опять прав",'
            . '"scores":{"chaos":8,"wholesome":2,"brainrot":7,"thirst":1,"drama":5},'
            . '"verdict":"Лёгкий хаос с примесью брейнрота."}',
        );
        $text = $this->runProcessor($this->repoStub(), $assistant, '')->response->messageText;

        // Header carries the model-picked emoji stamp.
        $this->assertStringContainsString('🎲🤡🔥 <b>VIBE CHECK</b>', $text);
        // Title.
        $this->assertStringContainsString('🏷 Сертифицированные гоблины', $text);
        // Energy meter + tier row.
        $this->assertStringContainsString('⚡ Энергия: <code>████████░░</code> 82%', $text);
        $this->assertStringContainsString('🥇 Тир: <b>A</b>', $text);
        // Axis bars come from the nested "scores" object.
        $this->assertStringContainsString('Chaos      ████████░░ 8', $text);
        $this->assertStringContainsString('Brainrot   ███████░░░ 7', $text);
        // Bonus creative fields.
        $this->assertStringContainsString('🎶 Саундтрек: Psycho Killer — Talking Heads', $text);
        $this->assertStringContainsString('🔮 Прогноз: К ночи будет ещё хуже.', $text);
        $this->assertStringContainsString('🎴 <blockquote>', $text);
        $this->assertStringContainsString('💬 Лёгкий хаос с примесью брейнрота.', $text);
    }

    public function testDerivesEnergyAndTierWhenModelOmitsThem(): void
    {
        // No energy/tier in the payload: energy = avg(scores)*10 = 50, tier -> C.
        $text = $this->runProcessor(
            $this->repoStub(),
            $this->assistantReturning('{"scores":{"chaos":5,"wholesome":5,"brainrot":5,"thirst":5,"drama":5},"verdict":"ok"}'),
            '',
        )->response->messageText;

        $this->assertStringContainsString('⚡ Энергия: <code>█████░░░░░</code> 50%', $text);
        $this->assertStringContainsString('🥉 Тир: <b>C</b>', $text);
    }

    public function testFallsBackToEnergyBasedTierWhenTierInvalid(): void
    {
        $text = $this->runProcessor(
            $this->repoStub(),
            $this->assistantReturning('{"energy":95,"tier":"Z","scores":{"chaos":1,"wholesome":1,"brainrot":1,"thirst":1,"drama":1},"verdict":"ok"}'),
            '',
        )->response->messageText;

        $this->assertStringContainsString('🏆 Тир: <b>S</b>', $text);
        $this->assertStringContainsString('95%', $text);
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
