<?php

namespace Perk11\Viktor89\VoiceGeneration;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class SoundAndPromptToTargetAndResidualApiClient
{
    private Client $httpClient;

    public function __construct(
        private readonly array $modelConfig,
    ) {
    }

    public function soundAndPromptToTargetAndResidual(string $prompt, string $audioFileContents): TargetAndResidualResponse
    {
        $this->initClientBasedOnModel(array_key_first($this->modelConfig));
        $params = [
            'prompt' => $prompt,
            'audio' => base64_encode($audioFileContents),
        ];

        $result = $this->request('sound-and-prompt-to-target-and-residual', $params);

        return TargetAndResidualResponse::fromString($result->getBody()->getContents());
    }

    private function initClientBasedOnModel(string $model): void
    {
        $params = $this->modelConfig[$model];
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
