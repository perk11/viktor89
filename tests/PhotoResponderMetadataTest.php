<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Test\Support\NullMessageRepository;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use Perk11\Viktor89\Util\Telegram\ReactionReplacer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(PhotoResponder::class)]
class PhotoResponderMetadataTest extends TestCase
{
    use TelegramRecordingTrait;

    private string $dbName = 'test_photo_metadata.db';
    private Database $database;
    private MessageMetadataRepository $metadataRepository;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
        $this->telegramResponseOverride = static function (string $action, array $form): ?array {
            if ($action === 'sendPhoto') {
                $chatId = (int) ($form['chat_id'] ?? 0);
                return [
                    'ok' => true,
                    'result' => [
                        'message_id' => 42,
                        'date' => time(),
                        'chat' => ['id' => $chatId, 'type' => 'group', 'title' => 'Test'],
                        'from' => ['id' => 123456789, 'is_bot' => true, 'first_name' => 'Bot'],
                        'photo' => [
                            ['file_id' => 'small', 'file_size' => 100],
                            ['file_id' => 'large', 'file_size' => 500],
                        ],
                    ],
                ];
            }

            return null;
        };

        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->database = new Database(123, $this->dbName);
        $this->metadataRepository = new MessageMetadataRepository($this->database);
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

    public function testCaptionIsRecordedAsMetadataWhenPhotoIsSent(): void
    {
        $responder = new PhotoResponder(
            new NullMessageRepository(),
            new class extends CacheFileManager {
                public function __construct()
                {
                }
                public function writeFileToCache(string $fileId, string $contents): void
                {
                }
            },
            new class extends ReactionReplacer {
                public function __construct()
                {
                }
                public function deleteOrReplaceWith(int $chatId, int $messageId, string $emoji): void
                {
                }
            },
            $this->metadataRepository,
         logger: new \Psr\Log\NullLogger());

        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100600;
        $message->userId = 999;
        $message->userName = 'Tester';
        $message->messageText = 'A prompt';

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );

        ob_start();
        try {
            $responder->sendPhoto($message, $png, false, 'A cute cat curling in a ball');
        } finally {
            ob_end_clean();
        }

        $metadata = $this->metadataRepository->findByMessageIdInChat(42, -100600);
        $this->assertNotNull($metadata, 'Metadata must be recorded for the sent photo');
        $this->assertSame('A cute cat curling in a ball', $metadata->caption);
        $this->assertNull($metadata->model);
    }

    public function testNoMetadataRecordedWhenCaptionIsNull(): void
    {
        $responder = new PhotoResponder(
            new NullMessageRepository(),
            new class extends CacheFileManager {
                public function __construct()
                {
                }
                public function writeFileToCache(string $fileId, string $contents): void
                {
                }
            },
            new class extends ReactionReplacer {
                public function __construct()
                {
                }
                public function deleteOrReplaceWith(int $chatId, int $messageId, string $emoji): void
                {
                }
            },
            $this->metadataRepository,
         logger: new \Psr\Log\NullLogger());

        $message = new InternalMessage();
        $message->id = 2;
        $message->chatId = -100601;
        $message->userId = 999;
        $message->userName = 'Tester';
        $message->messageText = 'A prompt';

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );

        ob_start();
        try {
            $responder->sendPhoto($message, $png, false, null);
        } finally {
            ob_end_clean();
        }

        $this->assertNull($this->metadataRepository->findByMessageIdInChat(42, -100601));
    }
}
