<?php

namespace Perk11\Viktor89\VoiceGeneration;

use GuzzleHttp\Client;
use InvalidArgumentException;

/**
 * Calls the ace-step inference-server's `/cover` endpoint: covers an uploaded source
 * song (audio-to-audio) with ACE-Step's `task_type=cover`. Mirrors SingApiClient's
 * contract (base64 audio in, {voice_data, info} out) and reuses TtsApiResponse.
 *
 * @see \inference-servers\ace-step\main.py `cover()`
 */
class CoverApiClient
{
    public function __construct(
        private readonly array $modelConfig,
        private readonly ?Client $httpClient = null,
    ) {
    }

    /**
     * @param string      $audio         Raw bytes of the source song being covered.
     * @param string      $prompt        The new cover style / caption (ACE-Step `caption`).
     * @param string      $modelName     Key from `coverModels` in config.json.
     * @param float       $coverStrength ACE-Step `audio_cover_strength` (0.0–1.0); 1.0 = full re-render,
     *                                   ~0.2 = style transfer.
     * @param string|null $lyrics        Optional new lyrics; null/blank → let ACE-Step keep defaults.
     * @param int|null    $duration      Desired length in milliseconds, or null to let the model decide.
     * @param int|null    $seed          Reproducibility seed, or null for a random one.
     * @param float|null  $coverNoise    ACE-Step `cover_noise_strength` (0.0–1.0): how closely the
     *                                   denoising starts from the source audio. null → let the wrapper
     *                                   apply its default (0.7), so a faithful cover is produced without
     *                                   ACE-Step's "pure noise, no cover" default of 0.0.
     */
    public function cover(
        string $audio,
        string $prompt,
        string $modelName,
        float $coverStrength = 1.0,
        ?string $lyrics = null,
        ?int $duration = null,
        ?int $seed = null,
        ?float $coverNoise = null,
    ): TtsApiResponse {
        if (!array_key_exists($modelName, $this->modelConfig)) {
            throw new InvalidArgumentException(
                "Unknown cover model: $modelName. Available: " . implode(', ', array_keys($this->modelConfig)),
            );
        }

        $payload = [
            'audio' => base64_encode($audio),
            'prompt' => $prompt,
            'cover_strength' => $coverStrength,
        ];
        if ($lyrics !== null && $lyrics !== '') {
            $payload['lyrics'] = $lyrics;
        }
        if ($duration !== null) {
            $payload['duration'] = $duration;
        }
        if ($seed !== null) {
            $payload['seed'] = $seed;
        }
        if ($coverNoise !== null) {
            $payload['cover_noise'] = $coverNoise;
        }

        $apiUrl = rtrim($this->modelConfig[$modelName]['url'], '/');
        $httpClient = $this->httpClient ?? new Client();
        $response = $httpClient->post("$apiUrl/cover", ['json' => $payload]);

        return TtsApiResponse::fromString($response->getBody()->getContents());
    }
}
