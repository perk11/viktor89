<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MetadataCommandProcessor;
use Perk11\Viktor89\MessageMetadata;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Repository\PersonaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataCommandProcessor::class)]
class MetadataCommandProcessorTest extends TestCase
{
    private Database $database;
    private MessageMetadataRepository $repository;
    private PersonaRepository $personaRepository;
    private MetadataCommandProcessor $processor;

    protected function setUp(): void
    {
        $this->database = new Database(123, 'test_metadata_cmd.db');
        $this->repository = new MessageMetadataRepository($this->database);
        $this->personaRepository = new PersonaRepository($this->database);
        $this->processor = new MetadataCommandProcessor($this->repository, $this->personaRepository, 'TestBot');
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/test_metadata_cmd.db';
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($fullPath . $suffix)) {
                unlink($fullPath . $suffix);
            }
        }
    }

    private function buildChain(int $replyToId): \Perk11\Viktor89\MessageChain
    {
        $replied = new InternalMessage();
        $replied->id = $replyToId;
        $replied->chatId = 100;
        $replied->messageText = 'bot reply';

        $command = new InternalMessage();
        $command->id = 999;
        $command->chatId = 100;
        $command->messageText = '/metadata';

        return new \Perk11\Viktor89\MessageChain([$replied, $command]);
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(MetadataCommandProcessor::class))
                ->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testGetTriggeringCommands(): void
    {
        $this->assertSame(['/metadata'], $this->processor->getTriggeringCommands());
    }

    public function testShowsMessageWhenNoMetadata(): void
    {
        $callback = $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
        $result = $this->processor->processMessageChain($this->buildChain(1), $callback);

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('Нет сохранённых метаданных', $result->response->messageText);
    }

    public function testDisplaysModelSystemPromptCaptionWithoutPersona(): void
    {
        $this->repository->upsert(new MessageMetadata(
            100,
            5,
            'gpt-4o',
            'Be a pirate',
            null,
            'A cute cat',
        ));

        $callback = $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
        $result = $this->processor->processMessageChain($this->buildChain(5), $callback);

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $text = $result->response->messageText;
        $this->assertStringContainsString('gpt-4o', $text);
        $this->assertStringContainsString('Be a pirate', $text);
        $this->assertStringNotContainsString('Персона', $text);
        $this->assertStringContainsString('A cute cat', $text);
    }

    public function testDisplaysPersonaNameAndAuthor(): void
    {
        $this->personaRepository->addPersona('Pirate', 'You are a pirate.', 999, 'Bob');
        $personaId = $this->personaRepository->findPersonaByName('Pirate')->id;

        $this->repository->upsert(new MessageMetadata(
            100,
            5,
            'gpt-4o',
            'Be a pirate',
            $personaId,
        ));

        $callback = $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
        $result = $this->processor->processMessageChain($this->buildChain(5), $callback);

        $text = $result->response->messageText;
        $this->assertStringContainsString('Pirate', $text);
        $this->assertStringContainsString('Bob', $text);
        $this->assertStringContainsString('от', $text);
        // The system prompt should NOT contain the persona prompt suffix.
        $this->assertStringNotContainsString('The user has required you to be the following persona', $text);
    }

    public function testShowsDeletedForMissingPersona(): void
    {
        $this->repository->upsert(new MessageMetadata(
            100,
            5,
            'gpt-4o',
            'Be helpful',
            99999,
        ));

        $callback = $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
        $result = $this->processor->processMessageChain($this->buildChain(5), $callback);

        $text = $result->response->messageText;
        $this->assertStringContainsString('удалена', $text);
    }

    public function testHtmlEscapesSystemPrompt(): void
    {
        $this->repository->upsert(new MessageMetadata(
            100,
            5,
            'gpt-4o',
            'Use <b>bold</b> & <i>tags</i>',
        ));

        $callback = $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
        $result = $this->processor->processMessageChain($this->buildChain(5), $callback);

        $text = $result->response->messageText;
        $this->assertStringNotContainsString('<b>bold</b>', $text);
        $this->assertStringContainsString('bold', $text);
    }
}
