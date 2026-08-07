<?php

namespace Perk11\Viktor89\VideoGeneration;

/**
 * Extracts still frames from video bytes via ffmpeg. Currently only the last
 * frame is needed (so an LLM can reference the final frame of a chain video as
 * an image when generating a new video).
 */
class VideoFrameExtractor
{
    public function __construct(private readonly \Psr\Log\LoggerInterface $logger)
    {
    }

    /**
     * Returns the last frame of $videoBytes as PNG bytes.
     *
     * -sseof -3 seeks to 3 seconds before the end (clamped to 0 for shorter
     * clips) so only the tail is decoded, and -update 1 makes the image2 muxer
     * overwrite the single output file for every frame — so it ends up holding
     * the final decoded frame.
     */
    public function extractLastFrameAsPng(string $videoBytes): string
    {
        $videoPath = tempnam(sys_get_temp_dir(), 'viktor89-vframe-src');
        $framePath = tempnam(sys_get_temp_dir(), 'viktor89-vframe') . '.png';
        try {
            file_put_contents($videoPath, $videoBytes);
            $command = sprintf(
                'ffmpeg -y -hide_banner -loglevel error -sseof -3 -i %s -update 1 -q:v 2 %s 2>&1',
                escapeshellarg($videoPath),
                escapeshellarg($framePath),
            );
            exec($command, $output, $exitCode);
            if ($exitCode !== 0 || !is_file($framePath)) {
                throw new \RuntimeException('Failed to extract last video frame: ' . implode("\n", $output));
            }
            $png = file_get_contents($framePath);
            if ($png === false) {
                throw new \RuntimeException('Failed to read extracted video frame');
            }

            return $png;
        } finally {
            if (is_file($videoPath)) {
                @unlink($videoPath);
            }
            if (is_file($framePath)) {
                @unlink($framePath);
            }
        }
    }
}
