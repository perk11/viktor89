<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\PersonalityCard\PersonalityCardProcessor;
use Perk11\Viktor89\PersonalityCard\PersonalityCardRenderer;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalityCardProcessor::class)]
class PersonalityCardProcessorTest extends TestCase
{
    public function testIsConcreteClass(): void
    {
        $reflection = new \ReflectionClass(PersonalityCardProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(PersonalityCardProcessor::class);
        $this->assertTrue($reflection->implementsInterface(MessageChainProcessor::class));
    }

    public function testDoesNotDeclareItsOwnTriggeringCommands(): void
    {
        // Command routing is delegated to a wrapping CommandBasedResponderTrigger;
        // the processor itself must not implement GetTriggeringCommandsInterface.
        $reflection = new \ReflectionClass(PersonalityCardProcessor::class);
        $this->assertFalse(
            $reflection->implementsInterface(\Perk11\Viktor89\GetTriggeringCommandsInterface::class),
        );
    }

    public function testConstructorDependencies(): void
    {
        $params = (new \ReflectionClass(PersonalityCardProcessor::class))->getConstructor()->getParameters();
        $this->assertCount(6, $params);
        $this->assertSame(MessageRepository::class, $params[0]->getType()->getName());
        $this->assertSame(AssistantInterface::class, $params[1]->getType()->getName());
        $this->assertSame(ImageByPromptGenerator::class, $params[2]->getType()->getName());
        $this->assertSame(PhotoResponder::class, $params[3]->getType()->getName());
        $this->assertSame(PersonalityCardRenderer::class, $params[4]->getType()->getName());
    }

    public function testReturnsQuietMessageWhenTargetHasNoTextHistory(): void
    {
        $repo = $this->createStub(MessageRepository::class);
        $repo->method('findLastMessagesByUserInChat')->willReturn([$this->message(''), $this->message('   ')]);
        // No assistant should be consulted when there is nothing to read.
        $assistant = $this->createMock(AssistantInterface::class);
        $assistant->expects($this->never())->method('getCompletionBasedOnContext');

        $result = $this->runProcessor($repo, $assistant);

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('нечего показать', $result->response->messageText);
    }

    public function testReturnsThinkingReactionWhenAssistantThrows(): void
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willThrowException(new \RuntimeException('boom'));

        $result = $this->runProcessor($this->repoWithMessages(), $assistant);

        $this->assertTrue($result->abortProcessing);
        $this->assertNull($result->response);
        $this->assertSame('🤔', $result->reaction);
    }

    public function testReturnsThinkingReactionWhenCompletionIsNotParseableJson(): void
    {
        $assistant = $this->assistantReturning('this is definitely not json');

        $result = $this->runProcessor($this->repoWithMessages(), $assistant);

        $this->assertTrue($result->abortProcessing);
        $this->assertNull($result->response);
        $this->assertSame('🤔', $result->reaction);
    }

    public function testBareCommandTargetsTheSender(): void
    {
        $repo = $this->createMock(MessageRepository::class);
        $repo->expects($this->once())
            ->method('findLastMessagesByUserInChat')
            ->with(-100, 7, 500) // chatId, the command sender's userId; fixed MESSAGE_COUNT
            ->willReturn([$this->message('привет')]);

        $result = $this->runProcessor($repo, $this->assistantReturningJson());
        $this->assertNotNull($result);
    }

    public function testReplyTargetsTheRepliedUser(): void
    {
        $repo = $this->createMock(MessageRepository::class);
        $repo->expects($this->once())
            ->method('findLastMessagesByUserInChat')
            ->with(-100, 999, $this->anything())
            ->willReturn([$this->message('хей')]);

        $target = $this->message('сообщение цели');
        $target->id = 5;
        $target->userId = 999;
        $target->userName = 'Цель';

        $command = $this->command('');
        $command->replyToMessageId = 5;

        $processor = $this->buildProcessor($repo, $this->assistantReturningJson());
        $processor->processMessageChain(new MessageChain([$target, $command]), $this->createStub(ProgressUpdateCallback::class));
    }

