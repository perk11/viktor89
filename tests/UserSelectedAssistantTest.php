<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\UserSelectedAssistant;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserSelectedAssistant::class)]
class UserSelectedAssistantTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(UserSelectedAssistant::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(UserSelectedAssistant::class);
        $this->assertTrue($reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class));
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(UserSelectedAssistant::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testConstructorTakesAssistantFactoryAndPreference(): void
    {
        $reflection = new \ReflectionClass(UserSelectedAssistant::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('assistantFactory', $params[0]->getName());
        $this->assertSame(AssistantFactory::class, $params[0]->getType()->getName());
        $this->assertSame(UserPreferenceReaderInterface::class, $params[1]->getType()->getName());
    }

    public function testUsesSelectedAssistantWhenAllowedInChat(): void
    {
        $factory = $this->buildFactory($this->assistantReturning('SELECTED'), allowed: true);

        $processor = new UserSelectedAssistant($factory, $this->reader('siepatch'));
        $result = $processor->processMessageChain($this->chain(-1001804789551), $this->createCallback());

        $this->assertNotNull($result->response);
        $this->assertSame('SELECTED', $result->response->messageText);
    }

    public function testFallsBackToDefaultWhenSelectedModelNotAllowedInChat(): void
    {
        $selected = $this->assistantReturning('SELECTED-SHOULD-NOT-LEAK');
        $default = $this->assistantReturning('DEFAULT');
        $factory = $this->buildFactory($selected, allowed: false, default: $default);

        $processor = new UserSelectedAssistant($factory, $this->reader('siepatch'));
        $result = $processor->processMessageChain($this->chain(-999), $this->createCallback());

        $this->assertNotNull($result->response);
        $this->assertSame('DEFAULT', $result->response->messageText);
    }

    public function testUsesDefaultWhenNoModelSelected(): void
    {
        $default = $this->assistantReturning('DEFAULT');
        $factory = $this->buildFactory($this->assistantReturning('NEVER'), allowed: true, default: $default);

        $processor = new UserSelectedAssistant($factory, $this->reader(null));
        $result = $processor->processMessageChain($this->chain(-999), $this->createCallback());

        $this->assertSame('DEFAULT', $result->response->messageText);
    }

    private function buildFactory(AssistantInterface $selected, bool $allowed, ?AssistantInterface $default = null): AssistantFactory
    {
        $factory = $this->createStub(AssistantFactory::class);
        $factory->method('getAssistantInstanceByName')->willReturn($selected);
        $factory->method('isModelNameAllowedInChat')->willReturn($allowed);
        $factory->method('getDefaultAssistantInstanceForChat')->willReturn($default ?? $selected);

        return $factory;
    }

    private function assistantReturning(string $text): AssistantInterface
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistant->method('processMessageChain')->willReturn(
            new ProcessingResult(InternalMessage::asResponseTo($this->message(0), $text), true),
        );

        return $assistant;
    }

    private function reader(?string $value): UserPreferenceReaderInterface
    {
        return new class($value) implements UserPreferenceReaderInterface {
            public function __construct(private readonly ?string $value)
            {
            }

            public function getCurrentPreferenceValue(int $userId): ?string
            {
                return $this->value;
            }
        };
    }

    private function chain(int $chatId): MessageChain
    {
        return new MessageChain([$this->message($chatId)]);
    }

    private function message(int $chatId): InternalMessage
    {
        $message = new InternalMessage();
        $message->chatId = $chatId;
        $message->messageText = 'hi';
        $message->userName = 'Tester';
        $message->userId = 1;
        $message->id = 10;

        return $message;
    }

    private function createCallback(): ProgressUpdateCallback
    {
        return $this->createMock(ProgressUpdateCallback::class);
    }
}
