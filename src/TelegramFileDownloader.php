<?php

namespace Perk11\Viktor89;

use GuzzleHttp\Client;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class TelegramFileDownloader
{
    private readonly Client $guzzle;
    public function __construct(private string $telegramBotApiKey)
    {
        $this->guzzle = new Client();
    }
    public function downloadPhotoFromMessage(Message $message): string
    {
        $photos = $message->getPhoto();
        if (count($photos) === 0) {
            throw new \Exception('Message does not contain photos');
        }
        $fileId = end($photos)->getFileId();
        echo "Downloading photo with fileId $fileId\n";

        return $this->downloadFile($fileId);
    }

    public function downloadVoice(Message $message): string
    {
        $voice = $message->getVoice();
        if ($voice === null) {
            throw new \Exception('Message does not contain photos');
        }
        $fileId = $voice->getFileId();
        echo "Downloading voice with fileId $fileId\n";

        return $this->downloadFile($fileId);
    }

    /**
     * @param string $fileId
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function downloadFile(string $fileId): string
    {
        $photoFile = Request::getFile([
                                          'file_id' => $fileId,
                                      ])->getResult();
        $baseDownloadUri = str_replace(
            '{API_KEY}',
            $this->telegramBotApiKey,
            'https://api.telegram.org/file/bot{API_KEY}'
        );
        $downloadResponse = $this->guzzle->get("{$baseDownloadUri}/{$photoFile->getFilePath()}");
        if ($downloadResponse->getStatusCode() !== 200) {
            throw new \Exception("Failed to download file " . $photoFile->getFilePath());
        }

        return $downloadResponse->getBody()->getContents();
    }
}
