<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\MessageChainTextDocumentLoader;
use Perk11\Viktor89\Assistant\TextDocumentReader;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MessageChainTextDocumentLoader::class)]
class MessageChainTextDocumentLoaderTest extends TestCase
{
    private const DB_NAME = 'test_message_chain_text_document_loader.db';

    protected function tearDown(): void
    {
        $fullPath = __DIR__ . '/../data/' . self::DB_NAME;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testTooLargeDocumentIsReportedOnlyOnce(): void
    {
        [$repository, $close] = $this->repository();
        try {
            $document = $this->document(100, 'big.txt');
            $repository->logInternalMessage($document);

            $tooLarge = str_repeat('x', TextDocumentReader::MAX_SIZE_BYTES + 1);
            $downloader = $this->createMock(TelegramFileDownloader::class);
            $downloader->expects($this->once())->method('downloadFile')->willReturn($tooLarge);
            $loader = $this->loader($downloader, $repository);

            $first = $loader->loadIntoChain(new MessageChain([$document]));
            $this->assertNotNull($first);
            $this->assertTrue($first->abortProcessing);
            $this->assertStringContainsString('слишком большой', $first->response->messageText);

            // Simulate a later turn: the user replies to the document, so the
            // chain contains it again, re-read from the database (marker
            // preserved) with the Telegram-provided file id re-attached the
            // way Engine re-enriches replied-to messages. The error must not
            // be reported a second time and the file must not be re-downloaded.
            $reloaded = $this->reloadedFromHistory($repository, 100, 'big.txt');
            $this->assertNull($loader->loadIntoChain(new MessageChain([$reloaded, $this->textMessage('why not?')])));
        } finally {
            $close();
        }
    }

    public function testFoldedDocumentIsNotFoldedTwiceOnReentry(): void
    {
        [$repository, $close] = $this->repository();
        try {
            $document = $this->document(101, 'notes.txt');
            $document->messageText = 'summarize';
            $repository->logInternalMessage($document);

            $downloader = $this->createMock(TelegramFileDownloader::class);
            $downloader->expects($this->once())->method('downloadFile')->willReturn('FILE CONTENT');
            $loader = $this->loader($downloader, $repository);

            $this->assertNull($loader->loadIntoChain(new MessageChain([$document])));
            $this->assertSame("summarize\n\nFILE CONTENT", $document->messageText);

            // Replying to the document later must reuse the persisted text
            // instead of folding the contents in a second time.
            $reloaded = $this->reloadedFromHistory($repository, 101, 'notes.txt');
            $this->assertNull($loader->loadIntoChain(new MessageChain([$reloaded])));
            $this->assertSame("summarize\n\nFILE CONTENT", $reloaded->messageText);
        } finally {
            $close();
        }
    }

    public function testDocumentWithinSameChainObjectIsLoadedOnlyOnce(): void
    {
        [$repository, $close] = $this->repository();
        try {
            $document = $this->document(102, 'notes.txt');
            $repository->logInternalMessage($document);

            $downloader = $this->createMock(TelegramFileDownloader::class);
            $downloader->expects($this->once())->method('downloadFile')->willReturn('FILE CONTENT');
            $loader = $this->loader($downloader, $repository);

            $chain = new MessageChain([$document]);
            $this->assertNull($loader->loadIntoChain($chain));
            $this->assertNull($loader->loadIntoChain($chain));
            $this->assertSame('FILE CONTENT', $document->messageText);
        } finally {
            $close();
        }
    }

    public function testBotDocumentsAreSkipped(): void
    {
        [$repository, $close] = $this->repository();
        try {
            $document = $this->document(103, 'response.md');
            $document->userId = 777;
            $document->messageText = 'already carried in text';
            $repository->logInternalMessage($document);

            $downloader = $this->createMock(TelegramFileDownloader::class);
            $downloader->expects($this->never())->method('downloadFile');

            $this->assertNull($this->loader($downloader, $repository)->loadIntoChain(new MessageChain([$document])));
            $this->assertSame('already carried in text', $document->messageText);
        } finally {
            $close();
        }
    }

    private function reloadedFromHistory(MessageRepository $repository, int $id, string $fileName): InternalMessage
    {
        $message = $repository->findMessageByIdInChat($id, -200);
        $this->assertNotNull($message);
        // Engine re-attaches the fresh Telegram document metadata to replied-to
        // messages; alt_text survives that enrichment.
        $message->documentFileId = 'file-id';
        $message->documentFileName = $fileName;
        $message->documentMimeType = 'text/plain';

        return $message;
    }

    private function loader(TelegramFileDownloader $downloader, MessageRepository $repository): MessageChainTextDocumentLoader
    {
        return new MessageChainTextDocumentLoader(
            new TextDocumentReader($downloader),
            $repository,
            new NullLogger(),
            777,
        );
    }

    private function document(int $id, string $fileName): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = $id;
        $message->chatId = -200;
        $message->userId = 1;
        $message->userName = 'Tester';
        $message->messageText = '';
        $message->type = 'document';
        $message->date = time();
        $message->documentFileId = 'file-id';
        $message->documentFileName = $fileName;
        $message->documentMimeType = 'text/plain';

        return $message;
    }

    private function textMessage(string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = random_int(1000, 100000);
        $message->chatId = -200;
        $message->userId = 1;
        $message->userName = 'Tester';
        $message->messageText = $text;
        $message->type = 'text';
        $message->date = time();

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
}
