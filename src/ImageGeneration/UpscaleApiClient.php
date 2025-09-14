<?php

namespace Perk11\Viktor89\ImageGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class UpscaleApiClient implements ImageByImageGenerator
{
    private Client $httpClient;

    public function __construct(
        private readonly UserPreferenceReaderInterface $stepsPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $upscaleModelPreference,
        private readonly array $modelConfig,
    ){}
    public function processImage(string $imageContent, int $userId, ?string $prompt = ''): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        $params['init_images'] = [base64_encode($imageContent)];
        $response = $this->request('/sdapi/v1/img2img', $params);

        return Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
    }

    /**
     * @param int $userId
     * @return mixed
     */
    private function getParamsBasedOnUserPreferences(int $userId): mixed
    {
        $modelName = $this->upscaleModelPreference->getCurrentPreferenceValue($userId);
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
