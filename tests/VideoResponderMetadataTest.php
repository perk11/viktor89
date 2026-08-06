<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Test\Support\NullMessageRepository;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use Perk11\Viktor89\Util\Telegram\ReactionReplacer;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(VideoResponder::class)]
class VideoResponderMetadataTest extends TestCase
{
    use TelegramRecordingTrait;

    private string $dbName = 'test_video_metadata.db';
    private Database $database;
    private MessageMetadataRepository $metadataRepository;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
        $this->telegramResponseOverride = static function (string $action, array $form): ?array {
            if ($action === 'sendVideo') {
                $chatId = (int) ($form['chat_id'] ?? 0);
                return [
                    'ok' => true,
                    'result' => [
                        'message_id' => 42,
                        'date' => time(),
                        'chat' => ['id' => $chatId, 'type' => 'group', 'title' => 'Test'],
                        'from' => ['id' => 123456789, 'is_bot' => true, 'first_name' => 'Bot'],
                        'video' => ['file_id' => 'vid', 'file_size' => 500],
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

    public function testOriginalPromptCaptionAndRewrittenPromptAreRecordedAsMetadata(): void
    {
        $responder = new VideoResponder(
            $this->stubReactionReplacer(),
            new \Psr\Log\NullLogger(),
            new NullMessageRepository(),
            $this->metadataRepository,
        );

        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100600;
        $message->userId = 999;
        $message->messageText = 'a dog on a beach';

        ob_start();
        try {
            $responder->sendVideo(
                $message,
                'mp4-bytes',
                // The caption is the original user idea, NOT the rewritten prompt.
                'a dog on a beach',
                // The preprocessor's rewritten prompt is recorded separately.
                'integrated_multimodal_description: [Shot 1] a dog runs along the shore ...',
            );
        } finally {
            ob_end_clean();
        }

        $metadata = $this->metadataRepository->findByMessageIdInChat(42, -100600);
        $this->assertNotNull($metadata, 'Metadata must be recorded for the sent video');
        $this->assertSame('a dog on a beach', $metadata->caption);
        $this->assertSame(
            'integrated_multimodal_description: [Shot 1] a dog runs along the shore ...',
            $metadata->processedPrompt,
        );
    }

    public function testOnlyCaptionIsRecordedWhenPromptWasNotPreprocessed(): void
    {
        $responder = new VideoResponder(
            $this->stubReactionReplacer(),
            new \Psr\Log\NullLogger(),
            new NullMessageRepository(),
            $this->metadataRepository,
        );

        $message = new InternalMessage();
        $message->id = 2;
        $message->chatId = -100601;
        $message->userId = 999;

        ob_start();
        try {
            $responder->sendVideo($message, 'mp4-bytes', 'a cat playing piano', null);
        } finally {
            ob_end_clean();
        }

        $metadata = $this->metadataRepository->findByMessageIdInChat(42, -100601);
        $this->assertNotNull($metadata);
        $this->assertSame('a cat playing piano', $metadata->caption);
        $this->assertNull($metadata->processedPrompt);
    }

    public function testNoMetadataRecordedWhenCaptionAndProcessedPromptAreNull(): void
    {
        $responder = new VideoResponder(
            $this->stubReactionReplacer(),
            new \Psr\Log\NullLogger(),
            new NullMessageRepository(),
            $this->metadataRepository,
        );

        $message = new InternalMessage();
        $message->id = 3;
        $message->chatId = -100602;
        $message->userId = 999;

        ob_start();
        try {
            $responder->sendVideo($message, 'mp4-bytes', null, null);
        } finally {
            ob_end_clean();
        }

        $this->assertNull($this->metadataRepository->findByMessageIdInChat(42, -100602));
    }

    public function testMetadataRepositoryIsOptional(): void
    {
        // Without a metadata repository the video still sends; nothing throws.
        $responder = new VideoResponder(
            $this->stubReactionReplacer(),
            new \Psr\Log\NullLogger(),
            new NullMessageRepository(),
        );

        $message = new InternalMessage();
        $message->id = 4;
        $message->chatId = -100603;
        $message->userId = 999;

        ob_start();
        try {
            $responder->sendVideo($message, 'mp4-bytes', 'a prompt', 'a rewrite');
        } finally {
            ob_end_clean();
        }

        $this->assertNull($this->metadataRepository->findByMessageIdInChat(42, -100603));
        $this->addToAssertionCount(1);
    }

    public function testModelIsRecordedAsMetadata(): void
    {
        $responder = new VideoResponder(
            $this->stubReactionReplacer(),
            new \Psr\Log\NullLogger(),
            new NullMessageRepository(),
            $this->metadataRepository,
        );

        $message = new InternalMessage();
        $message->id = 5;
        $message->chatId = -100604;
        $message->userId = 999;

        ob_start();
        try {
            $responder->sendVideo($message, 'mp4-bytes', 'a prompt', null, 'cogvideox');
        } finally {
            ob_end_clean();
        }

        $metadata = $this->metadataRepository->findByMessageIdInChat(42, -100604);
        $this->assertNotNull($metadata);
        $this->assertSame('cogvideox', $metadata->model);
    }

    public function testModelAloneIsRecordedAsMetadata(): void
    {
        // Even with no caption/processed prompt, the model is worth recording.
        $responder = new VideoResponder(
            $this->stubReactionReplacer(),
            new \Psr\Log\NullLogger(),
            new NullMessageRepository(),
            $this->metadataRepository,
        );

        $message = new InternalMessage();
        $message->id = 6;
        $message->chatId = -100605;
        $message->userId = 999;

        ob_start();
        try {
            $responder->sendVideo($message, 'mp4-bytes', null, null, 'ltx-video');
        } finally {
            ob_end_clean();
        }

        $metadata = $this->metadataRepository->findByMessageIdInChat(42, -100605);
        $this->assertNotNull($metadata);
        $this->assertSame('ltx-video', $metadata->model);
    }

    public function testCaptionWithModelPrependsModel(): void
    {
        $this->assertSame("Model: cogvideox\na dog on a beach", VideoResponder::captionWithModel('cogvideox', 'a dog on a beach'));
        $this->assertSame('a dog on a beach', VideoResponder::captionWithModel(null, 'a dog on a beach'));
        $this->assertSame('Model: cogvideox', VideoResponder::captionWithModel('cogvideox', null));
        $this->assertNull(VideoResponder::captionWithModel(null, null));
    }

    private function stubReactionReplacer(): ReactionReplacer
    {
        return new class extends ReactionReplacer {
            public function __construct()
            {
            }
            public function deleteOrReplaceWith(int $chatId, int $messageId, string $emoji): void
            {
            }
        };
    }
}
