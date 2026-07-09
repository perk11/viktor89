<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\AddPersonaProcessor;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PersonaHelper;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\PersonaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddPersonaProcessor::class)]
class AddPersonaProcessorTest extends TestCase
{
    private string $dbName = 'test_persona_add.db';
    private Database $database;
    private PersonaRepository $personaRepository;
    private AddPersonaProcessor $processor;

    protected function setUp(): void
    {
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->database = new Database(123, $this->dbName);
        $this->personaRepository = new PersonaRepository($this->database);
        $this->processor = new AddPersonaProcessor($this->personaRepository, new PersonaHelper('testbot'), 2);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($fullPath . $suffix)) {
                unlink($fullPath . $suffix);
            }
        }
    }

    public function testEmptyArgumentShowsUsage(): void
    {
        $result = $this->runProcessor('');
        $this->assertResponseContains($result, 'Использование');
    }

    public function testRequiresPromptOnSecondLine(): void
    {
        $result = $this->runProcessor('pirate');
        $this->assertResponseContains($result, 'первой строке');
    }

    public function testCreatesPersona(): void
    {
        $result = $this->runProcessor("pirate\nYou are a pirate captain.");

        $this->assertTrue($result->abortProcessing);
        $persona = $this->personaRepository->findPersonaByName('pirate');
        $this->assertNotNull($persona);
        $this->assertSame('You are a pirate captain.', $persona->systemPrompt);
        $this->assertSame(111, $persona->userId);
        $this->assertSame('Alice', $persona->userName);
    }

    public function testRejectsReservedNameDefault(): void
    {
        $result = $this->runProcessor("Default\nprompt");
        $this->assertResponseContains($result, 'зарезервировано');
        $this->assertNull($this->personaRepository->findPersonaByName('Default'));
    }

    public function testRejectsReservedNameCaseInsensitive(): void
    {
        $result = $this->runProcessor("default\nprompt");
        $this->assertResponseContains($result, 'зарезервировано');
    }

    public function testRejectsDuplicateName(): void
    {
        $this->personaRepository->addPersona('pirate', 'old prompt', 999, 'Bob');

        $result = $this->runProcessor("pirate\nnew prompt");

        $this->assertResponseContains($result, 'уже существует');
        $this->assertSame('old prompt', $this->personaRepository->findPersonaByName('pirate')->systemPrompt);
    }

    public function testEnforcesPerUserLimit(): void
    {
        $this->personaRepository->addPersona('one', 'p1', 111, 'Alice');
        $this->personaRepository->addPersona('two', 'p2', 111, 'Alice');

        $result = $this->runProcessor("three\nprompt three");

        $this->assertResponseContains($result, 'максимум');
        $this->assertNull($this->personaRepository->findPersonaByName('three'));
        $this->assertSame(2, $this->personaRepository->countPersonasByUserId(111));
    }

    public function testLimitIsPerUser(): void
    {
        $this->personaRepository->addPersona('one', 'p1', 999, 'Bob');
        $this->personaRepository->addPersona('two', 'p2', 999, 'Bob');

        $result = $this->runProcessor("mine\nprompt");

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($this->personaRepository->findPersonaByName('mine'));
    }

    public function testRoutesViaCommandBasedResponderTrigger(): void
    {
        $trigger = new CommandBasedResponderTrigger(['/addpersona'], $this->processor);
        $trigger->processMessageChain(
            new MessageChain([self::makeMessage("/addpersona pirate\nYou are a pirate.", 111)]),
            $this->createMock(ProgressUpdateCallback::class)
        );

        $this->assertNotNull($this->personaRepository->findPersonaByName('pirate'));
    }

    private function runProcessor(string $argument): ProcessingResult
    {
        return $this->processor->processMessageChain(
            new MessageChain([self::makeMessage($argument, 111)]),
            $this->createMock(ProgressUpdateCallback::class)
        );
    }

    private static function makeMessage(string $text, int $userId): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = random_int(1, 100000);
        $message->chatId = -100123;
        $message->userId = $userId;
        $message->userName = 'Alice';
        $message->messageText = $text;
        $message->type = 'text';
        $message->date = time();

        return $message;
    }

    private function assertResponseContains(ProcessingResult $result, string $needle): void
    {
        $this->assertNotNull($result->response, 'Expected a text response');
        $this->assertStringContainsString($needle, $result->response->messageText);
    }
}
