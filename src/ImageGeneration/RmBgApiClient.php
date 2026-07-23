<?php

namespace Perk11\Viktor89\ImageGeneration;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RmBgApiClient implements ImageByImageGenerator
{
    private Client $httpClient;

    public function __construct(
        private readonly array $modelConfig,
        private readonly LoggerInterface $logger,
    ){}
    public function processImage(string $imageContent, int $userId,  ?string $prompt = ''): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        $this->logger->log(LogLevel::DEBUG, "Sending removebg request with params: " . json_encode($params, JSON_THROW_ON_ERROR));
        $params['init_images'] = [base64_encode($imageContent)];
        $response = $this->request('/sdapi/v1/img2img', $params);

        $response = Automatic1111ImageApiResponse::fromString($response->getBody()->getContents());
        $response->sendAsFile = true;

        return $response;
    }

    private function getParamsBasedOnUserPreferences(int $userId): array
    {
        $params = current($this->modelConfig);
        $apiUrl = rtrim($params['url'], '/');
        unset ($params['url']);
        $this->httpClient = new Client(['base_uri' => $apiUrl]);

        return $params;
    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post(urlencode($method), [
            'json' => $data,
        ]);
    }
}
