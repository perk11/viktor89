<?php

namespace Perk11\Viktor89\ImageGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class ZoomApiClient implements ImageByImageGenerator
{
    private Client $httpClient;

    public function __construct(
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $zoomLevelPreference,
        private readonly array $modelConfig,
    ){}
    public function processImage(string $imageContent, int $userId): Automatic1111ImageApiResponse
    {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        echo "Sending zoom request with params: ". json_encode($params, JSON_THROW_ON_ERROR) ."\n";
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
        $params = current($this->modelConfig);
        $apiUrl = rtrim($params['url'], '/');
        unset ($params['url']);
        $this->httpClient = new Client(['base_uri' => $apiUrl]);
        $seed = $this->seedPreference->getCurrentPreferenceValue($userId);
        if ($seed !== null) {
            $params['seed'] = $seed;
        }
        $zoomLevel =  $this->zoomLevelPreference->getCurrentPreferenceValue($userId);
        if ($zoomLevel === null) {
            $zoomLevel = 4;
        } else {
            $zoomLevel = (float)$zoomLevel;
        }
        $params['zoom_level'] = $zoomLevel;
        return $params;
    }

    private function request(string $method, array $data): ResponseInterface
    {
        return $this->httpClient->post(urlencode($method), [
            'json' => $data,
        ]);
    }
}
