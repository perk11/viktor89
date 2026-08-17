<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\ImageGeneration\DeleteSavedMediaProcessor;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeleteSavedMediaProcessor::class)]
class DeleteSavedMediaProcessorTest extends TestCase
{
    private string $dbName = 'test_delete_saved_media.db';
    private Database $database;
    private ImageRepository $imageRepository;
    private AudioRepository $audioRepository;
    private DeleteSavedMediaProcessor $processor;

    protected function setUp(): void
    {
        $this->removeDatabaseFile();
        $this->database = new Database(123, $this->dbName);
        $this->imageRepository = new ImageRepository($this->database->sqlite3Database);
        $this->audioRepository = new AudioRepository($this->database->sqlite3Database);
        $this->processor = new DeleteSavedMediaProcessor($this->imageRepository, $this->audioRepository);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $this->removeDatabaseFile();
    }

    public function testNoArgumentShowsUsage(): void
    {
        $result = $this->runProcessor('');

        $this->assertTrue($result->abortProcessing);
        $this->assertStringContainsString('Использование', $result->response->messageText);
    }

    public function testUnknownName(): void
    {
        $result = $this->runProcessor('ghost');

        $this->assertStringContainsString('не найдено', $result->response->messageText);
    }

    public function testOnlySaverCanDeleteImage(): void
    {
        $this->insertImage('котик', 999);

        $result = $this->runProcessor('котик', 111);

        $this->assertStringContainsString('только тот, кто его сохранил', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
    }

    public function testOnlySaverCanDeleteAudio(): void
    {
        $this->insertAudio('песня', 999);

        $result = $this->runProcessor('песня', 111);

        $this->assertStringContainsString('только тот, кто его сохранил', $result->response->messageText);
        $this->assertNotNull($this->audioRepository->findByName('песня'));
    }

    public function testDeletesImageWhenOnlyImageExists(): void
    {
        $this->insertImage('котик', 111);

        $result = $this->runProcessor('котик', 111);

        $this->assertSame("Изображение «котик» удалено.", $result->response->messageText);
        $this->assertNull($this->imageRepository->findByName('котик'));
        $this->assertNotNull($this->imageRepository->findDeletedByName('котик'));
    }

    public function testDeletesAudioWhenOnlyAudioExists(): void
    {
        $this->insertAudio('песня', 111);

        $result = $this->runProcessor('песня', 111);

        $this->assertSame("Аудио «песня» удалено.", $result->response->messageText);
        $this->assertNull($this->audioRepository->findByName('песня'));
        $this->assertNotNull($this->audioRepository->findDeletedByName('песня'));
    }

    public function testDeletesBothWhenBothExist(): void
    {
        $this->insertImage('котик', 111);
        $this->insertAudio('котик', 111);

        $result = $this->runProcessor('котик', 111);

        $this->assertSame("Изображение и аудио «котик» удалены.", $result->response->messageText);
        $this->assertNull($this->imageRepository->findByName('котик'));
        $this->assertNull($this->audioRepository->findByName('котик'));
    }

    public function testDeletesOwnAudioButRefusesImageSavedByOther(): void
    {
        $this->insertImage('котик', 999);
        $this->insertAudio('котик', 111);

        $result = $this->runProcessor('котик', 111);

        $this->assertStringContainsString("Аудио «котик» удалено.", $result->response->messageText);
        $this->assertStringContainsString('только тот, кто его сохранил', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
        $this->assertNull($this->audioRepository->findByName('котик'));
    }

    public function testImgTagsAreStrippedFromArgument(): void
    {
        $this->insertImage('котик', 111);

        $result = $this->runProcessor('<img>котик</img>', 111);

        $this->assertSame("Изображение «котик» удалено.", $result->response->messageText);
        $this->assertNull($this->imageRepository->findByName('котик'));
    }

    public function testNameContainingGuillemetsIsRejected(): void
    {
        $this->insertImage('котик', 111);

        foreach (['ко»тик', 'ко«тик', "котик\nпёсик"] as $name) {
            $result = $this->runProcessor($name, 111);

            $this->assertStringContainsString('Имя не должно содержать', $result->response->messageText);
        }

        $this->assertNotNull($this->imageRepository->findByName('котик'));
    }

    public function testAlreadyDeletedNameIsReportedWithRestoreHint(): void
    {
        $this->insertDeletedImage('котик', 111);

        $result = $this->runProcessor('котик', 222);

        $this->assertStringContainsString('уже удалено', $result->response->messageText);
        $this->assertStringContainsString('/restore', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findDeletedByName('котик'));
    }

    public function testCallerOwningNeitherMediaGetsRefusalsForBoth(): void
    {
        $this->insertImage('котик', 999);
        $this->insertAudio('котик', 888);

        $result = $this->runProcessor('котик', 111);

        $this->assertStringContainsString('Изображение «котик» может удалить только тот, кто его сохранил.', $result->response->messageText);
        $this->assertStringContainsString('Аудио «котик» может удалить только тот, кто его сохранил.', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
        $this->assertNotNull($this->audioRepository->findByName('котик'));
    }

    private function runProcessor(string $argument, int $userId = 111): ProcessingResult
    {
        return $this->processor->processMessageChain(
            new MessageChain([self::makeMessage($argument, $userId)]),
            $this->createStub(ProgressUpdateCallback::class)
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

    private function insertImage(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_image (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'delete-processor-test.jpg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function insertDeletedImage(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_image (name, filename, user_id, created_at, deleted_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'delete-processor-test.jpg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function insertAudio(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_audio (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'delete-processor-test.ogg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
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
