<?php

namespace Perk11\Viktor89\VoiceGeneration;

use GuzzleHttp\Client;
use InvalidArgumentException;

/**
 * Calls the ace-step inference-server's `/cover` endpoint to re-render an uploaded source
 * song with new lyrics (ACE-Step `task_type=cover`). Mirrors SingApiClient's contract
 * (base64 audio in, {voice_data, info} out) and reuses TtsApiResponse.
 *
 * Nothing caller-controlled except the lyrics, duration and seed: the caption/genre is sent
 * blank (the server requires a non-empty `prompt`, so a single space is sent), `cover_strength`
 * is pinned to 0.0 (no source-anchored denoising) and `cover_noise` to 0.2 (the Gradio UI
 * cover default). The model is the single `updatelyricsModels` entry from config.json.
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
     * @param string   $audio    Raw bytes of the source song being re-rendered.
     * @param string   $lyrics   The new lyrics.
     * @param int|null $duration Desired length in milliseconds, or null to let the model decide.
     * @param int|null $seed     Reproducibility seed, or null for a random one.
     */
    public function cover(
        string $audio,
        string $lyrics,
        ?int $duration = null,
        ?int $seed = null,
    ): TtsApiResponse {
        if ($this->modelConfig === []) {
            throw new InvalidArgumentException('No cover models configured.');
        }
        $modelName = array_key_first($this->modelConfig);

        $payload = [
            'audio'  => base64_encode($audio),
            // The server requires a non-empty `prompt`; the genre is intentionally blank now.
            'prompt' => ' ',
            // cover_strength 0.0: /updatelyrics only swaps lyrics, so no source-anchored denoising.
            // cover_noise 0.2: the Gradio UI cover default — a recognisable seed with room to change.
            'cover_strength' => 0.0,
            'cover_noise'    => 0.2,
            'lyrics'         => $lyrics,
        ];
        if ($duration !== null) {
            $payload['duration'] = $duration;
        }
        if ($seed !== null) {
            $payload['seed'] = $seed;
        }

        $apiUrl = rtrim($this->modelConfig[$modelName]['url'], '/');
        $httpClient = $this->httpClient ?? new Client();
        $response = $httpClient->post("$apiUrl/cover", ['json' => $payload]);

        return TtsApiResponse::fromString($response->getBody()->getContents());
    }
}
