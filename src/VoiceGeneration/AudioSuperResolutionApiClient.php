<?php

namespace Perk11\Viktor89\VoiceGeneration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

/**
 * Calls the audio-sr inference-server's `/enhance` endpoint: runs AudioSR
 * (https://github.com/haoheliu/versatile_audio_super_resolution) over an audio file to
 * up-mix it to high-fidelity 48 kHz. Same contract as the other voice clients (base64
 * audio in, {voice_data, info} out) and reuses TtsApiResponse.
 *
 * Used as an optional post-processing step in /sing: a `singModels` entry with
 * `audioSR: true` sends the generated song here before it is posted to Telegram. The
 * server URL is the top-level `audioSuperResolutionUrl` config key.
 *
 * @see \inference-servers\audio-sr\main.py `enhance()`
 */
class AudioSuperResolutionApiClient
{
    public function __construct(
        private readonly string $url,
        private readonly ?Client $httpClient = null,
    ) {
    }

    /**
     * @param string   $audio         Raw bytes of the audio to super-resolve (any format
     *                                the server's ffmpeg can decode, e.g. OGG/Opus).
     * @param int|null $seed          Reproducibility seed, or null for a random one.
     * @param int|null $ddimSteps     DDIM sampling steps; null = server default.
     * @param float|null $guidanceScale CFG guidance; null = server default.
     */
    public function enhance(
        string $audio,
        ?int $seed = null,
        ?int $ddimSteps = null,
        ?float $guidanceScale = null,
    ): TtsApiResponse {
        $payload = ['audio' => base64_encode($audio)];
        if ($seed !== null) {
            $payload['seed'] = $seed;
        }
        if ($ddimSteps !== null) {
            $payload['ddim_steps'] = $ddimSteps;
        }
        if ($guidanceScale !== null) {
            $payload['guidance_scale'] = $guidanceScale;
        }

        $apiUrl = rtrim($this->url, '/');
        $httpClient = $this->httpClient ?? new Client();
        /** @var Response $response */
        $response = $httpClient->post("$apiUrl/enhance", ['json' => $payload]);

        return TtsApiResponse::fromString($response->getBody()->getContents());
    }
}
