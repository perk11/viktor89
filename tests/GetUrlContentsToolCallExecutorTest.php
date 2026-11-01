<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\Assistant\Tool\GetUrlContentsToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\BlockedUrlException;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\HostAddressResolver;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\PublicUrlGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(GetUrlContentsToolCallExecutor::class)]
class GetUrlContentsToolCallExecutorTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array}> */
    private array $history = [];

    public function testFetchesPublicUrlPinnedToResolvedAddress(): void
    {
        $executor = $this->executor(
            ['example.com' => ['93.184.216.34']],
            [new Response(200, ['Content-Type' => 'text/html'], '<html><title>Hi</title><body>Hello <b>world</b></body></html>')],
        );

        $result = $executor->executeToolCall(['url' => 'https://example.com/page']);

        $this->assertSame('https://example.com/page', $result['url']);
        $this->assertSame('Hi', $result['title']);
        $this->assertStringContainsString('Hello world', $result['content']);

        $this->assertCount(1, $this->history);
        $options = $this->history[0]['options'];
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function testRefusesPrivateAddressWithoutSendingRequest(): void
    {
        $executor = $this->executor(['localhost' => ['127.0.0.1']], []);

        try {
            $executor->executeToolCall(['url' => 'http://localhost:8188/history']);
            $this->fail('Expected BlockedUrlException');
        } catch (BlockedUrlException $e) {
            $this->assertStringNotContainsString('127.0.0.1', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function testFollowsRedirectToPublicHost(): void
    {
        $executor = $this->executor(
            ['a.example' => ['1.1.1.1'], 'b.example' => ['8.8.8.8']],
            [
                new Response(302, ['Location' => 'https://b.example/final']),
                new Response(200, ['Content-Type' => 'text/plain'], 'done'),
            ],
        );

        $result = $executor->executeToolCall(['url' => 'http://a.example/start']);

        $this->assertSame('https://b.example/final', $result['url']);
        $this->assertSame('done', $result['content']);
        $this->assertSame(['b.example:443:8.8.8.8'], $this->history[1]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function testResolvesRelativeRedirect(): void
    {
        $executor = $this->executor(
            ['a.example' => ['1.1.1.1']],
            [
                new Response(301, ['Location' => '/moved']),
                new Response(200, ['Content-Type' => 'text/plain'], 'ok'),
            ],
        );

        $result = $executor->executeToolCall(['url' => 'http://a.example/start']);

        $this->assertSame('http://a.example/moved', $result['url']);
        $this->assertSame('http://a.example/moved', (string) $this->history[1]['request']->getUri());
    }

    public function testRefusesRedirectIntoPrivateNetwork(): void
    {
        $executor = $this->executor(
            ['a.example' => ['1.1.1.1'], 'comfy.internal' => ['192.168.1.20']],
            [new Response(302, ['Location' => 'http://comfy.internal:8188/queue'])],
        );

        $this->expectException(BlockedUrlException::class);
        try {
            $executor->executeToolCall(['url' => 'http://a.example/start']);
        } finally {
            $this->assertCount(1, $this->history);
        }
    }

    public function testStopsAfterTooManyRedirects(): void
    {
        $responses = [];
        for ($i = 0; $i < 7; $i++) {
            $responses[] = new Response(302, ['Location' => 'http://a.example/' . $i]);
        }
        $executor = $this->executor(['a.example' => ['1.1.1.1']], $responses);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many redirects');
        $executor->executeToolCall(['url' => 'http://a.example/start']);
    }

    public function testConnectionErrorsDoNotLeakTransportDetails(): void
    {
        $executor = $this->executor(
            ['a.example' => ['1.1.1.1']],
            [new \GuzzleHttp\Exception\ConnectException(
                'cURL error 7: Failed to connect to a.example port 80: Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', 'http://a.example/'),
            )],
        );

        try {
            $executor->executeToolCall(['url' => 'http://a.example/']);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to fetch URL', $e->getMessage());
        }
    }

    public function testRejectsNonHttpScheme(): void
    {
        $executor = $this->executor([], []);

        $this->expectException(\InvalidArgumentException::class);
        $executor->executeToolCall(['url' => 'file:///etc/passwd']);
    }

    /**
     * @param array<string, list<string>> $hosts
     * @param list<Response|\Throwable> $responses
     */
    private function executor(array $hosts, array $responses): GetUrlContentsToolCallExecutor
    {
        $resolver = $this->createMock(HostAddressResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (string $host): array => $hosts[$host] ?? [],
        );
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GetUrlContentsToolCallExecutor(
            new PublicUrlGuard($resolver, new NullLogger()),
            new NullLogger(),
            new Client(['handler' => $stack]),
        );
    }
}
