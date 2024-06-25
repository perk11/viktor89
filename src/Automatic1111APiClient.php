<?php

namespace Perk11\Viktor89;

use GuzzleHttp\Client;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Psr\Http\Message\ResponseInterface;

class Automatic1111APiClient
{
    private readonly Client $httpClient;

    public function __construct(private readonly UserPreferenceSetByCommandProcessor $denoisingStrengthPreference)
    {
        if (!isset($_ENV['AUTOMATIC1111_API_URL'])) {
            throw new \Exception('Environment variable AUTOMATIC1111_API_URL is not defined');
        }
        $this->httpClient = new Client([
                                           'base_uri' => rtrim($_ENV['AUTOMATIC1111_API_URL'], '/'),
                                       ]);
    }
    public function getPngContentsByPromptTxt2Img(string $prompt): string
    {
        $response = $this->request('txt2img', [
            'model' => 'sd_xl_base_1.0_0.9vae.safetensors',
            'prompt' => $prompt,
            'width' => 1024,
            'height' => 1024,
            'sampler_name' => 'DPM++ 2M',
            'steps' => 30,
            'refiner_checkpoint' => 'sd_xl_refiner_1.0_0.9vae.safetensors',
            'refiner_switch_at' => 0.8,
        ]);
        $decoded = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('images', $decoded) || !is_array($decoded['images'])) {
            throw new \RuntimeException("Unexpected response from Automatic1111 API:\n" . $response->getBody());
        }
        return base64_decode($decoded['images'][0]);
    }

    public function getPngContentsByPromptAndImageImg2Img(string $imageContent, string $prompt, int $userId): string
    {
        $response = $this->request('img2img', [
            'init_images' => [
                base64_encode($imageContent),
            ],
            'model' => 'sd_xl_base_1.0_0.9vae.safetensors',
            'prompt' => $prompt,
            'width' => 1024,
            'height' => 1024,
            'sampler_name' => 'DPM++ 2M',
            'steps' => 30,
            'denoising_strength' => (float) ($this->denoisingStrengthPreference->getCurrentPreferenceValue($userId) ?? '0.75'),
        ]);
        $decoded = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('images', $decoded) || !is_array($decoded['images'])) {
            throw new \RuntimeException("Unexpected response from Automatic1111 API:\n" . $response->getBody());
        }
        return base64_decode($decoded['images'][0]);

    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post('/sdapi/v1/' . urlencode($method), [
            'json' => $data,
        ]);
    }

}
