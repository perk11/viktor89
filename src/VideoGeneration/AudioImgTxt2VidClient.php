<?php

namespace Perk11\Viktor89\VideoGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class AudioImgTxt2VidClient
{
    private Client $httpClient;
    private ?string $resolvedModelName = null;

    public function __construct(
        private readonly UserPreferenceReaderInterface $stepsPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly array $modelConfig,
    ) {
    }

    public function generateByPromptImageAndAudio(string $audioContent, ?string $imageContent, string $prompt, int $userId): VideoApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        $params['init_audios'] = [base64_encode($audioContent)];
        if ($imageContent === null) {
            $uri = 'audio_txt2vid';
        } else {
            $uri = 'audio_img_txt2vid';
            $params['init_images'] = [base64_encode($imageContent)];
        }
        $params['prompt'] = $prompt;
        $response = $this->request($uri, $params);

        $videoResponse = VideoApiResponse::fromString($response->getBody()->getContents());
        $videoResponse->modelName = $this->resolvedModelName;

        return $videoResponse;
    }

    /**
     * @param int $userId
     * @return mixed
     */
    private function getParamsBasedOnUserPreferences(int $userId): mixed
    {
        $modelName = $this->resolveModelName();
        $this->resolvedModelName = $modelName;
        $params = $modelName !== null ? $this->modelConfig[$modelName] : current($this->modelConfig);
        $apiUrl = rtrim($params['url'], '/');
        unset ($params['url']);
        $this->httpClient = new Client(['base_uri' => $apiUrl]);

        $steps = $this->stepsPreference->getCurrentPreferenceValue($userId);
        if ($steps !== null) {
            $params['steps'] = $steps;
        }
        $seed = $this->seedPreference->getCurrentPreferenceValue($userId);
        if ($seed !== null) {
            $params['seed'] = $seed;
        }

        return $params;
    }

    /**
     * The single configured audio+image→video model (first entry).
     */
    private function resolveModelName(): ?string
    {
        $fallback = array_key_first($this->modelConfig);

        return $fallback !== false ? $fallback : null;
    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post(urlencode($method), [
            'json' => $data,
        ]);
    }
}
