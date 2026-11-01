<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Anthropic\Core\Exceptions\APIStatusException;
use OpenAI\Exceptions\ErrorException;

/** Recognises "the prompt does not fit in the context window" errors from the OpenAI and Anthropic clients. */
final class ContextLengthErrorDetector
{
    private const array CONTEXT_LENGTH_PHRASES = [
        'context length',
        'context limit',
        'context window',
        'maximum context',
        'max context',
        'token limit',
        'tokens exceed',
        'too many tokens',
        'too long',
        'request too large',
        'maximum number of tokens',
        'reduce the length',
        'reduce your prompt',
    ];

    public static function isContextLengthError(\Throwable $exception): bool
    {
        return match (true) {
            $exception instanceof ErrorException => self::matches(
                $exception->getStatusCode(),
                implode(' ', [$exception->getErrorMessage(), $exception->getErrorType() ?? '', (string) ($exception->getErrorCode() ?? '')]),
            ),
            $exception instanceof APIStatusException => self::matches($exception->status, $exception->getMessage()),
            default => false,
        };
    }

    public static function matches(?int $statusCode, string $errorText): bool
    {
        if ($statusCode === 413) {
            return true;
        }

        // The status code is not checked otherwise: for streamed requests an
        // OpenAI-compatible server may answer HTTP 200 and embed the error in
        // the SSE stream, and some report context-length failures as 5xx. The
        // message checks below are specific enough to avoid false positives
        // (an exact phrase, or a context/token term plus an overflow term).
        $normalizedError = str_replace(['_', '-'], ' ', strtolower($errorText));

        foreach (self::CONTEXT_LENGTH_PHRASES as $phrase) {
            if (str_contains($normalizedError, $phrase)) {
                return true;
            }
        }

        $mentionsContextOrTokens = str_contains($normalizedError, 'context')
            || str_contains($normalizedError, 'token');

        $mentionsOverflow = str_contains($normalizedError, 'exceed')
            || str_contains($normalizedError, 'maximum')
            || str_contains($normalizedError, 'limit')
            || str_contains($normalizedError, 'length');

        return $mentionsContextOrTokens && $mentionsOverflow;
    }
}
