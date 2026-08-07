<?php

namespace Perk11\Viktor89\Assistant;

/**
 * Thrown by {@see TextDocumentReader::readContent()} when a document exceeds
 * the maximum allowed size, so the caller can surface an error to the user
 * instead of silently truncating or omitting the file.
 */
class DocumentTooLargeException extends \RuntimeException
{
    public function __construct(public readonly int $size)
    {
        parent::__construct("Document is too large: {$size} bytes (limit is " . TextDocumentReader::MAX_SIZE_BYTES . ' bytes)');
    }
}
