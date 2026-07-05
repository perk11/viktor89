<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\WebSearchResponseLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSearchResponseLimiter::class)]
class WebSearchResponseLimiterTest extends TestCase
{
    public function testReturnsResponseUnchangedWhenWithinLimit(): void
    {
        $limiter = new WebSearchResponseLimiter(64000);
        $response = ['results' => [['title' => 't', 'url' => 'u', 'content' => 'c']]];

        $this->assertSame($response, $limiter->limitResponseSize($response));
    }

    public function testRejectsZeroOrNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxResponseSizeBytes must be an integer greater than 0');
        new WebSearchResponseLimiter(0);
    }

    public function testDropsResultsUntilItFits(): void
    {
        // Each result is ~50 bytes, limit only allows a handful.
        $limiter = new WebSearchResponseLimiter(170);
        $response = ['results' => [
            ['title' => 'result-1', 'url' => 'http://one', 'content' => 'content one'],
            ['title' => 'result-2', 'url' => 'http://two', 'content' => 'content two'],
            ['title' => 'result-3', 'url' => 'http://three', 'content' => 'content three'],
            ['title' => 'result-4', 'url' => 'http://four', 'content' => 'content four'],
            ['title' => 'result-5', 'url' => 'http://five', 'content' => 'content five'],
        ]];

        $limited = $limiter->limitResponseSize($response);

        $this->assertArrayHasKey('truncated', $limited);
        $this->assertTrue($limited['truncated']);
        // Results were dropped from the end until the JSON fit.
        $this->assertLessThan(count($response['results']), count($limited['results']));
        $this->assertSame('result-1', $limited['results'][0]['title']);
    }

    public function testTruncatesLargeStringsWhenResultsCannotBeDropped(): void
    {
        // A single result whose string is huge, very small limit forces string truncation.
        $limiter = new WebSearchResponseLimiter(60);
        $response = ['results' => [
            ['title' => str_repeat('x', 500), 'url' => 'u', 'content' => 'c'],
        ]];

        $limited = $limiter->limitResponseSize($response);

        $this->assertTrue($limited['truncated']);
        $encodedLength = strlen(json_encode($limited, JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(60, $encodedLength);
    }

    public function testReturnsEmptyResultsWhenNothingFits(): void
    {
        // Tiny limit that cannot even hold the empty structure with a truncated marker.
        $limiter = new WebSearchResponseLimiter(5);
        $response = ['results' => [['title' => str_repeat('y', 1000)]]];

        $limited = $limiter->limitResponseSize($response);

        $this->assertSame([], $limited['results']);
        $this->assertTrue($limited['truncated']);
        $this->assertSame('Response exceeded the configured size limit.', $limited['message']);
    }

    public function testHandlesResponseWithoutResultsKey(): void
    {
        $limiter = new WebSearchResponseLimiter(50);
        // No 'results' key, just a big string to force the string-truncation path.
        $response = ['data' => str_repeat('z', 500)];

        $limited = $limiter->limitResponseSize($response);

        $this->assertTrue($limited['truncated']);
        // The original 500-char string must have been heavily truncated.
        $this->assertLessThan(500, strlen($limited['data']));
        // The truncated body (excluding the small `truncated` flag) stays within limit.
        unset($limited['truncated']);
        $this->assertLessThanOrEqual(
            50,
            strlen(json_encode($limited, JSON_THROW_ON_ERROR)),
        );
    }
}
