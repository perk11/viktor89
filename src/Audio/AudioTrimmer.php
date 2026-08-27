<?php

namespace Perk11\Viktor89\Audio;

/**
 * Crops audio via ffmpeg, e.g. to skip the leading seconds of an audio
 * referenced with an offset suffix (<audio>Name:5.123</audio>) before it is
 * fed to a model.
 */
class AudioTrimmer
{
    /**
     * Returns $audioBytes without its first $seconds, re-encoded as OGG/Opus
     * (the container the inference servers already accept). Input seeking via
     * -ss before -i keeps this fast for any decodable format.
     */
    public function trimLeadingSeconds(string $audioBytes, float $seconds): string
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'viktor89-atrim-src');
        $targetPath = tempnam(sys_get_temp_dir(), 'viktor89-atrim') . '.ogg';
        try {
            if (file_put_contents($sourcePath, $audioBytes) === false) {
                throw new \RuntimeException('Failed to write audio to a temporary file');
            }
            $command = sprintf(
                'ffmpeg -y -hide_banner -loglevel error -ss %.3F -i %s -c:a libopus -application audio -vbr on -b:a 128k %s 2>&1',
                $seconds,
                escapeshellarg($sourcePath),
                escapeshellarg($targetPath),
            );
            exec($command, $output, $exitCode);
            if ($exitCode !== 0 || !is_file($targetPath)) {
                throw new \RuntimeException('Failed to trim audio: ' . implode("\n", $output));
            }
            $trimmed = file_get_contents($targetPath);
            if ($trimmed === false || $trimmed === '') {
                throw new \RuntimeException('Trimming audio produced no output (offset past the end of the audio?)');
            }

            return $trimmed;
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }
}
