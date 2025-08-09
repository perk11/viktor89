<?php

namespace Perk11\Viktor89;

class CacheFileManager
{
    private const DOWNLOADED_FILES_CACHE_DIR = __DIR__ . '/../data/cache/downloaded-files';

    private function getCacheFileName(string $fileId): string
    {
        return self::DOWNLOADED_FILES_CACHE_DIR . '/' . mb_substr(
                str_replace(['.', '/'], ['_', '_'], $fileId),
                0,
                1024
            );
    }

    private function createCacheDir(): void
    {
        if (!is_dir(self::DOWNLOADED_FILES_CACHE_DIR)) {
            if (mkdir(self::DOWNLOADED_FILES_CACHE_DIR, recursive: true) || !is_dir(self::DOWNLOADED_FILES_CACHE_DIR)) {
                throw new \RuntimeException(
                    'Could not create downloaded files directory ' . self::DOWNLOADED_FILES_CACHE_DIR
                );
            }
        }
    }

    public function readFileFromCache(string $fileId): ?string
    {
        $cacheFileName = $this->getCacheFileName($fileId);
        if (!file_exists($cacheFileName)) {
            return null;
        }
        $contents = file_get_contents($cacheFileName);
        if ($contents === false) {
            throw new \RuntimeException(
                "Failed to read downloaded cache file: $cacheFileName. " . error_get_last()['message']
            );
        }
        echo "Reading file from cache: $cacheFileName\n";

        return $contents;
    }

    public function writeFileToCache(string $fileId, string $contents): void
    {
        $this->createCacheDir();
        $cacheFileName = $this->getCacheFileName($fileId);
        echo "Writing " . strlen($contents) . " bytes to cache file $fileId\n";
        $putResult = file_put_contents($cacheFileName, $contents);

        if ($putResult === false) {
            throw new \RuntimeException("Failed to write file $fileId to cache: ". error_get_last()['message']);
        }
    }
}
