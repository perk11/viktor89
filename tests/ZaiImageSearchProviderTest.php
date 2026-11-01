<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Mcp\Client;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchCandidate;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ZaiImageSearchProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZaiImageSearchProvider::class)]
class ZaiImageSearchProviderTest extends TestCase
{
    public function testCollectsCandidatesAcrossPagesWithOgImageFirst(): void
    {
        $provider = $this->buildProvider(
            [['title' => 'Page 1', 'link' => 'https://one.example/'], ['title' => 'Page 2', 'link' => 'https://two.example/']],
            [
                'https://one.example/' => [
                    'title' => 'First page',
                    'metadata' => ['og:image' => 'https://one.example/og.jpg'],
                    'images' => [
                        'Image 1,2: A tower at night' => 'https://one.example/a.jpg',
                        'Image 3' => 'https://one.example/b.jpg',
                        'Image 4' => 'https://one.example/c.jpg',
                    ],
                ],
                'https://two.example/' => ['images' => ['Image 1' => 'https://two.example/d.png']],
            ],
        );

        $candidates = $provider->findCandidates('tower', 4);

        // At most ceil(4/2) = 2 candidates per page before moving on, then leftovers.
        $this->assertSame(
            ['https://one.example/og.jpg', 'https://one.example/a.jpg', 'https://two.example/d.png', 'https://one.example/b.jpg'],
            self::urls($candidates),
        );
        $this->assertEquals(new ImageSearchCandidate('First page', 'https://one.example/og.jpg', 'https://one.example/'), $candidates[0]);
        $this->assertSame('A tower at night', $candidates[1]->title);
        $this->assertSame('Page 2', $candidates[2]->title);
    }

    public function testFiltersIconsSvgsAndDuplicates(): void
    {
        $provider = $this->buildProvider(
            [['title' => 'Page', 'link' => 'https://one.example/']],
            [
                'https://one.example/' => [
                    'metadata' => ['og:image' => 'https://one.example/photo.jpg'],
                    'images' => [
                        'Image 1' => 'https://one.example/photo.jpg',
                        'Image 2' => 'https://one.example/static/images/wordmark.png',
                        'Image 3' => 'https://one.example/thumb/Flag.svg/40px-Flag.svg.png',
                        'Image 4' => 'https://one.example/site-logo.png',
                        'Image 5' => 'https://one.example/drawing.svg',
                        'Image 6' => 'https://one.example/anim.gif',
                        'Image 7' => 'data:image/png;base64,AAAA',
                        'Image 8' => 'https://one.example/cms/images/topnav/map.png',
                        'Image 9' => 'https://one.example/second.webp',
                    ],
                ],
            ],
        );

        $this->assertSame(
            ['https://one.example/photo.jpg', 'https://one.example/second.webp'],
            self::urls($provider->findCandidates('q', 10)),
        );
    }

    public function testPrefersPhotoExtensionsOverPngs(): void
    {
        $provider = $this->buildProvider(
            [['title' => 'Page', 'link' => 'https://one.example/']],
            ['https://one.example/' => [
                'metadata' => ['og:image' => 'https://one.example/share.png'],
                'images' => [
                    'Image 1' => 'https://one.example/menu-map.png',
                    'Image 2' => 'https://one.example/photo.jpg',
                    'Image 3' => 'https://one.example/photo2.webp',
                ],
            ]],
        );

        $this->assertSame(
            ['https://one.example/share.png', 'https://one.example/photo.jpg', 'https://one.example/photo2.webp', 'https://one.example/menu-map.png'],
            self::urls($provider->findCandidates('q', 10)),
        );
    }

    public function testStopsReadingPagesOnceEnoughCandidatesAreFound(): void
    {
        $readerClient = $this->createMock(Client::class);
        $readerClient->expects($this->once())
            ->method('callTool')
            ->willReturn(self::textResult(['images' => ['Image 1' => 'https://one.example/a.jpg', 'Image 2' => 'https://one.example/b.jpg']]));
        $provider = new ZaiImageSearchProvider(
            'test-key',
            searchClientProvider: fn (): Client => $this->clientReturning([['link' => 'https://one.example/'], ['link' => 'https://two.example/']]),
            readerClientProvider: fn (): Client => $readerClient,
        );

        $this->assertCount(2, $provider->findCandidates('q', 2));
    }

    public function testReadsDirectLinksBeforeGoogleRedirects(): void
    {
        $readUrls = [];
        $readerClient = $this->createStub(Client::class);
        $readerClient->method('callTool')->willReturnCallback(
            static function (string $toolName, array $arguments) use (&$readUrls): CallToolResult {
                $readUrls[] = $arguments['url'];

                return self::textResult([]);
            }
        );
        $provider = new ZaiImageSearchProvider(
            'test-key',
            searchClientProvider: fn (): Client => $this->clientReturning([
                ['link' => 'https://www.google.com/goto?url=ABC'],
                ['link' => 'https://direct.example/'],
            ]),
            readerClientProvider: fn (): Client => $readerClient,
        );

        $provider->findCandidates('q', 3);

        $this->assertSame(['https://direct.example/', 'https://www.google.com/goto?url=ABC'], $readUrls);
    }

