<?php

namespace Perk11\Viktor89\VoiceRecognition;

use CURLFile;

class VoiceRecogniser
{

    public function __construct(
        private readonly string $whisperCppUri
    ) {
    }

    public function recogniseByFileContents(string $fileContents, string $extension): ?string
    {

        $tmpFilePath = tempnam(sys_get_temp_dir(), 'viktor89-voice-recognition');

        $tmpPathWithExtension = $tmpFilePath . '.' . $extension;
        rename($tmpFilePath, $tmpPathWithExtension);
        echo "Temporary audio recorded to $tmpPathWithExtension\n";

        file_put_contents($tmpPathWithExtension, $fileContents);
        echo "Recognizing voice...\n";
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
