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
    private const USER_ID = 111;
    private const OTHER_USER_ID = 999;

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
        $this->processor = new RestoreSavedMediaProcessor($this->imageRepository, $this->audioRepository);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $this->removeDatabaseFile();
    }

    public function testNoReplyShowsUsage(): void
    {
        $result = $this->runProcessor([]);

        $this->assertTrue($result->abortProcessing);
        $this->assertStringContainsString('Использование', $result->response->messageText);
    }

    public function testReplyWithoutDeleteCommandInThreadIsRejected(): void
    {
        $result = $this->runProcessor([
            ["Изображение «котик» удалено.", self::BOT_USER_ID],
        ]);

        $this->assertStringContainsString('в ответ на сообщение', $result->response->messageText);
    }

    public function testReplyToCommandEchoTypedByAnotherUserIsRejected(): void
    {
        $this->insertDeletedImage('котик', self::USER_ID);

        $result = $this->runProcessor([
            ['/delete котик', self::OTHER_USER_ID],
        ]);

        $this->assertStringContainsString('в ответ на сообщение', $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findDeletedByName('котик'));
    }

    public function testRestoresEverythingDeletedUnderTheName(): void
    {
        $this->insertDeletedImage('котик', self::USER_ID);
        $this->insertDeletedAudio('котик', self::USER_ID);

        $result = $this->runProcessor([
            ['/delete котик', self::USER_ID],
            ["Изображение и аудио «котик» удалены.", self::BOT_USER_ID],
        ]);

        $this->assertSame("Восстановлено: изображение и аудио «котик».", $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
        $this->assertNotNull($this->audioRepository->findByName('котик'));
    }

    public function testReplyDirectlyToOwnDeleteCommandAlsoRestores(): void
    {
        $this->insertDeletedImage('котик', self::USER_ID);

        $result = $this->runProcessor([
            ['/delete котик', self::USER_ID],
        ]);

        $this->assertSame("Восстановлено: изображение «котик».", $result->response->messageText);
        $this->assertNotNull($this->imageRepository->findByName('котик'));
    }

    public function testNameIsExtractedFromImgTagsAndBotMention(): void
    {
        $this->insertDeletedImage('котик', self::USER_ID);

        $result = $this->runProcessor([
            ['/delete@some_bot <img>котик</img>', self::USER_ID],
        ]);

        $this->assertSame("Восстановлено: изображение «котик».", $result->response->messageText);
    }

    public function testNothingLeftUnderTheNameAtAll(): void
    {
        $result = $this->runProcessor([
            ['/delete котик', self::USER_ID],
            ["Изображение «котик» может удалить только тот, кто его сохранил.", self::BOT_USER_ID],
        ]);

        $this->assertStringContainsString('нечего восстанавливать', $result->response->messageText);
    }

    public function testLiveEntryUnderTheNameIsReportedAsTaken(): void
    {
        $this->insertImage('котик', self::OTHER_USER_ID);

        $result = $this->runProcessor([
            ['/delete котик', self::USER_ID],
            ["Изображение «котик» может удалить только тот, кто его сохранил.", self::BOT_USER_ID],
        ]);

        $this->assertStringContainsString('Изображение «котик» не удалось восстановить', $result->response->messageText);
        $this->assertStringContainsString('имя уже занято или уже восстановлено', $result->response->messageText);
    }
    public function testReportsTypeThatCannotBeRestoredWhenNameIsReused(): void
    {
        $this->insertDeletedImage('котик', self::USER_ID);
        $this->insertAudio('котик', self::OTHER_USER_ID);

        $result = $this->runProcessor([
            ['/delete котик', self::USER_ID],
            ["Изображение и аудио «котик» удалены.", self::BOT_USER_ID],
        ]);

        $this->assertSame(
            "Восстановлено: изображение «котик».\nАудио «котик» не удалось восстановить: имя уже занято или уже восстановлено.",
            $result->response->messageText,
        );
    }

    /** @param list<array{0: string, 1: int}> $thread [text, userId] pairs preceding the /restore message */
    private function runProcessor(array $thread, int $restorerId = self::USER_ID): ProcessingResult
    {
        $messages = [];
        foreach ($thread as [$text, $userId]) {
            $messages[] = self::makeMessage($text, $userId);
        }
        $messages[] = self::makeMessage('', $restorerId);

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

    private function insertDeletedImage(string $name, int $userId): void
    {
        $this->insert('saved_image', 'restore-processor-test.jpg', $name, $userId, deleted: true);
    }

    private function insertDeletedAudio(string $name, int $userId): void
    {
        $this->insert('saved_audio', 'restore-processor-test.ogg', $name, $userId, deleted: true);
    }

    private function insertImage(string $name, int $userId): void
    {
        $this->insert('saved_image', 'restore-processor-test.jpg', $name, $userId);
    }

    private function insertAudio(string $name, int $userId): void
    {
        $this->insert('saved_audio', 'restore-processor-test.ogg', $name, $userId);
    }

    private function insert(string $table, string $filename, string $name, int $userId, bool $deleted = false): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            $deleted
                ? "INSERT INTO $table (name, filename, user_id, created_at, deleted_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                : "INSERT INTO $table (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', $filename);
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
