<?php

namespace Perk11\Viktor89\VideoGeneration;

use GuzzleHttp\Client;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Http\Message\ResponseInterface;

class Txt2VideoClient
{
    private Client $httpClient;
    private ?string $resolvedModelName = null;
    private bool $resolvedSupportsReferences = false;

    public function __construct(
        private readonly UserPreferenceReaderInterface $stepsPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $framesPreference,
        private readonly UserPreferenceReaderInterface $videoModelPreference,
        private readonly array $modelConfig,
    ){}

    /**
     * Generate a video from a prompt, optionally conditioned on reference images
     * and/or reference audios. Reference-capable models (supportsReferences in
     * their config) are served by the audio-img-txt2vid server and accept up to
     * 3 of each; anything beyond that is rejected before the request is sent.
     *
     * @param string[] $referenceImages raw image bytes (0–3)
     * @param string[] $referenceAudios raw audio bytes (0–3)
     */
    public function generateByPromptTxt2Vid(
        string $prompt,
        int $userId,
        array $referenceImages = [],
        array $referenceAudios = [],
        ?string $audioTrack = null,
    ): VideoApiResponse {
        $params = $this->getParamsBasedOnUserPreferences($userId);
        if(isset($params['promptPrefix']) && !str_starts_with($prompt, $params['promptPrefix'])) {
            $prompt = $params['promptPrefix'] . $prompt;
        }
        $params['prompt'] = $prompt;

        // A synchronized audio track plus the reference-only audios together
        // populate the model's reference-audio slots.
        $audios = $audioTrack !== null
            ? array_merge([$audioTrack], $referenceAudios)
            : $referenceAudios;
        // Only reference-capable models consume image/audio references; for any
        // other model the references are silently dropped (legacy behaviour),
        // so they must not be forwarded to a /txt2vid endpoint that cannot use
        // them.
        if ($this->resolvedSupportsReferences) {
            if (count($referenceImages) > 3 || count($audios) > 3) {
                throw new \InvalidArgumentException('At most 3 reference images and 3 reference audios are supported.');
            }
            if ($referenceImages !== []) {
                $params['init_images'] = array_map('base64_encode', $referenceImages);
            }
            if ($audios !== []) {
                $params['init_audios'] = array_map('base64_encode', $audios);
            }
        }

        $response = $this->request($this->endpointFor($referenceImages !== []), $params);

        $videoResponse = VideoApiResponse::fromString($response->getBody()->getContents());
        $videoResponse->modelName = $this->resolvedModelName;

        return $videoResponse;
    }

    /**
     * Reference-capable models live on the audio-img-txt2vid server, which
     * exposes separate image/no-image endpoints both backed by the MiniMax-H3
     * ref2vid workflow. Plain txt2vid models keep the legacy /txt2vid endpoint.
     */
    private function endpointFor(bool $hasImages): string
    {
        if (!$this->resolvedSupportsReferences) {
            return 'txt2vid';
        }

        return $hasImages ? 'audio_img_txt2vid' : 'audio_txt2vid';
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
        $this->resolvedSupportsReferences = (bool) ($params['supportsReferences'] ?? false);
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
