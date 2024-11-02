<?php

namespace Perk11\Viktor89;

use CURLFile;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;

class VoiceProcessor implements PreResponseProcessor
{

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly string $whisperCppUri
    ) {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getVoice() !== null) {
            $file = $this->telegramFileDownloader->downloadVoice($message);
            $extension = 'ogg';
        } elseif ($message->getVideoNote() !== null) {
            $file = $this->telegramFileDownloader->downloadVideoNote($message);
            $extension = 'mp4';
        } else {
            return false;
        }

        $tmpFilePath = tempnam(sys_get_temp_dir(), 'viktor89-voice');

        $tmpPathWithExtension = $tmpFilePath . '.' . $extension;
        rename($tmpFilePath, $tmpPathWithExtension);
        echo "Temporary audio recorded to $tmpPathWithExtension\n";

        file_put_contents($tmpPathWithExtension, $file);
        echo "Recognizing voice...\n";
        try {
            $result = $this->recogniseVoice($tmpPathWithExtension, 'voice.ogg');
        } finally {
            unlink($tmpPathWithExtension);
        }

        return $result;
    }

    private function recogniseVoice(string $filePath): ?string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, rtrim($this->whisperCppUri, '/') . "/inference");
        curl_setopt($ch, CURLOPT_POST, true);

        $data = [
            'file'            => new CURLFile($filePath),
            'temperature'     => '0.0',
            'language'        => 'ru',
            'response_format' => 'json',
        ];

        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo 'Curl error: ' . curl_error($ch) . "\n";
        } else {
            echo 'Response: ' . $response . "\n";
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "Got error from whisper.cpp\n";

            return null;
        }
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error parsing JSON from whisper.cpp\n";
            return null;
        }
        if (!isset($decodedResponse['text'])) {
            echo "Response from whisper.cpp does not contain \"text\" property";
            return null;
        }

        return $decodedResponse['text'];
    }
}
