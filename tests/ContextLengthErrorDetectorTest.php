<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Anthropic\Core\Exceptions\APIStatusException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use Perk11\Viktor89\Assistant\ContextLengthErrorDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextLengthErrorDetector::class)]
class ContextLengthErrorDetectorTest extends TestCase
{

    #[DataProvider('contextLengthErrorProvider')]
    public function testIsContextLengthError(string $label, int $status, array $body, bool $expected): void
    {
        $e = new ErrorException($body, new Response($status));
        $this->assertSame($expected, ContextLengthErrorDetector::isContextLengthError($e), $label);
    }

    /**
     * @return array<string, array{string, int, array, bool}>
     */
    public static function contextLengthErrorProvider(): array
    {
        return [
            '413 always'            => ['413 is context-length',         413, ['message' => 'x'],                            true],
            '400 context message'   => ['400 "context" in message',     400, ['message' => 'context_length_exceeded'],       true],
            '400 too many tokens'   => ['400 "too many" in message',    400, ['message' => 'too many tokens'],               true],
            '400 exceed in message' => ['400 "exceed" in message',      400, ['message' => 'max_tokens exceeded'],           true],
            '400 request too large' => ['400 "request too large"',      400, ['message' => 'Request too large'],             true],
            '400 maximum in msg'    => ['400 "maximum" in message',     400, ['message' => 'maximum context length'],        true],
            '400 limit in msg'      => ['400 "limit" in message',       400, ['message' => 'token limit reached'],           true],
            '400 token in msg'      => ['400 "token" in message',       400, ['message' => 'token limit'],                   true],
            '400 too long in msg'   => ['400 "too long" in message',    400, ['message' => 'prompt too long'],               true],
            '400 unrelated error'   => ['400 unrelated',               400, ['message' => 'invalid_api_key'],               false],
            '500 server error'      => ['500 is not context-length',    500, ['message' => 'internal error'],                false],
            '429 rate limit'        => ['429 is not context-length',    429, ['message' => 'rate limit'],                    false],

            // Streamed requests can arrive as HTTP 200 with the error embedded
            // in the SSE stream; the library keeps the 200 status on the
            // ErrorException. Status must not gate detection (regression test
            // for the streaming compaction failure).
            '200 streamed exceed'   => ['200 streamed exceeds context', 200, ['message' => 'request (140832 tokens) exceeds the available context size (80128 tokens), try increasing it'], true],
            '200 streamed limit'    => ['200 streamed context length',  200, ['message' => 'This model maximum context length is 8192 tokens'], true],
            '200 streamed unrelated'=> ['200 streamed unrelated',       200, ['message' => 'invalid_api_key'],               false],
            '500 context length'    => ['500 but really context-length',500, ['message' => 'context length exceeded'],       true],
        ];
    }

    public function testRecognisesAnthropicPromptTooLongError(): void
    {
        $exception = APIStatusException::from(
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
            new Response(400, [], json_encode([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'prompt is too long: 1000001 tokens > 1000000 maximum'],
            ])),
        );

        $this->assertTrue(ContextLengthErrorDetector::isContextLengthError($exception));
    }

    public function testIgnoresOtherExceptions(): void
    {
        $this->assertFalse(ContextLengthErrorDetector::isContextLengthError(new \RuntimeException('prompt is too long')));
    }
}
