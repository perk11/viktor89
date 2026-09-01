<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\Util\Utf8Sanitizer;

/**
 * Detects Telegram documents that are plain text (by MIME type / file
 * extension), downloads them and returns their contents. Enforces a hard size
 * cap so a single attachment cannot blow up the model context.
 */
class TextDocumentReader
{
    /** Maximum size of a document that will be read, in bytes (64 KB). */
    public const int MAX_SIZE_BYTES = 64 * 1024;

    private const array TEXT_MIME_PREFIXES = ['text/'];

    private const array TEXT_MIME_TYPES = [
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-javascript',
        'application/x-yaml',
        'application/yaml',
        'application/x-sh',
        'application/sql',
        'application/toml',
        'application/x-php',
        'application/x-httpd-php',
    ];

    private const array TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'rst', 'json', 'xml', 'yaml', 'yml',
        'csv', 'tsv', 'log', 'sql', 'py', 'rb', 'php', 'phtml', 'js',
        'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'html', 'htm', 'css', 'scss',
        'less', 'java', 'c', 'h', 'cpp', 'hpp', 'cc', 'cxx', 'cs', 'go',
        'rs', 'swift', 'kt', 'kts', 'scala', 'sh', 'bash', 'zsh', 'ps1',
        'bat', 'cmd', 'toml', 'ini', 'cfg', 'conf', 'env', 'pl', 'lua',
        'r', 'dart', 'groovy', 'gradle', 'vue', 'svelte', 'tex', 'bib',
        'makefile', 'dockerfile', 'gitignore',
    ];

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
    ) {
    }

    public function isTextDocument(InternalMessage $message): bool
    {
        // Image documents are handled as photos upstream; only treat standalone
        // generic documents as text sources.
        if ($message->documentFileId === null || $message->photoFileId !== null) {
            return false;
        }
        $mime = strtolower($message->documentMimeType ?? '');
        foreach (self::TEXT_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }
        if (in_array($mime, self::TEXT_MIME_TYPES, true)) {
            return true;
        }

        $extension = $this->extensionFromFileName($message->documentFileName ?? '');

        return $extension !== null && in_array($extension, self::TEXT_EXTENSIONS, true);
    }

    /**
     * @throws DocumentTooLargeException when the document exceeds the size cap
     */
    public function readContent(InternalMessage $message): string
    {
        if ($message->documentFileSize !== null && $message->documentFileSize > self::MAX_SIZE_BYTES) {
            throw new DocumentTooLargeException($message->documentFileSize);
        }
        $contents = $this->telegramFileDownloader->downloadFile($message->documentFileId);
        $length = strlen($contents);
        if ($length > self::MAX_SIZE_BYTES) {
            throw new DocumentTooLargeException($length);
        }

        // Arbitrary file bytes: may not be valid UTF-8
        return Utf8Sanitizer::sanitize($contents);
    }

    private function extensionFromFileName(string $fileName): ?string
    {
        $fileName = strtolower(trim($fileName));
        if ($fileName === '') {
            return null;
        }
        // Dotfiles / extensionless source files (Dockerfile, Makefile, .gitignore)
        // have no real extension, so the whole basename identifies the type.
        if (!str_contains($fileName, '.')) {
            return $fileName;
        }

        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: null;
    }
}
