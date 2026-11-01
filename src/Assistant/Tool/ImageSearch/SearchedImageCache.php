<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

use Perk11\Viktor89\Repository\SearchedImageRepository;

/**
 * Keeps the normalized bytes of images picked by image_search, keyed by their
 * source URL, so they can be put back into the message chain on later turns
 * without downloading them again. Each image is also indexed by the query it
 * was picked for, so a later search with exactly the same query reuses it.
 */
class SearchedImageCache
{
    private const string DEFAULT_DIRECTORY = __DIR__ . '/../../../../data/cache/image-search';

    public function __construct(
        private readonly SearchedImageRepository $searchedImageRepository,
        private readonly string $directory = self::DEFAULT_DIRECTORY,
    ) {
    }

    public function store(string $query, ImageSearchCandidate $candidate, string $image): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, recursive: true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Could not create image search cache directory {$this->directory}");
        }
        $path = $this->getPath($candidate->imageUrl);
        // Written under a temporary name first so a concurrent worker never reads a partial file.
        $temporaryPath = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporaryPath, $image) === false || !rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException("Failed to write image search cache file $path");
        }
        $this->searchedImageRepository->add($query, $candidate);
    }

    public function read(string $imageUrl): ?string
    {
        $path = $this->getPath($imageUrl);
        if (!is_file($path)) {
            return null;
        }
        $image = file_get_contents($path);

        return $image === false ? null : $image;
    }

    /**
     * Images previously picked for exactly this query whose bytes are still cached.
     *
     * @return list<array{candidate: ImageSearchCandidate, image: string}>
     */
    public function findByQuery(string $query, int $limit): array
    {
        $images = [];
        foreach ($this->searchedImageRepository->findByQuery($query, $limit) as $candidate) {
            $image = $this->read($candidate->imageUrl);
            if ($image !== null) {
                $images[] = ['candidate' => $candidate, 'image' => $image];
            }
        }

        return $images;
    }

    private function getPath(string $imageUrl): string
    {
        return $this->directory . '/' . sha1($imageUrl);
    }
}
