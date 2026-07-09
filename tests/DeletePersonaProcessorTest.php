<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\DeletePersonaProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PersonaHelper;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeletePersonaProcessor::class)]
class DeletePersonaProcessorTest extends TestCase
{
    private string $dbName = 'test_persona_delete.db';
    private Database $database;
    private DeletePersonaProcessor $processor;

    protected function setUp(): void
    {
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->database = new Database(123, $this->dbName);
        $this->processor = new DeletePersonaProcessor($this->database, new PersonaHelper('testbot'));
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

    public function testUnknownPersona(): void
    {
        $result = $this->runProcessor('ghost');
        $this->assertResponseContains($result, 'не найдена');
    }

    public function testOnlyCreatorCanDelete(): void
    {
        $this->database->addPersona('pirate', 'prompt', 999, 'Bob');

        $result = $this->runProcessor('pirate', 111);

        $this->assertResponseContains($result, 'создал');
        $this->assertNotNull($this->database->findPersonaByName('pirate'));
    }

    public function testCreatorDeleteRemovesPersona(): void
    {
        $this->database->addPersona('pirate', 'prompt', 111, 'Alice');

        $result = $this->runProcessor('pirate', 111);

        $this->assertTrue($result->abortProcessing);
        $this->assertNull($this->database->findPersonaByName('pirate'));
    }

    private function runProcessor(string $argument, int $userId = 111): ProcessingResult
    {
        return $this->processor->processMessageChain(
            new MessageChain([self::makeMessage($argument, $userId)]),
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
