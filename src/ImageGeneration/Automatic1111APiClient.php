<?php

namespace Perk11\Viktor89\ImageGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Psr\Http\Message\ResponseInterface;

class Automatic1111APiClient implements Prompt2ImgGenerator, PromptAndImg2ImgGenerator
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
        if (!isset($this->modelConfig['default'])) {
            throw new \Exception('"default" model is not defined');
        }
    }

    public function generateByPromptTxt2Img(string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        $params['prompt'] = $prompt;
        $response = $this->request('txt2img', $params);

        return Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
    }

    public function generatePromptAndImageImg2Img(string $imageContent, string $prompt, int $userId): Automatic1111ImageApiResponse
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

    /**
     * @param int $userId
     * @return mixed
     */
    private function getParamsBasedOnUserPreferences(int $userId): mixed
    {
        $modelName = $this->imageModelPreference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $modelName = 'default';
        }
        $params = $this->modelConfig[$modelName];
        if (array_key_exists('customUrl', $params)) {
            $apiUrl = rtrim($params['customUrl'], '/');
        } else {
            $apiUrl = rtrim($_ENV['AUTOMATIC1111_API_URL'], '/');
        }
        $this->httpClient = new Client(['base_uri' => $apiUrl]);

        $steps = $this->stepsPreference->getCurrentPreferenceValue($userId);
        if ($steps !== null) {
            $params['steps'] = $steps;
        }
        $seed = $this->seedPreference->getCurrentPreferenceValue($userId);
        if ($seed !== null) {
            $params['seed'] = $seed;
        }
        $useOptions = array_key_exists('useOptions', $params) ? $params['useOptions'] : true;
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

}
