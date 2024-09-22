<?php

namespace Perk11\Viktor89\ImageGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Psr\Http\Message\ResponseInterface;

class Automatic1111APiClient implements ImageByPromptGenerator, ImageByPromptAndImageGenerator
{
    private Client $httpClient;

    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $denoisingStrengthPreference,
        private readonly UserPreferenceSetByCommandProcessor $stepsPreference,
        private readonly UserPreferenceSetByCommandProcessor $seedPreference,
        private readonly UserPreferenceSetByCommandProcessor $imageModelPreference,
        private readonly array $modelConfig,
    )
    {
        if (!isset($_ENV['AUTOMATIC1111_API_URL'])) {
            throw new \Exception('Environment variable AUTOMATIC1111_API_URL is not defined');
        }
        if (count($modelConfig) === 0) {
            throw new \Exception('At least one image model should be defined');
        }
    }

    public function generateImageByPrompt(string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);

        return $this->generateImageByPromptAndModelParams($prompt, $params);
    }

    public function generateImageByPromptAndModelParams(string $prompt, array $params): Automatic1111ImageApiResponse
    {
        if (isset($params['promptPrefix'])) {
            $prompt = $params['promptPrefix'] . $prompt;
            unset($params['promptPrefix']);
        }
        $params['prompt'] = $prompt;
        $this->processParamsAndInitHttpClient($params);
        $response = $this->request('txt2img', $params);

        return Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
    }

    public function generateImageByPromptAndImage(string $imageContent, string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        if (isset($params['promptPrefix'])) {
            $prompt = $params['promptPrefix'] . $prompt;
            unset($params['promptPrefix']);
        }
        $params['init_images'] = [base64_encode($imageContent)];
        $params['prompt'] = $prompt;
        $params['denoising_strength'] = (float) ($this->denoisingStrengthPreference->getCurrentPreferenceValue($userId) ?? '0.75');
        unset($params['refiner_checkpoint'], $params['refiner_switch_at']);
        $response = $this->request('img2img', $params);
        return Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post('/sdapi/v1/' . urlencode($method), [
            'json' => $data,
        ]);
    }

    private function processParamsAndInitHttpClient(array $params): array
    {
        unset($params['assistantPrompt']);
        if (array_key_exists('customUrl', $params)) {
            $apiUrl = rtrim($params['customUrl'], '/');
            unset ($params['customUrl']);
        } else {
            $apiUrl = rtrim($_ENV['AUTOMATIC1111_API_URL'], '/');
        }
        $this->httpClient = new Client(['base_uri' => $apiUrl]);

        if (array_key_exists('useOptions', $params)) {
            $useOptions = $params['useOptions'];
            unset($params['useOptions']);
        } else {
            $useOptions = true;
        }
        //TODO: improve this and add validation
        if ($useOptions) {
            $options = json_decode($this->httpClient->get('/sdapi/v1/options')->getBody()->getContents());
            if ($options !== null) {
                $options->sd_model_checkpoint = $params['model'];
                $this->httpClient->post('/sdapi/v1/options', ['json' => $options]);
            }
        }

        return $params;
    }

    private function getParamsBasedOnUserPreferences(int $userId): array
    {
        $modelName = $this->imageModelPreference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $params = current($this->modelConfig);
        } else {
            $params = $this->modelConfig[$modelName];
        }
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

}
