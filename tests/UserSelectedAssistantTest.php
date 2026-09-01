<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\MessageChainTextDocumentLoader;
use Perk11\Viktor89\Assistant\TextDocumentReader;
use Perk11\Viktor89\Assistant\UserSelectedAssistant;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(UserSelectedAssistant::class)]
class UserSelectedAssistantTest extends TestCase
{
    private const DB_NAME = 'test_user_selected_assistant.db';

    protected function tearDown(): void
    {
        MessageChain::setTextDocumentLoader(null);
        $fullPath = __DIR__ . '/../data/' . self::DB_NAME;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(UserSelectedAssistant::class);
        $this->assertTrue($reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class));
    }

    public function testConstructorTakesAllDependencies(): void
    {
        $reflection = new \ReflectionClass(UserSelectedAssistant::class);
        $params = $reflection->getConstructor()->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('assistantFactory', $params[0]->getName());
        $this->assertSame(AssistantFactory::class, $params[0]->getType()->getName());
        $this->assertSame(UserPreferenceReaderInterface::class, $params[1]->getType()->getName());
    }

    public function testUsesSelectedAssistantWhenAllowedInChat(): void
    {
        $factory = $this->buildFactory($this->assistantReturning('SELECTED'), allowed: true);

        $result = $this->buildProcessor($factory, $this->reader('siepatch'))
            ->processMessageChain($this->chain(-1001804789551), $this->createCallback());

        $this->assertNotNull($result->response);
        $this->assertSame('SELECTED', $result->response->messageText);
    }

    public function testFallsBackToDefaultWhenSelectedModelNotAllowedInChat(): void
    {
        $default = $this->assistantReturning('DEFAULT');
        $factory = $this->buildFactory($this->assistantReturning('NEVER'), allowed: false, default: $default);

        $result = $this->buildProcessor($factory, $this->reader('siepatch'))
            ->processMessageChain($this->chain(-999), $this->createCallback());

        $this->assertSame('DEFAULT', $result->response->messageText);
    }

    public function testUsesDefaultWhenNoModelSelected(): void
    {
        $default = $this->assistantReturning('DEFAULT');
        $factory = $this->buildFactory($this->assistantReturning('NEVER'), allowed: true, default: $default);

        $result = $this->buildProcessor($factory, $this->reader(null))
            ->processMessageChain($this->chain(-999), $this->createCallback());

        $this->assertSame('DEFAULT', $result->response->messageText);
    }

    public function testTextDocumentContentBecomesPromptAndIsPersisted(): void
    {
        [$repository, $close] = $this->repository();
        try {
            $document = $this->message(-200);
            $document->id = 50;
            $document->messageText = 'explain this';
            $document->documentFileId = 'file-id';
            $document->documentFileName = 'notes.txt';
            $document->documentMimeType = 'text/plain';
            $repository->logInternalMessage($document);

            $factory = $this->buildFactory($this->assistantReturning('OK'), allowed: true);
            $processor = $this->buildProcessor($factory, $this->reader(null), $repository, 'FILE CONTENT');

            $result = $processor->processMessageChain(new MessageChain([$document]), $this->createCallback());

            // The model received the instruction + file content as the prompt.
            $this->assertSame('OK', $result->response->messageText);
            $this->assertSame("explain this\n\nFILE CONTENT", $document->messageText);

            // And the same text was persisted for future history.
            $stored = $repository->findMessageByIdInChat(50, -200);
            $this->assertNotNull($stored);
            $this->assertSame("explain this\n\nFILE CONTENT", $stored->messageText);
        } finally {
            $close();
        }
    }

    public function testTextDocumentWithoutInstructionUsesContentAlone(): void
    {
        [$repository, $close] = $this->repository();
        try {
            $document = $this->message(-200);
            $document->id = 51;
            $document->messageText = '';
            $document->documentFileId = 'file-id';
            $document->documentFileName = 'code.py';
            $repository->logInternalMessage($document);

            $factory = $this->buildFactory($this->assistantReturning('OK'), allowed: true);
            $processor = $this->buildProcessor($factory, $this->reader(null), $repository, 'print(1)');

            $processor->processMessageChain(new MessageChain([$document]), $this->createCallback());

            $this->assertSame('print(1)', $document->messageText);
        } finally {
            $close();
        }
    }

    public function testTooLargeDocumentReturnsErrorAndDoesNotReachAssistant(): void
    {
        $assistant = $this->createMock(AssistantInterface::class);
        $assistant->expects($this->never())->method('processMessageChain');
        $factory = $this->buildFactory($assistant, allowed: true);

        [$repository, $close] = $this->repository();
        try {
            $document = $this->message(-200);
            $document->id = 52;
            $document->messageText = '';
            $document->documentFileId = 'file-id';
            $document->documentFileName = 'big.txt';
            $document->documentMimeType = 'text/plain';
            $repository->logInternalMessage($document);

            $tooLarge = str_repeat('x', TextDocumentReader::MAX_SIZE_BYTES + 1);
            $processor = $this->buildProcessor($factory, $this->reader(null), $repository, $tooLarge);

            $result = $processor->processMessageChain(new MessageChain([$document]), $this->createCallback());

            $this->assertTrue($result->abortProcessing);
            $this->assertNotNull($result->response);
            $this->assertStringContainsString('слишком большой', $result->response->messageText);
            $this->assertStringContainsString('big.txt', $result->response->messageText);
        } finally {
            $close();
        }
    }

    public function testBotDocumentInChainIsNotReadAgainAndStillReachesAssistant(): void
    {
        // A bot-sent .md document (over-long rich markdown delivered as a file)
        // already carries its full text in messageText; it must not be
        // re-downloaded or re-folded, but its text must reach the model.
        $assistant = $this->createMock(AssistantInterface::class);
        $assistant->expects($this->once())->method('processMessageChain')->willReturn(
            new ProcessingResult(InternalMessage::asResponseTo($this->message(0), 'OK'), true),
        );
        $factory = $this->buildFactory($assistant, allowed: true);

        [$repository, $close] = $this->repository();
        try {
            $botDocument = $this->message(-200);
            $botDocument->id = 60;
            $botDocument->userId = 777;
            $botDocument->messageText = str_repeat('a', TextDocumentReader::MAX_SIZE_BYTES + 1);
            $botDocument->documentFileId = 'bot-doc';
            $botDocument->documentFileName = 'response.md';
            $botDocument->documentMimeType = 'text/markdown';
            $repository->logInternalMessage($botDocument);

            $downloader = $this->createMock(TelegramFileDownloader::class);
            $downloader->expects($this->never())->method('downloadFile');
            MessageChain::setTextDocumentLoader($this->loader($downloader, $repository));
            $processor = new UserSelectedAssistant(
                $factory,
                $this->reader(null),
            );

            $result = $processor->processMessageChain(new MessageChain([$botDocument]), $this->createCallback());

            $this->assertSame('OK', $result->response->messageText);
            // messageText unchanged, no size-limit error
            $this->assertSame(str_repeat('a', TextDocumentReader::MAX_SIZE_BYTES + 1), $botDocument->messageText);
        } finally {
            $close();
        }
    }

    private function buildFactory(AssistantInterface $selected, bool $allowed, ?AssistantInterface $default = null): AssistantFactory
    {
        $factory = $this->createStub(AssistantFactory::class);
        $factory->method('getAssistantInstanceByName')->willReturn($selected);
        $factory->method('isModelNameAllowedInChat')->willReturn($allowed);
        $factory->method('getDefaultAssistantInstanceForChat')->willReturn($default ?? $selected);

        return $factory;
    }

    private function assistantReturning(string $text): AssistantInterface
    {
        $assistant = $this->createStub(AssistantInterface::class);
        $assistant->method('processMessageChain')->willReturn(
            new ProcessingResult(InternalMessage::asResponseTo($this->message(0), $text), true),
        );

        return $assistant;
    }

    private function reader(?string $value): UserPreferenceReaderInterface
    {
        return new class($value) implements UserPreferenceReaderInterface {
            public function __construct(private readonly ?string $value)
            {
            }

            public function getCurrentPreferenceValue(int $userId): ?string
            {
                return $this->value;
            }
        };
    }

    private function buildProcessor(
        AssistantFactory $factory,
        UserPreferenceReaderInterface $preference,
        ?MessageRepository $repository = null,
        string $downloadedBytes = '',
    ): UserSelectedAssistant {
        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->method('downloadFile')->willReturn($downloadedBytes);

        MessageChain::setTextDocumentLoader($this->loader($downloader, $repository));

        return new UserSelectedAssistant(
            $factory,
            $preference,
        );
    }

    private function loader(TelegramFileDownloader $downloader, ?MessageRepository $repository = null): MessageChainTextDocumentLoader
    {
        return new MessageChainTextDocumentLoader(
            new TextDocumentReader($downloader),
            $repository ?? $this->createStub(MessageRepository::class),
            new NullLogger(),
            777,
        );
    }

    private function chain(int $chatId): MessageChain
    {
        return new MessageChain([$this->message($chatId)]);
    }

    private function message(int $chatId): InternalMessage
    {
        $message = new InternalMessage();
        $message->chatId = $chatId;
        $message->messageText = 'hi';
        $message->userName = 'Tester';
        $message->userId = 1;
        $message->id = 10;
        $message->type = 'text';
        $message->date = 1;

        return $message;
    }

    /**
     * @return array{0: MessageRepository, 1: callable}
     */
    private function repository(): array
    {
        $database = new Database(123, self::DB_NAME);
        $repository = new MessageRepository($database);

        return [$repository, static function () use ($database): void {
            $database->sqlite3Database->close();
        }];
    }

    private function createCallback(): ProgressUpdateCallback
    {
        return $this->createMock(ProgressUpdateCallback::class);
    }
}
