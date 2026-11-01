<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchCandidate;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchProviderInterface;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchResultSelector;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\SearchedImageCache;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\WebImageDownloader;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Repository\SearchedImageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageSearchToolCallExecutor::class)]
class ImageSearchToolCallExecutorTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/viktor89-image-search-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->cacheDirectory . '/*') ?: []);
        @rmdir($this->cacheDirectory);
    }

    public function testAppendsSelectedImagesToChainAfterExistingPhotos(): void
    {
        $candidates = [
            new ImageSearchCandidate('Broken', 'https://one.example/broken.jpg', 'https://one.example/'),
            new ImageSearchCandidate('Tower', 'https://one.example/tower.jpg', 'https://one.example/'),
            new ImageSearchCandidate('Bridge', 'https://two.example/bridge.jpg', 'https://two.example/'),
            new ImageSearchCandidate('Night', 'https://two.example/night.jpg', 'https://two.example/'),
        ];
        $selector = $this->createMock(ImageSearchResultSelector::class);
        $selector->expects($this->once())
            ->method('select')
            ->with('eiffel tower', $this->callback(static function (array $images): bool {
                return array_map(static fn (array $image): string => $image['image'], $images)
                    === ['bytes:https://one.example/tower.jpg', 'bytes:https://two.example/bridge.jpg', 'bytes:https://two.example/night.jpg'];
            }), 2)
            ->willReturn([2, 0]);
        $repository = $this->createMock(SearchedImageRepository::class);
        $repository->expects($this->once())->method('findByQuery')->with('eiffel tower', 2)->willReturn([]);
        $indexed = [];
        $repository->expects($this->exactly(2))->method('add')->willReturnCallback(
            static function (string $query, ImageSearchCandidate $candidate) use (&$indexed): void {
                $indexed[] = [$query, $candidate->imageUrl];
            }
        );
        $cache = $this->buildCache($repository);
        $executor = new ImageSearchToolCallExecutor($this->providerReturning($candidates), $this->buildDownloader(), $selector, $cache);
        $trigger = self::makeMessage(null);
        $chain = new MessageChain([self::makeMessage('photo_file_1'), $trigger]);

        $result = $executor->executeToolCall(['query' => ' eiffel tower ', 'max_results' => 2], $chain);

        $this->assertSame([
            ['reference' => '#1', 'title' => 'Night', 'image_url' => 'https://two.example/night.jpg', 'source_page' => 'https://two.example/'],
            ['reference' => '#2', 'title' => 'Tower', 'image_url' => 'https://one.example/tower.jpg', 'source_page' => 'https://one.example/'],
        ], $result['images']);
        $this->assertSame(
            ['#1' => 'bytes:https://two.example/night.jpg', '#2' => 'bytes:https://one.example/tower.jpg'],
            $result['context_images'],
        );
        $this->assertStringContainsString('<img>#N</img>', $result['note']);

        $images = $chain->getImages();
        $this->assertCount(3, $images);
        $this->assertSame('bytes:https://two.example/night.jpg', $images[1]->photoContents);
        $this->assertSame('image_search', $images[1]->userName);
        $this->assertSame('Web image: Night (https://two.example/)', $images[1]->messageText);
        $this->assertSame($trigger, $chain->last());
        $this->assertSame('bytes:https://two.example/night.jpg', $cache->read('https://two.example/night.jpg'));
        $this->assertSame('bytes:https://one.example/tower.jpg', $cache->read('https://one.example/tower.jpg'));
        $this->assertNull($cache->read('https://two.example/bridge.jpg'));
        $this->assertSame(
            [['eiffel tower', 'https://two.example/night.jpg'], ['eiffel tower', 'https://one.example/tower.jpg']],
            $indexed,
        );
    }

    public function testAnswersFromCacheWithoutSearchingOrSelectingWhenTheSameQueryWasCached(): void
    {
        $repository = $this->createMock(SearchedImageRepository::class);
        $cache = $this->buildCache($repository);
        $cachedTower = new ImageSearchCandidate('Cached tower', 'https://one.example/tower.jpg', 'https://one.example/');
        $cache->store('eiffel tower', $cachedTower, 'cached-tower-bytes');
        $repository->expects($this->once())->method('findByQuery')->with('eiffel tower', 2)->willReturn([
            $cachedTower,
            new ImageSearchCandidate('Evicted', 'https://gone.example/a.jpg', 'https://gone.example/'),
        ]);
        $repository->expects($this->never())->method('add');
        $provider = $this->createMock(ImageSearchProviderInterface::class);
        $provider->expects($this->never())->method('findCandidates');
        $downloader = $this->createMock(WebImageDownloader::class);
        $downloader->expects($this->never())->method('downloadMany');
        $selector = $this->createMock(ImageSearchResultSelector::class);
        $selector->expects($this->never())->method('select');
        $executor = new ImageSearchToolCallExecutor($provider, $downloader, $selector, $cache);
        $chain = self::chain();

        $result = $executor->executeToolCall(['query' => ' eiffel tower ', 'max_results' => 2], $chain);

        $this->assertSame([
            ['reference' => '#0', 'title' => 'Cached tower', 'image_url' => 'https://one.example/tower.jpg', 'source_page' => 'https://one.example/'],
        ], $result['images']);
        $this->assertSame(['#0' => 'cached-tower-bytes'], $result['context_images']);
        $this->assertSame('cached-tower-bytes', $chain->getImages()[0]->photoContents);
    }

    public function testRethrowsWebSearchFailureWhenNothingIsCached(): void
    {
        $provider = $this->createStub(ImageSearchProviderInterface::class);
        $provider->method('findCandidates')->willThrowException(new \RuntimeException('quota exceeded'));
        $executor = new ImageSearchToolCallExecutor($provider, $this->buildDownloader(), new ImageSearchResultSelector(), $this->buildCache());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('quota exceeded');

        $executor->executeToolCall(['query' => 'eiffel tower'], self::chain());
    }

    public function testRequestsCandidatesProportionalToMaxResults(): void
    {
        $provider = $this->createMock(ImageSearchProviderInterface::class);
        $provider->expects($this->exactly(2))
            ->method('findCandidates')
            ->willReturnCallback(function (string $query, int $limit): array {
                static $expectedLimits = [6, 10];
                $this->assertSame(array_shift($expectedLimits), $limit);

                return [];
            });
        $executor = new ImageSearchToolCallExecutor($provider, $this->buildDownloader(), new ImageSearchResultSelector(), $this->buildCache());

        $executor->executeToolCall(['query' => 'q', 'max_results' => 2], self::chain());
        $executor->executeToolCall(['query' => 'q', 'max_results' => 5], self::chain());
    }

    public function testReturnsMessageWithoutContextImagesWhenNothingSelected(): void
    {
        $selector = $this->createStub(ImageSearchResultSelector::class);
        $selector->method('select')->willReturn([]);
        $executor = new ImageSearchToolCallExecutor(
            $this->providerReturning([new ImageSearchCandidate('Cat', 'https://one.example/cat.jpg', 'https://one.example/')]),
            $this->buildDownloader(),
            $selector,
            $this->buildCache(),
        );
        $chain = self::chain();

        $result = $executor->executeToolCall(['query' => 'dog'], $chain);

        $this->assertSame([], $result['images']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayNotHasKey('context_images', $result);
        $this->assertSame([], $chain->getImages());
    }

    public function testRejectsUnsupportedArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported argument: size');

        $this->buildExecutor()->executeToolCall(['query' => 'q', 'size' => 'large'], self::chain());
    }

    public function testRejectsOutOfRangeMaxResults(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->buildExecutor()->executeToolCall(['query' => 'q', 'max_results' => 6], self::chain());
    }

    public function testRejectsMissingQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->buildExecutor()->executeToolCall(['query' => '  '], self::chain());
    }

    private function buildExecutor(): ImageSearchToolCallExecutor
    {
        return new ImageSearchToolCallExecutor(
            $this->providerReturning([]),
            $this->buildDownloader(),
            new ImageSearchResultSelector(),
            $this->buildCache(),
        );
    }

    /**
     * @param list<ImageSearchCandidate> $candidates
     */
    private function providerReturning(array $candidates): ImageSearchProviderInterface
    {
        $provider = $this->createStub(ImageSearchProviderInterface::class);
        $provider->method('findCandidates')->willReturn($candidates);

        return $provider;
    }

    private function buildCache(?SearchedImageRepository $repository = null): SearchedImageCache
    {
        return new SearchedImageCache($repository ?? $this->createStub(SearchedImageRepository::class), $this->cacheDirectory);
    }

    private function buildDownloader(): WebImageDownloader
    {
        $downloader = $this->createStub(WebImageDownloader::class);
        $downloader->method('downloadMany')->willReturnCallback(static function (array $urls): array {
            $results = [];
            foreach ($urls as $url) {
                $results[$url] = str_contains($url, 'broken') ? new \RuntimeException('Download failed') : 'bytes:' . $url;
            }

            return $results;
        });

        return $downloader;
    }

    private static function chain(): MessageChain
    {
        return new MessageChain([self::makeMessage(null)]);
    }

    private static function makeMessage(?string $photoFileId): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->type = 'text';
        $message->userId = 12345;
        $message->date = 0;
        $message->userName = 'Alice';
        $message->messageText = 'find a tower';
        $message->chatId = -100123;
        $message->photoFileId = $photoFileId;

        return $message;
    }
}
