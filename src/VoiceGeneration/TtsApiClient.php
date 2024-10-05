<?php

namespace Perk11\Viktor89\VoiceGeneration;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class TtsApiClient
{
    private Client $httpClient;

    public function __construct(
        private readonly array $modelConfig,
    ) {
    }

    public function text2Voice(string $prompt, ?string $voice, ?string $speakerId, string $language, string $outputFormat, ?string $speed, string $model): TtsApiResponse
    {
        $this->initClientBasedOnModel($model);
        $params = [
            'prompt'        => $prompt,
            'language'      => $language,
            'output_format' => $outputFormat,
        ];
        if ($speakerId !== null) {
            $params['speaker_id'] = $speakerId;
        }
        if ($speed !== null) {
            $params['speed'] = $speed;
        }
        if ($voice !== null) {
            $params['source_voice'] = base64_encode($voice);
            $params['source_voice_format'] = 'ogg';
        }
        $result = $this->request('txt2voice', $params);

        return TtsApiResponse::fromString($result->getBody()->getContents());
    }

    private function initClientBasedOnModel(string $model): void
    {
        $params = current($this->modelConfig); //todo: use model
        $apiUrl = rtrim($params['url'], '/');
        $this->httpClient = new Client(['base_uri' => $apiUrl]);
    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post(urlencode($method), [
            'json' => $data,
        ]);
    }
}
