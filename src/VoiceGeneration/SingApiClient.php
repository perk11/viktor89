<?php

namespace Perk11\Viktor89\VoiceGeneration;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class SingApiClient
{
    private Client $httpClient;

    public function __construct(
        private readonly array $modelConfig,
    ) {
    }

    public function txtTags2Music(string $lyrics, string $tags, string $model, ?int $durationMs = null): TtsApiResponse
    {
        $this->initClientBasedOnModel($model);

        $params = [
            'lyrics' => $lyrics,
            'tags'   => $tags,
            'model'  => $model,
        ];

        if ($durationMs !== null) {
            $params['duration'] = $durationMs;
        }

        $result = $this->request('txt_tags2music', $params);

        return TtsApiResponse::fromString($result->getBody()->getContents());
    }

    private function initClientBasedOnModel(string $model): void
    {
        $params = $this->modelConfig[$model] ?? null;
        if (!is_array($params) || !isset($params['url'])) {
            throw new \InvalidArgumentException(sprintf('Model "%s" is missing from configuration or has no "url".', $model));
        }

        $apiUrl = rtrim((string) $params['url'], '/');
        $this->httpClient = new Client(['base_uri' => $apiUrl]);
    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post(urlencode($method), [
            'json' => $data,
        ]);
    }
}
