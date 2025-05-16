<?php

namespace Perk11\Viktor89;

use GuzzleHttp\Client;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class TelegramFileDownloader
{
    private const DOWNLOADED_FILES_CACHE_DIR = __DIR__ . '/../data/cache/downloaded-files';
    private readonly Client $guzzle;
    public function __construct(private string $telegramBotApiKey)
    {
        $this->guzzle = new Client();
    }

    public function downloadVoice(Message $message): string
    {
        $voice = $message->getVoice();
        if ($voice === null) {
            throw new \Exception('Message does not contain voice');
        }
        $fileId = $voice->getFileId();
        echo "Downloading voice with fileId $fileId\n";

        return $this->downloadFile($fileId);
    }
    public function downloadVideoNote(Message $message): string
    {
        $voice = $message->getVideoNote();
        if ($voice === null) {
            throw new \Exception('Message does not contain video note');
        }
        $fileId = $voice->getFileId();
        echo "Downloading video note with fileId $fileId\n";

        return $this->downloadFile($fileId);
    }

    /**
     * @param string $fileId
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function downloadFile(string $fileId): string
    {
        if (!is_dir(self::DOWNLOADED_FILES_CACHE_DIR)) {
            if (mkdir(self::DOWNLOADED_FILES_CACHE_DIR, recursive: true) || !is_dir(self::DOWNLOADED_FILES_CACHE_DIR)) {
                throw new \RuntimeException('Could not create downloaded files directory ' . self::DOWNLOADED_FILES_CACHE_DIR);
            }
        }
        $cacheFileName =self::DOWNLOADED_FILES_CACHE_DIR . '/' . mb_substr(str_replace(['.', '/'], ['_', '_'], $fileId), 0, 1024);
        if (file_exists($cacheFileName)) {
            $contents = file_get_contents($cacheFileName);
            if ($contents === false) {
                throw new \RuntimeException("Failed to read downloaded cache file: $cacheFileName. " . error_get_last()['message']);
            }
            echo "Reading file from cache: $cacheFileName\n";
            return $contents;
        }
        echo "Downloading file: $cacheFileName\n";
        $fileRequest = Request::getFile([
                                            'file_id' => $fileId,
                                        ]);
        $file = $fileRequest->getResult();
        if ($file === null) {
            throw new \Exception("Failed to get file info for fileId $fileId: " . $fileRequest->getDescription());
        }
        $baseDownloadUri = str_replace(
            '{API_KEY}',
            $this->telegramBotApiKey,
            'https://api.telegram.org/file/bot{API_KEY}'
        );
        $downloadResponse = $this->guzzle->get("{$baseDownloadUri}/{$file->getFilePath()}");
        if ($downloadResponse->getStatusCode() !== 200) {
            throw new \Exception("Failed to download file " . $file->getFilePath());
        }

        $contents = $downloadResponse->getBody()->getContents();
        echo "Finished downloading file: $cacheFileName";
        $putResult = file_put_contents($cacheFileName, $contents);

        if ($putResult === false) {
            throw new \Exception("Failed to write downloaded file: " . $file->getFilePath() . '. '. error_get_last()['message']);
        }
        return $contents;
    }

    public function downloadPhotoFromInternalMessage(InternalMessage $internalMessage): string
    {
        if ($internalMessage->photoFileId === null) {
            throw new \Exception('Message does not contain photos');
        }
        $fileId = $internalMessage->photoFileId;

        return $this->downloadFile($fileId);
    }
}
