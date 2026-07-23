<?php

namespace Perk11\Viktor89\VoiceRecognition;

use CURLFile;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VoiceRecogniser
{

    private array $hallucinations;
    public function __construct(
        private readonly string $whisperCppUri,
        private readonly LoggerInterface $logger,
    ) {
        $this->hallucinations = file(__DIR__ . '/hallucinations-ru.txt', FILE_IGNORE_NEW_LINES);
    }

    public function recogniseByFileContents(string $fileContents, string $extension): ?string
    {

        $tmpFilePath = tempnam(sys_get_temp_dir(), 'viktor89-voice-recognition');

        $tmpPathWithExtension = $tmpFilePath . '.' . $extension;
        rename($tmpFilePath, $tmpPathWithExtension);
        $this->logger->log(LogLevel::INFO, "Temporary audio recorded to $tmpPathWithExtension");

        file_put_contents($tmpPathWithExtension, $fileContents);
        $this->logger->log(LogLevel::INFO, 'Recognizing voice...');
        try {
            $result = $this->recogniseVoiceByFilePath($tmpPathWithExtension);
        } finally {
            unlink($tmpPathWithExtension);
        }

        return $result;
    }

    private function recogniseVoiceByFilePath(string $filePath): ?string
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
            $this->logger->log(LogLevel::ERROR, 'Curl error: ' . curl_error($ch));
        } else {
            $this->logger->log(LogLevel::DEBUG, 'Response: ' . $response);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->log(LogLevel::ERROR, 'Got error from whisper.cpp');

            return null;
        }
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log(LogLevel::ERROR, 'Error parsing JSON from whisper.cpp');
            return null;
        }
        if (!isset($decodedResponse['text'])) {
            $this->logger->log(LogLevel::ERROR, 'Response from whisper.cpp does not contain "text" property');
            return null;
        }

        return trim($this->removeHallucinations($decodedResponse['text']));
    }
    private function removeHallucinations(string $sourceText): string
    {
        return str_replace($this->hallucinations, '', $sourceText);
    }
}
