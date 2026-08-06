<?php

namespace Perk11\Viktor89\VideoGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class Img2VideoClient
{
    private Client $httpClient;
    private ?string $resolvedModelName = null;

    public function __construct(
        private readonly UserPreferenceReaderInterface $stepsPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $framesPreference,
        private readonly UserPreferenceReaderInterface $img2VideoModelPreference,
        private readonly array $modelConfig,
    ){}
    public function generateByPromptImg2Vid( string $imageContent, string $prompt, int $userId, ?string $modelName = null): VideoApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId, $modelName);
        $params['init_images'] = [base64_encode($imageContent)];
        $params['prompt'] = $prompt;
        $response = $this->request('img2vid', $params);

        $videoResponse = VideoApiResponse::fromString($response->getBody()->getContents());
        $videoResponse->modelName = $this->resolvedModelName;

        return $videoResponse;
    }
    /**
     * @param int $userId
     * @return mixed
     */
    private function getParamsBasedOnUserPreferences(int $userId, ?string $forcedModelName = null): mixed
    {
        $modelName = $this->resolveModelName($userId, $forcedModelName);
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
        $frames = $this->framesPreference->getCurrentPreferenceValue($userId);
        if ($frames !== null) {
            $params['num_frames'] = $frames;
        }
        return $params;
    }

    /**
     * The forced model when given, otherwise the user's selected model, falling
     * back to the first configured model when there is no preference or it is
     * unknown.
     */
    private function resolveModelName(int $userId, ?string $forcedModelName = null): ?string
    {
        $modelName = $forcedModelName ?? $this->img2VideoModelPreference->getCurrentPreferenceValue($userId);
        if ($modelName !== null && array_key_exists($modelName, $this->modelConfig)) {
            return $modelName;
        }

        $fallback = array_key_first($this->modelConfig);

        return $fallback !== false ? $fallback : null;
    }
    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post( urlencode($method), [
            'json' => $data,
        ]);
    }
}
