<?php

namespace Perk11\Viktor89\VideoGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class Txt2VideoClient
{
    private Client $httpClient;
    private ?string $resolvedModelName = null;

    public function __construct(
        private readonly UserPreferenceReaderInterface $stepsPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $framesPreference,
        private readonly UserPreferenceReaderInterface $videoModelPreference,
        private readonly array $modelConfig,
    ){}
    public function generateByPromptTxt2Vid(string $prompt, int $userId): VideoApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        if(isset($params['promptPrefix']) && !str_starts_with($prompt, $params['promptPrefix'])) {
            $prompt = $params['promptPrefix'] . $prompt;
        }
        $params['prompt'] = $prompt;
        $response = $this->request('txt2vid', $params);

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
        $modelName = $this->resolveModelName($userId);
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
     * The user's selected model, falling back to the first configured model when
     * there is no preference or it is unknown.
     */
    private function resolveModelName(int $userId): ?string
    {
        $modelName = $this->videoModelPreference->getCurrentPreferenceValue($userId);
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