    public function testHappyPathSendsCardImageAndAborts(): void
    {
        $imageResponse = $this->createStub(Automatic1111ImageApiResponse::class);
        $imageResponse->method('getFirstImageAsPng')->willReturn($this->solidPng(64, 64, 40, 50, 90));
        $imageGenerator = $this->createStub(ImageByPromptGenerator::class);
        $imageGenerator->method('generateImageByPrompt')->willReturn($imageResponse);

        $captured = null;
        $photoResponder = $this->createMock(PhotoResponder::class);
        $photoResponder->expects($this->once())
            ->method('sendPhoto')
            ->with(
                $this->anything(),
                $this->callback(function (string $bytes) use (&$captured): bool {
                    $captured = $bytes;

                    return true;
                }),
                false,
                $this->anything(),
            );

        $processor = new PersonalityCardProcessor(
            $this->repoWithMessages(),
            $this->assistantReturningJson(),
            $imageGenerator,
            $photoResponder,
            new PersonalityCardRenderer(),
         logger: new \Psr\Log\NullLogger());

        $result = $processor->processMessageChain(
            new MessageChain([$this->command('')]),
            $this->createStub(ProgressUpdateCallback::class),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNull($result->response);
        $this->assertNotNull($captured);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($captured, 0, 8));
        $info = getimagesizefromstring($captured);
        $this->assertSame([880, 1180], [$info[0], $info[1]]);
    }

    public function testIgnoresNumericMessageCountArgument(): void
    {
        // The processor reads a fixed MESSAGE_COUNT and no longer parses a count argument.
        $repo = $this->createMock(MessageRepository::class);
        $repo->expects($this->once())
            ->method('findLastMessagesByUserInChat')
            ->with(-100, 7, 500)
            ->willReturn([$this->message('привет')]);

        $this->runProcessor($repo, $this->assistantReturningJson(), '120');
    }

    private function buildProcessor(
        ?MessageRepository $repo = null,
        ?AssistantInterface $assistant = null,
    ): PersonalityCardProcessor {
        return new PersonalityCardProcessor(
            $repo ?? $this->createStub(MessageRepository::class),
            $assistant ?? $this->createStub(AssistantInterface::class),
            $this->createStub(ImageByPromptGenerator::class),
            $this->createStub(PhotoResponder::class),
            new PersonalityCardRenderer(),
         logger: new \Psr\Log\NullLogger());
    }

    private function runProcessor(
        MessageRepository $repo,
        AssistantInterface $assistant,
        string $argument = '',
    ): ProcessingResult {
        $command = $this->command($argument);
        $processor = $this->buildProcessor($repo, $assistant);

        return $processor->processMessageChain(
            new MessageChain([$command]),
            $this->createStub(ProgressUpdateCallback::class),
        );
    }

    private function assistantReturning(string $completion): AssistantInterface
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willReturn(new CompletionResponse($completion));

        return $assistant;
    }

    private function assistantReturningJson(): AssistantInterface
    {
        return $this->assistantReturning(
            '{"wit":7,"chaos":9,"wisdom":5,"menace":8,'
            . '"archetype":"Агент Хаоса","ability":"Мемная Диверсия",'
            . '"abilityEffect":"превращает любой тред в мемологему",'
            . '"specialAbility":"Цепная Реакция",'
            . '"specialAbilityQuote":"просто чиллю",'
            . '"weakness":"серьёзные темы без подвоха ломают весь настрой и выбивают из колеи",'
            . '"portrait":"a smirking rogue in a neon hoodie"}',
        );
    }

    private function repoWithMessages(): MessageRepository
    {
        $repo = $this->createStub(MessageRepository::class);
        $repo->method('findLastMessagesByUserInChat')->willReturn([
            $this->message('нормально'),
            $this->message('а вы как?'),
            $this->message('просто чиллю'),
        ]);

        return $repo;
    }

    private function message(string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->messageText = $text;
        $message->userName = 'User' . random_int(1, 1000);
        $message->userId = random_int(1, 1000);

        return $message;
    }

    private function command(string $argument): InternalMessage
    {
        $message = new InternalMessage();
        $message->messageText = $argument;
        $message->chatId = -100;
        $message->id = 42;
        $message->userId = 7;
        $message->userName = 'Asker';

        return $message;
    }

    private function solidPng(int $w, int $h, int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }
}
