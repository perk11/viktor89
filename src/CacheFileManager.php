<?php

namespace Perk11\Viktor89;

use RuntimeException;

class CacheFileManager
{
    public function __construct(private readonly Database $database)
    {
    }

    private const string DOWNLOADED_FILES_CACHE_DIR = __DIR__ . '/../data/cache/downloaded-files';

    private function getCacheFileName(string $fileId): string
    {
        return mb_substr(
                str_replace(['.', '/'], ['_', '_'], $fileId),
                0,
                1024
            );
    }

    private function getCacheFilePath(string $fileName): string
    {
        return self::DOWNLOADED_FILES_CACHE_DIR . '/' . $fileName;
    }

    private function createCacheDir(): void
    {
        if (!is_dir(self::DOWNLOADED_FILES_CACHE_DIR)) {
            if (mkdir(self::DOWNLOADED_FILES_CACHE_DIR, recursive: true) || !is_dir(self::DOWNLOADED_FILES_CACHE_DIR)) {
                throw new RuntimeException(
                    'Could not create downloaded files directory ' . self::DOWNLOADED_FILES_CACHE_DIR
                );
            }
        }
    }

    public function readFileFromCache(string $fileId): ?string
    {
        $cacheFileName = $this->database->readFileCacheNameById($fileId);
        if ($cacheFileName === null) {
            echo "No Database record for cached file $fileId";

            return null;
        }
        $cacheFilePath = $this->getCacheFilePath($cacheFileName);
        if (!file_exists($cacheFilePath)) {
            echo "A record with for cached file $fileId exists in the database, but $cacheFilePath does not exist\n";
            return null;
        }
        $contents = file_get_contents($cacheFilePath);
        if ($contents === false) {
            throw new RuntimeException(
                "Failed to read downloaded cache file: $cacheFilePath. " . error_get_last()['message']
            );
        }
        echo "Reading file from cache: $cacheFilePath\n";

        return $contents;
    }

    public function writeFileToCache(string $fileId, string $contents): void
    {
        $sha1 = sha1($contents);
        $filePathWithSameSha = $this->database->readFileCacheNameBySha1($sha1);
        if ($filePathWithSameSha !== null) {
            $this->database->writeFileCache($fileId, $filePathWithSameSha, $sha1);
            return;
        }
        $this->createCacheDir();
        $cacheFileName = $this->getCacheFileName($fileId);
        $cacheFilePath = $this->getCacheFilePath($cacheFileName);
        echo "Writing " . strlen($contents) . " bytes to cache file $fileId\n";
        $putResult = file_put_contents($cacheFilePath, $contents);

        if ($putResult === false) {
            throw new RuntimeException("Failed to write file $fileId to cache: ". error_get_last()['message']);
        }
        $this->database->writeFileCache($fileId, $cacheFileName, $sha1);
    }
}
