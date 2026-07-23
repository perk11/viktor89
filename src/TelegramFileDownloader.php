<?php

namespace Perk11\Viktor89;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class TelegramFileDownloader
{
    private readonly Client $guzzle;
    public function __construct(
        private readonly CacheFileManager $cacheFileManager,
        private readonly string $telegramBotApiKey,
        private readonly LoggerInterface $logger,
    )
    {
        $this->guzzle = new Client();
    }

    public function downloadVoice(Message $message): string
    {
        $voice = $message->getVoice();
        if ($voice === null) {
            throw new Exception('Message does not contain voice');
        }
        $fileId = $voice->getFileId();
        $this->logger->log(LogLevel::INFO, "Downloading voice with fileId $fileId");

        return $this->downloadFile($fileId);
    }
    public function downloadVideoNote(Message $message): string
    {
        $voice = $message->getVideoNote();
        if ($voice === null) {
            throw new Exception('Message does not contain video note');
        }
        $fileId = $voice->getFileId();
        $this->logger->log(LogLevel::INFO, "Downloading video note with fileId $fileId");

        return $this->downloadFile($fileId);
    }

    /**
     * @param string $fileId
     * @return string
     * @throws GuzzleException
     */
    public function downloadFile(string $fileId): string
    {
        $cachedFileContents = $this->cacheFileManager->readFileFromCache($fileId);
        if ($cachedFileContents !== null) {
            return $cachedFileContents;
        }
        $this->logger->log(LogLevel::INFO, "Downloading file: $fileId");
        $fileRequest = Request::getFile([
                                            'file_id' => $fileId,
                                        ]);
        $file = $fileRequest->getResult();
        if ($file === null) {
            throw new Exception("Failed to get file info for fileId $fileId: " . $fileRequest->getDescription());
        }
        $baseDownloadUri = str_replace(
            '{API_KEY}',
            $this->telegramBotApiKey,
            'https://api.telegram.org/file/bot{API_KEY}'
        );
        $downloadResponse = $this->guzzle->get("{$baseDownloadUri}/{$file->getFilePath()}");
        if ($downloadResponse->getStatusCode() !== 200) {
            throw new Exception("Failed to download file " . $file->getFilePath());
        }

        $contents = $downloadResponse->getBody()->getContents();
        $this->cacheFileManager->writeFileToCache($fileId, $contents);

        return $contents;
    }

    public function downloadPhotoFromInternalMessage(InternalMessage $internalMessage): string
    {
        if ($internalMessage->photoFileId === null) {
            throw new Exception('Message does not contain photos');
        }
        $fileId = $internalMessage->photoFileId;

        return $this->downloadFile($fileId);
    }

}
