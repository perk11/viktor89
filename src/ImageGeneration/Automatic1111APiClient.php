<?php

namespace Perk11\Viktor89\ImageGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class Automatic1111APiClient implements ImageByPromptGenerator, ImageByPromptAndImageGenerator
{
    private Client $httpClient;

    public function __construct(
        private readonly UserPreferenceReaderInterface $denoisingStrengthPreference,
        private readonly UserPreferenceReaderInterface $stepsPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $imageModelPreference,
        private readonly array $modelConfig,
        private readonly UserPreferenceReaderInterface $imageSizeProcessor,
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
        $params = $this->getParamsBasedOnUserPreferences($userId, ImageGenerationType::txt2img);

        return $this->generateImageByPromptAndModelParams($prompt, $params);
    }

    public function generateImageByPromptAndModelParams(string $prompt, array $params): Automatic1111ImageApiResponse
    {
        if (isset($params['promptPrefix']) && !str_starts_with($prompt, $params['promptPrefix'])) {
            $prompt = $params['promptPrefix'] . $prompt;
            unset($params['promptPrefix']);
        }
        if (isset($params['promptSuffix']) && !str_ends_with($prompt, $params['promptSuffix'])) {
            $prompt .= $params['promptSuffix'];
            unset($params['promptSuffix']);
        }

        $params['prompt'] = $prompt;
        $sendAsFile = false;
        if (isset($params['sendAsFile'])) {
            $sendAsFile = $params['sendAsFile'];
            unset($params['sendAsFile']);
        }
        $this->processParamsAndInitHttpClient($params);
        unset($params['assistantPrompt'], $params['txt2img'], $params['img2img'], $params['useOptions']);
        $response = $this->request('txt2img', $params);

        $a111response = Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
        $a111response->sendAsFile = $sendAsFile;

        return $a111response;
    }

    public function generateImageByPromptAndImages(array $imageContents, string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId, ImageGenerationType::img2img);
        $params = $this->processParamsAndInitHttpClient($params);
        if (isset($params['promptPrefix'])) {
            $prompt = $params['promptPrefix'] . $prompt;
            unset($params['promptPrefix']);
        }
        if (isset($params['promptSuffix'])) {
            $prompt .= $params['promptSuffix'];
            unset($params['promptSuffix']);
        }
        $sendAsFile = false;
        if (isset($params['sendAsFile'])) {
            $sendAsFile = $params['sendAsFile'];
            unset($params['sendAsFile']);
        }
        unset($params['assistantPrompt'], $params['txt2img'], $params['img2img'], $params['useOptions']);
        $params['init_images'] = [];
        foreach ($imageContents as $imageContent) {
            $params['init_images'][] = base64_encode($imageContent);
        }
        $params['prompt'] = $prompt;
        $params['denoising_strength'] = (float) ($this->denoisingStrengthPreference->getCurrentPreferenceValue($userId) ?? '0.75');
        unset($params['refiner_checkpoint'], $params['refiner_switch_at']);
        $response = $this->request('img2img', $params);
        $a111response = Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
        $a111response->sendAsFile = $sendAsFile;

        return $a111response;
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

    private function getParamsBasedOnUserPreferences(int $userId, ImageGenerationType $generationType): array
    {
        $modelName = $this->imageModelPreference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $params = current($this->modelConfig);
        } else {
            $params = $this->modelConfig[$modelName];
        }
        if (!$this->modelSupportsGeneration($params, $generationType)) {
            $params = $this->getFallBackModelParams($generationType);
        }
        $steps = $this->stepsPreference->getCurrentPreferenceValue($userId);
        if ($steps !== null) {
            $params['steps'] = $steps;
        }
        $seed = $this->seedPreference->getCurrentPreferenceValue($userId);
        if ($seed !== null) {
            $params['seed'] = $seed;
        }
        $imageSize = $this->imageSizeProcessor->getCurrentPreferenceValue($userId);
        if ($imageSize !== null && str_contains($imageSize, 'x')) {
            $sizeObject = $this->parseImageSizeString($imageSize);
            $params['width'] = $sizeObject->width;
            $params['height'] = $sizeObject->height;
        }

        return $params;
    }
    private function modelSupportsGeneration(array $modelConfig, ImageGenerationType $generationType): bool
    {
        if ($generationType === ImageGenerationType::txt2img) {
            if (!isset($modelConfig['txt2img'])) {
                return true;
            }
            if ($modelConfig['txt2img'] === true) {
                return true;
            }
        } elseif ($generationType === ImageGenerationType::img2img) {
            if (!isset($modelConfig['img2img'])) {
                return false;
            }
            if ($modelConfig['img2img'] === true) {
                return true;
            }
        } else {
            throw new \LogicException("Unexpected generation type");
        }
        return false;
    }
    private function getFallBackModelParams(ImageGenerationType $generationType): array
    {
        foreach ($this->modelConfig as $modelConfig) {
            if ($this->modelSupportsGeneration($modelConfig, $generationType)) {
                return $modelConfig;
            }
        }
        throw new \RuntimeException("Failed to find " . $generationType->name . " fallback model");
    }


    private function parseImageSizeString(string $imageSize): ImageSize
    {
        $parts = explode('x', $imageSize);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Invalid image size string');
        }
        return new ImageSize((int) $parts[0], (int) $parts[1]);
    }

}
