<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\DocumentTooLargeException;
use Perk11\Viktor89\Assistant\TextDocumentReader;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentReader::class)]
class TextDocumentReaderTest extends TestCase
{
    public function testDetectsTextByMimeType(): void
    {
        $reader = $this->buildReader();

        $this->assertTrue($reader->isTextDocument($this->document(mimeType: 'text/plain')));
        $this->assertTrue($reader->isTextDocument($this->document(mimeType: 'TEXT/CSV')));
        $this->assertTrue($reader->isTextDocument($this->document(mimeType: 'application/json')));
        $this->assertTrue($reader->isTextDocument($this->document(mimeType: 'application/xml')));
    }

    public function testDetectsTextByExtensionWhenMimeTypeMissing(): void
    {
        $reader = $this->buildReader();

        $this->assertTrue($reader->isTextDocument($this->document(fileName: 'script.py')));
        $this->assertTrue($reader->isTextDocument($this->document(fileName: 'notes.MD')));
        $this->assertTrue($reader->isTextDocument($this->document(fileName: 'Dockerfile')));
        $this->assertTrue($reader->isTextDocument($this->document(fileName: '.gitignore')));
    }

    public function testRejectsNonTextDocuments(): void
    {
        $reader = $this->buildReader();

        $this->assertFalse($reader->isTextDocument($this->document(fileName: 'archive.zip')));
        $this->assertFalse($reader->isTextDocument($this->document(mimeType: 'application/pdf')));
        $this->assertFalse($reader->isTextDocument($this->document(fileName: 'photo.png', mimeType: 'image/png')));
        $this->assertFalse($reader->isTextDocument($this->document(fileName: 'unknown.bin')));
    }

    public function testImageDocumentIsNotTextEvenWithTextMime(): void
    {
        $reader = $this->buildReader();
        $message = $this->document(mimeType: 'text/plain');
        $message->photoFileId = 'photo-file-id';

        $this->assertFalse($reader->isTextDocument($message));
    }

    public function testRejectsMessageWithoutDocument(): void
    {
        $reader = $this->buildReader();
        $message = new InternalMessage();

        $this->assertFalse($reader->isTextDocument($message));
    }

    public function testReadContentReturnsDownloadedBytes(): void
    {
        $reader = $this->buildReader('hello world');

        $this->assertSame('hello world', $reader->readContent($this->document()));
    }

    public function testReadContentAcceptsExactlyTheLimit(): void
    {
        $exactly = str_repeat('x', TextDocumentReader::MAX_SIZE_BYTES);
        $reader = $this->buildReader($exactly);

        $this->assertSame($exactly, $reader->readContent($this->document()));
    }

    public function testReadContentThrowsWhenDownloadedBytesExceedLimit(): void
    {
        $tooLarge = str_repeat('x', TextDocumentReader::MAX_SIZE_BYTES + 1);
        $reader = $this->buildReader($tooLarge);

        $this->expectException(DocumentTooLargeException::class);
        $reader->readContent($this->document());
    }

    public function testReadContentThrowsFromReportedSizeWithoutDownloading(): void
    {
        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->expects($this->never())->method('downloadFile');
        $reader = new TextDocumentReader($downloader);

        $message = $this->document();
        $message->documentFileSize = TextDocumentReader::MAX_SIZE_BYTES + 1;

        try {
            $reader->readContent($message);
            $this->fail('Expected DocumentTooLargeException');
        } catch (DocumentTooLargeException $e) {
            $this->assertSame($message->documentFileSize, $e->size);
        }
    }

    public function testReadContentSanitizesInvalidUtf8(): void
    {
        // Raw document bytes are ingested as-is from Telegram and may not be
        // valid UTF-8 (e.g. Latin-1 text or a broken multibyte sequence)
        $reader = $this->buildReader("notes\xC3(\xE2\x82");

        $content = $reader->readContent($this->document());

        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertNotFalse(json_encode($content, JSON_THROW_ON_ERROR));
    }

    private function buildReader(string $downloadedBytes = ''): TextDocumentReader
    {
        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->method('downloadFile')->willReturn($downloadedBytes);

        return new TextDocumentReader($downloader);
    }

    private function document(?string $fileName = null, ?string $mimeType = null): InternalMessage
    {
        $message = new InternalMessage();
        $message->documentFileId = 'file-id';
        $message->documentFileName = $fileName;
        $message->documentMimeType = $mimeType;

        return $message;
    }
}
