<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchCandidate;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\SearchedImageCache;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\Repository\SearchedImageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchedImageCache::class)]
#[CoversClass(SearchedImageRepository::class)]
class SearchedImageCacheTest extends TestCase
{
    private const string DB_NAME = 'test_searched_image.db';

    private string $directory;
    private Database $database;
    private SearchedImageCache $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/viktor89-searched-image-cache-test-' . bin2hex(random_bytes(4)) . '/nested';
        $this->database = new Database(123, self::DB_NAME);
        $this->cache = new SearchedImageCache(new SearchedImageRepository($this->database), $this->directory);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        foreach (['', '-shm', '-wal'] as $suffix) {
            @unlink(__DIR__ . '/../data/' . self::DB_NAME . $suffix);
        }
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        @rmdir($this->directory);
        @rmdir(dirname($this->directory));
    }

    public function testStoresAndReadsImageByUrl(): void
    {
        $this->cache->store('tower', self::candidate('a'), 'bytes-a');
        $this->cache->store('tower', self::candidate('b'), 'bytes-b');

        $this->assertSame('bytes-a', $this->cache->read('https://example.com/a.jpg'));
        $this->assertSame('bytes-b', $this->cache->read('https://example.com/b.jpg'));
        $this->assertNull($this->cache->read('https://example.com/missing.jpg'));
        $this->assertCount(2, glob($this->directory . '/*'));
    }

    public function testFindsImagesOnlyForTheSameQueryIgnoringCase(): void
    {
        $this->cache->store('Eiffel Tower', self::candidate('a'), 'bytes-a');
        $this->cache->store('eiffel tower', self::candidate('b'), 'bytes-b');
        $this->cache->store('eiffel tower at night', self::candidate('c'), 'bytes-c');

        $found = $this->cache->findByQuery('eiffel tower', 10);

        $this->assertSame(['bytes-b', 'bytes-a'], array_column($found, 'image'));
        $this->assertEquals(self::candidate('b'), $found[0]['candidate']);
        $this->assertSame(['bytes-b', 'bytes-a'], array_column($this->cache->findByQuery('EIFFEL TOWER', 10), 'image'));
        $this->assertSame([], $this->cache->findByQuery('eiffel', 10));
        $this->assertCount(1, $this->cache->findByQuery('eiffel tower', 1));
    }

    public function testIgnoresCaseOfNonLatinQueries(): void
    {
        $this->cache->store('Эйфелева Башня', self::candidate('a'), 'bytes-a');

        $this->assertSame(['bytes-a'], array_column($this->cache->findByQuery('эйфелева башня', 10), 'image'));
    }

    public function testStoringAgainRefreshesTheIndexEntry(): void
    {
        $this->cache->store('tower', self::candidate('a'), 'old');
        $this->cache->store('tower', self::candidate('b'), 'bytes-b');
        $this->cache->store('tower', new ImageSearchCandidate('Renamed', 'https://example.com/a.jpg', 'https://other.example/'), 'new');

        $found = $this->cache->findByQuery('tower', 10);

        $this->assertSame(['new', 'bytes-b'], array_column($found, 'image'));
        $this->assertSame('Renamed', $found[0]['candidate']->title);
        $this->assertSame('https://other.example/', $found[0]['candidate']->sourcePage);
    }

    public function testSkipsIndexedImagesWhoseBytesAreGone(): void
    {
        $this->cache->store('tower', self::candidate('a'), 'bytes-a');
        $this->cache->store('tower', self::candidate('b'), 'bytes-b');
        unlink($this->directory . '/' . sha1('https://example.com/b.jpg'));

        $this->assertSame(['bytes-a'], array_column($this->cache->findByQuery('tower', 10), 'image'));
    }

    private static function candidate(string $name): ImageSearchCandidate
    {
        return new ImageSearchCandidate("Title $name", "https://example.com/$name.jpg", 'https://example.com/');
    }
}
