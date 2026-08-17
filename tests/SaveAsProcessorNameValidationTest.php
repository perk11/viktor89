<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\SaveAsProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaveAsProcessor::class)]
class SaveAsProcessorNameValidationTest extends TestCase
{
    private string $dbName = 'test_save_as_name_validation.db';
    private Database $database;
    private ImageRepository $imageRepository;
    private SaveAsProcessor $processor;

    protected function setUp(): void
    {
        $this->removeDatabaseFile();
        $this->database = new Database(123, $this->dbName);
        $this->imageRepository = new ImageRepository($this->database->sqlite3Database);
        $this->processor = new SaveAsProcessor(
            $this->createStub(TelegramFileDownloader::class),
            $this->imageRepository,
            new AudioRepository($this->database->sqlite3Database),
        );
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $this->removeDatabaseFile();
    }

    public function testNameContainingGuillemetsOrNewlineIsRejected(): void
    {
        foreach (['ко»тик', 'ко«тик', "котик\nпёсик"] as $name) {
            $result = $this->runProcessor($name);

            $this->assertStringContainsString('Имя не должно содержать', $result->response->messageText);
            $this->assertNull($this->imageRepository->findByName($name));
        }
    }

    private function runProcessor(string $name): ProcessingResult
    {
        $previous = self::makeMessage('');
        $previous->photoFileId = 'photo-file-id';
        $command = self::makeMessage($name, 111);

        return $this->processor->processMessageChain(
            new MessageChain([$previous, $command]),
            $this->createStub(ProgressUpdateCallback::class)
        );
    }

    private static function makeMessage(string $text, int $userId = 111): InternalMessage
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

    private function removeDatabaseFile(): void
    {
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
}
