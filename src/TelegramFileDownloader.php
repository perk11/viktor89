<?php

namespace Perk11\Viktor89;

use GuzzleHttp\Client;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class TelegramFileDownloader
{
    private readonly Client $guzzle;
    public function __construct(
        private readonly CacheFileManager $cacheFileManager,
        private string $telegramBotApiKey
    )
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
        $cachedFileContents = $this->cacheFileManager->readFileFromCache($fileId);
        if ($cachedFileContents !== null) {
            return $cachedFileContents;
        }
        echo "Downloading file: $fileId\n";
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
        $this->cacheFileManager->writeFileToCache($fileId, $contents);

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