    public function testSkipsGoogleHostedImagesWhenRedirectWasNotFollowed(): void
    {
        $provider = $this->buildProvider(
            [['title' => 'Bridge facts', 'link' => 'https://www.google.com/goto?url=ABC']],
            ['https://www.google.com/goto?url=ABC' => [
                'title' => 'Google',
                'images' => [
                    'Image 1' => 'https://www.google.com/assets/bridge.jpg',
                    'Image 2' => 'https://cdn.example/bridge.jpg',
                ],
            ]],
        );

        $candidates = $provider->findCandidates('q', 3);

        $this->assertSame(['https://cdn.example/bridge.jpg'], self::urls($candidates));
        $this->assertSame('Bridge facts', $candidates[0]->title);
    }

    public function testCleansTitles(): void
    {
        $provider = $this->buildProvider(
            [['title' => 'Hotel near the tower', 'link' => 'https://one.example/']],
            ['https://one.example/' => ['images' => [
                'Image 1: image' => 'https://one.example/hotel.jpg',
                'Image 2: ' . str_repeat("word\n", 100) => 'https://one.example/long.jpg',
            ]]],
        );

        $candidates = $provider->findCandidates('q', 3);

        $this->assertSame('Hotel near the tower', $candidates[0]->title);
        $this->assertSame(150, mb_strlen($candidates[1]->title));
        $this->assertStringNotContainsString("\n", $candidates[1]->title);
        $this->assertStringEndsWith('…', $candidates[1]->title);
    }

    public function testSkipsPagesThatFailToLoad(): void
    {
        $provider = $this->buildProvider(
            [['title' => 'Broken', 'link' => 'https://broken.example/'], ['title' => 'Good', 'link' => 'https://good.example/']],
            ['https://good.example/' => ['images' => ['Image 1' => 'https://good.example/ok.jpg']]],
        );

        $this->assertSame(['https://good.example/ok.jpg'], self::urls($provider->findCandidates('q', 3)));
    }

    public function testSendsExpectedMcpArguments(): void
    {
        $searchClient = $this->createMock(Client::class);
        $searchClient->expects($this->once())
            ->method('callTool')
            ->with('web_search_prime', ['search_query' => 'eiffel tower', 'location' => 'us'])
            ->willReturn(self::textResult([['link' => 'https://one.example/']]));
        $readerClient = $this->createMock(Client::class);
        $readerClient->expects($this->once())
            ->method('callTool')
            ->with('webReader', [
                'url' => 'https://one.example/',
                'with_images_summary' => true,
                'retain_images' => true,
                'return_format' => 'text',
            ])
            ->willReturn(self::textResult([]));

        $provider = new ZaiImageSearchProvider(
            'test-key',
            searchClientProvider: fn (): Client => $searchClient,
            readerClientProvider: fn (): Client => $readerClient,
        );

        $provider->findCandidates('eiffel tower', 3);
    }

    public function testDecodesJsonWrappedInJsonString(): void
    {
        $searchClient = $this->createStub(Client::class);
        $searchClient->method('callTool')->willReturn(
            CallToolResult::success([new TextContent(json_encode(json_encode([['link' => 'https://one.example/']])))])
        );
        $readerClient = $this->createStub(Client::class);
        $readerClient->method('callTool')->willReturn(
            CallToolResult::success([new TextContent(json_encode(json_encode(['images' => ['Image 1' => 'https://one.example/a.jpg']])))])
        );
        $provider = new ZaiImageSearchProvider(
            'test-key',
            searchClientProvider: fn (): Client => $searchClient,
            readerClientProvider: fn (): Client => $readerClient,
        );

        $this->assertSame(['https://one.example/a.jpg'], self::urls($provider->findCandidates('q', 3)));
    }

    public function testSearchErrorThrows(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('callTool')->willReturn(CallToolResult::error([new TextContent('quota exceeded')]));
        $provider = new ZaiImageSearchProvider(
            'test-key',
            searchClientProvider: fn (): Client => $client,
            readerClientProvider: fn (): Client => $client,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('quota exceeded');

        $provider->findCandidates('q', 3);
    }

    public function testDoesNotConnectUntilUsed(): void
    {
        $connections = 0;
        $connect = function () use (&$connections): Client {
            $connections++;
            return $this->createStub(Client::class);
        };
        new ZaiImageSearchProvider('test-key', searchClientProvider: $connect, readerClientProvider: $connect);

        $this->assertSame(0, $connections);
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ZaiImageSearchProvider(' ');
    }

    /**
     * @param list<array<string, string>> $searchResults
     * @param array<string, array<string, mixed>> $pagesByUrl Pages missing from this map fail to load.
     */
    private function buildProvider(array $searchResults, array $pagesByUrl): ZaiImageSearchProvider
    {
        $readerClient = $this->createStub(Client::class);
        $readerClient->method('callTool')->willReturnCallback(
            static function (string $toolName, array $arguments) use ($pagesByUrl): CallToolResult {
                if (!isset($pagesByUrl[$arguments['url']])) {
                    throw new \RuntimeException('Page failed to load');
                }

                return self::textResult($pagesByUrl[$arguments['url']]);
            }
        );

        return new ZaiImageSearchProvider(
            'test-key',
            searchClientProvider: fn (): Client => $this->clientReturning($searchResults),
            readerClientProvider: fn (): Client => $readerClient,
        );
    }

    private function clientReturning(array $payload): Client
    {
        $client = $this->createStub(Client::class);
        $client->method('callTool')->willReturn(self::textResult($payload));

        return $client;
    }

    private static function textResult(array $payload): CallToolResult
    {
        return CallToolResult::success([new TextContent(json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    /**
     * @param list<ImageSearchCandidate> $candidates
     * @return list<string>
     */
    private static function urls(array $candidates): array
    {
        return array_map(static fn (ImageSearchCandidate $candidate): string => $candidate->imageUrl, $candidates);
    }
}
