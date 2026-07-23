<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PersonaAwareSystemPromptReader;
use Perk11\Viktor89\PersonaHelper;
use Perk11\Viktor89\Assistant\PrependingSystemPromptProcessor;
use Perk11\Viktor89\Repository\PersonaRepository;
use Perk11\Viktor89\Repository\UserPreferenceRepository;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\NullMessageRepository;
use Perk11\Viktor89\Test\Support\StubStreamingAssistant;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Verifies that AI-generated messages carry their metadata (model, system
 * prompt, persona) through the assistant -> ProcessingResultExecutor pipeline
 * and land in the message_metadata table, and that image captions are recorded
 * by PhotoResponder.
 */
#[CoversClass(ProcessingResultExecutor::class)]
#[CoversClass(\Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant::class)]
class MessageMetadataPersistenceIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private string $dbName = 'test_metadata_persistence.db';
    private Database $database;
    private MessageMetadataRepository $metadataRepository;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();

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

    public function testAssistantRecordsModelAndSystemPromptInMetadata(): void
    {
        $systemPrompt = 'You are a helpful test assistant.';
        $assistant = new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader($systemPrompt),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            static fn (): string => 'Hello from the model',
        );
        $assistant->setModelName('test-model-42');

        $chain = IntegrationTestDsl::buildIncomingMessageChain(-100500);

        $result = $assistant->processMessageChain($chain, $this->createCallback());

        $this->assertSame('test-model-42', $result->response->model);
        $this->assertStringContainsString($systemPrompt, $result->response->systemPrompt);
        $this->assertNull($result->response->personaId);
    }

    /**
     * Reproduces the real factory wiring: a PersonaAwareSystemPromptReader
     * wrapped in a PrependingSystemPromptProcessor (config-level systemPrompt).
     * The metadata interface must reach through the wrapper to record the
     * persona id and the base system prompt without the persona suffix.
     */
    public function testAssistantRecordsPersonaIdAndBasePromptThroughPrependingWrapper(): void
    {
        $personaRepository = new PersonaRepository($this->database);
        $userPreferenceRepository = new UserPreferenceRepository($this->database);

        $personaRepository->addPersona('Pirate', 'You are a pirate.', 999, 'Bob');
        $personaId = $personaRepository->findPersonaByName('Pirate')->id;
        $userPreferenceRepository->writeUserPreference(999, PersonaHelper::PERSONA_PREFERENCE, 'Pirate');

        $personaReader = new PersonaAwareSystemPromptReader(
            $userPreferenceRepository,
            $personaRepository,
            IntegrationTestDsl::stubPreferenceReader('Be concise.'),
        );
        // This is exactly what AssistantFactory does when a model config has
        // a hardcoded "systemPrompt" key.
        $wrappedReader = new PrependingSystemPromptProcessor($personaReader, 'You are Dolphin.');

        $assistant = new StubStreamingAssistant(
            $wrappedReader,
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            static fn (): string => 'Hello from the model',
        );
        $assistant->setModelName('dolphin');

        $chain = IntegrationTestDsl::buildIncomingMessageChain(-100501);
        $chain->last()->userId = 999;

        $result = $assistant->processMessageChain($chain, $this->createCallback());

        $this->assertSame('dolphin', $result->response->model);
        $this->assertSame($personaId, $result->response->personaId);
        $this->assertStringContainsString('Be concise.', $result->response->systemPrompt);
        $this->assertStringContainsString('You are Dolphin.', $result->response->systemPrompt);
        // The persona prompt must NOT be in the recorded system prompt.
        $this->assertStringNotContainsString('The user has required you to be the following persona', $result->response->systemPrompt);
        $this->assertStringNotContainsString('You are a pirate.', $result->response->systemPrompt);
    }

    public function testMetadataIsPersistedByProcessingResultExecutorWhenSending(): void
    {
        $response = new InternalMessage();
        $response->chatId = -100500;
        $response->messageText = 'A generated reply';
        $response->parseMode = 'Default';
        $response->model = 'gpt-test';
        $response->systemPrompt = 'Be brief';
        $response->personaId = 5;

        $result = new ProcessingResult($response, true);

        ob_start();
        try {
            $executor = new ProcessingResultExecutor(
                new NullMessageRepository(),
                true,
                null,
                $this->metadataRepository,
             logger: new \Psr\Log\NullLogger());
            $executor->execute($result);
        } finally {
            ob_end_clean();
        }

        // The recording Telegram client returns message_id 42.
        $this->assertSame(42, $response->id);

        $metadata = $this->metadataRepository->findByMessageIdInChat(42, -100500);
        $this->assertNotNull($metadata);
        $this->assertSame('gpt-test', $metadata->model);
        $this->assertSame('Be brief', $metadata->systemPrompt);
        $this->assertSame(5, $metadata->personaId);
        $this->assertNull($metadata->caption);
    }

    public function testMetadataIsNotPersistedWhenRepositoryIsNull(): void
    {
        $response = new InternalMessage();
        $response->chatId = -100501;
        $response->messageText = 'A generated reply';
        $response->model = 'gpt-test';

        $result = new ProcessingResult($response, true);

        ob_start();
        try {
            (new ProcessingResultExecutor(new NullMessageRepository(), logger: new \Psr\Log\NullLogger()))->execute($result);
        } finally {
            ob_end_clean();
        }

        // Nothing was written — the table stays empty.
        $this->assertNull($this->metadataRepository->findByMessageIdInChat(42, -100501));
    }

    public function testMetadataIsNotPersistedWhenAllFieldsNull(): void
    {
        $response = new InternalMessage();
        $response->chatId = -100502;
        $response->messageText = 'A plain reply';

        $result = new ProcessingResult($response, true);

        ob_start();
        try {
            $executor = new ProcessingResultExecutor(
                new NullMessageRepository(),
                true,
                null,
                $this->metadataRepository,
             logger: new \Psr\Log\NullLogger());
            $executor->execute($result);
        } finally {
            ob_end_clean();
        }

        $this->assertNull($this->metadataRepository->findByMessageIdInChat(42, -100502));
    }

    public function testAssistantWithoutModelNameRecordsNullModel(): void
    {
        $assistant = new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader('Be helpful'),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            static fn (): string => 'Hi',
        );
        // setModelName is never called.

        $chain = IntegrationTestDsl::buildIncomingMessageChain(-100503);
        $result = $assistant->processMessageChain($chain, $this->createCallback());

        $this->assertNull($result->response->model);
        $this->assertStringContainsString('Be helpful', $result->response->systemPrompt);
    }

    private function createCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createStub(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }
}
