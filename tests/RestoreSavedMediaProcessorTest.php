<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\RestoreSavedMediaProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestoreSavedMediaProcessor::class)]
class RestoreSavedMediaProcessorTest extends TestCase
{
    private const BOT_USER_ID = 123;

    private string $dbName = 'test_restore_saved_media.db';
    private Database $database;
    private ImageRepository $imageRepository;
    private AudioRepository $audioRepository;
    private RestoreSavedMediaProcessor $processor;

    protected function setUp(): void
    {
        $this->removeDatabaseFile();
        $this->database = new Database(self::BOT_USER_ID, $this->dbName);
        $this->imageRepository = new ImageRepository($this->database->sqlite3Database);
        $this->audioRepository = new AudioRepository($this->database->sqlite3Database);
        $this->processor = new RestoreSavedMediaProcessor($this->imageRepository, $this->audioRepository, self::BOT_USER_ID);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $this->removeDatabaseFile();
    }

    public function testNoReplyShowsUsage(): void
    {
        $result = $this->runProcessor(null, 111);

        $this->assertTrue($result->abortProcessing);
        $this->assertStringContainsString('Использование', $result->response->messageText);
    }

    public function testReplyToNonDeleteMessageIsRejected(): void
    {
        $result = $this->runProcessor('Неизвестная команда', 111);

        $this->assertStringContainsString('в ответ на сообщение', $result->response->messageText);
    }

    public function testReplyToRefusalMessageIsRejected(): void
    {
        $result = $this->runProcessor("Изображение «котик» может удалить только тот, кто его сохранил.", 111);

        $this->assertStringContainsString('в ответ на сообщение', $result->response->messageText);
    }

    public function testRestoresImageAndAudioDeletedByBothMessage(): void
    {
        $this->insertDeletedImage('котик', 111);
        $this->insertDeletedAudio('котик', 111);

        $result = $this->runProcessor("Изображение и аудио «котик» удалены.", 111);

        $this->assertSame("Восстановлено: изображение и аудио «котик».", $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
        $this->assertNotNull($this->audioRepository->findByName('котик'));
    }

    public function testRestoresOnlyAudioFromAudioMessage(): void
    {
        $this->insertDeletedImage('котик', 111);
        $this->insertDeletedAudio('котик', 111);

        $result = $this->runProcessor("Аудио «котик» удалено.", 111);

        $this->assertSame("Восстановлено: аудио «котик».", $result->response->messageText);
        $this->assertNull($this->imageRepository->findByName('котик'));
        $this->assertNotNull($this->audioRepository->findByName('котик'));
    }

    public function testRestoresOnlyImageFromPartialDeleteMessage(): void
    {
        $this->insertDeletedImage('котик', 111);
        $this->insertDeletedAudio('котик', 999);

        $result = $this->runProcessor("Изображение «котик» удалено.\nАудио «котик» может удалить только тот, кто его сохранил.", 111);

        $this->assertSame("Восстановлено: изображение «котик».", $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
        $this->assertNull($this->audioRepository->findByName('котик'));
    }

    public function testOnlySaverCanRestore(): void
    {
        $this->insertDeletedImage('котик', 999);

        $result = $this->runProcessor("Изображение «котик» удалено.", 111);

        $this->assertStringContainsString('только тот, кто его сохранил', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findDeletedByName('котик'));
    }

    public function testNothingToRestore(): void
    {
        $result = $this->runProcessor("Изображение «котик» удалено.", 111);

        $this->assertStringContainsString('уже восстановлено', $result->response->messageText);
    }

    public function testReplyToMessageFromAnotherUserIsRejected(): void
    {
        $this->insertDeletedImage('котик', 111);

        $messages = [
            self::makeMessage("Изображение «котик» удалено.", 555),
            self::makeMessage('', 111),
        ];
        $result = $this->processor->processMessageChain(
            new MessageChain($messages),
            $this->createStub(ProgressUpdateCallback::class)
        );

        $this->assertStringContainsString('в ответ на сообщение бота', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findDeletedByName('котик'));
    }

    public function testReportsTypeThatCannotBeRestoredWhenNameIsReused(): void
    {
        $this->insertDeletedImage('котик', 111);
        $this->insertAudio('котик', 999);

        $result = $this->runProcessor("Изображение и аудио «котик» удалены.", 111);

        $this->assertSame(
            "Восстановлено: изображение «котик».\nАудио «котик» не удалось восстановить: имя уже занято или уже восстановлено.",
            $result->response->messageText,
        );
        $this->assertNotNull($this->imageRepository->findByName('котик'));
        $audio = $this->audioRepository->findByName('котик');
        $this->assertSame(999, $audio->userId);
    }

    public function testReportsNothingRestorableWhenNameIsReused(): void
    {
        $this->insertImage('котик', 999);

        $result = $this->runProcessor("Изображение «котик» удалено.", 111);

        $this->assertStringContainsString('Изображение «котик» не удалось восстановить', $result->response->messageText);
        $this->assertStringContainsString('имя уже занято или уже восстановлено', $result->response->messageText);
        $this->assertSame(999, $this->imageRepository->findByName('котик')->userId);
    }

    private function runProcessor(?string $repliedText, int $userId): ProcessingResult
    {
        $messages = [];
        if ($repliedText !== null) {
            $messages[] = self::makeMessage($repliedText, self::BOT_USER_ID);
        }
        $messages[] = self::makeMessage('', $userId);

        return $this->processor->processMessageChain(
            new MessageChain($messages),
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

    private function insertAudio(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_audio (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'restore-processor-test.ogg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function insertImage(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_image (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'restore-processor-test.jpg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function insertDeletedImage(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_image (name, filename, user_id, created_at, deleted_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'restore-processor-test.jpg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function insertDeletedAudio(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_audio (name, filename, user_id, created_at, deleted_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'restore-processor-test.ogg');
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
