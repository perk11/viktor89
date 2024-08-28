<?php

namespace Perk11\Viktor89\VideoGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Psr\Http\Message\ResponseInterface;

class Txt2VideoClient
{
    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $stepsPreference,
        private readonly UserPreferenceSetByCommandProcessor $seedPreference,
        private readonly UserPreferenceSetByCommandProcessor $videoModelPreference,
        private readonly array $modelConfig,
    ){}
    public function generateByPromptTxt2Vid(string $prompt, int $userId): VideoApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        $params['prompt'] = $prompt;
        $response = $this->request('txt2vid', $params);

        return VideoApiResponse::fromString($response->getBody()->getContents());
    }
    /**
     * @param int $userId
     * @return mixed
     */
    private function getParamsBasedOnUserPreferences(int $userId): mixed
    {
        $modelName = $this->videoModelPreference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $params = current($this->modelConfig);
        } else {
            $params = $this->modelConfig[$modelName];
        }
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
    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post( urlencode($method), [
            'json' => $data,
        ]);
    }
}
