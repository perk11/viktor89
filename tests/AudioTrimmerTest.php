<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Audio\AudioTrimmer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AudioTrimmer::class)]
class AudioTrimmerTest extends TestCase
{
    private function requireFfmpeg(): void
    {
        exec('ffmpeg -version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ffmpeg is not available on PATH');
        }
    }

    public function testTrimsLeadingSeconds(): void
    {
        $this->requireFfmpeg();

        $sourcePath = tempnam(sys_get_temp_dir(), 'viktor89-atrim-test') . '.ogg';
        exec(
            'ffmpeg -y -hide_banner -loglevel error -f lavfi -i sine=frequency=440:duration=4 '
            . '-c:a libopus -b:a 96k ' . escapeshellarg($sourcePath) . ' 2>&1',
            $output,
            $exitCode,
        );
        $this->assertSame(0, $exitCode, implode("\n", $output));

        $trimmed = (new AudioTrimmer())->trimLeadingSeconds((string) file_get_contents($sourcePath), 2.0);

        exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($sourcePath), $probeOutput);
        @unlink($sourcePath);
        $sourceDuration = (float) $probeOutput[0];

        $trimmedPath = tempnam(sys_get_temp_dir(), 'viktor89-atrim-test') . '.ogg';
        file_put_contents($trimmedPath, $trimmed);
        exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($trimmedPath), $probeOutput);
        @unlink($trimmedPath);
        $trimmedDuration = (float) $probeOutput[0];

        $this->assertSame('OGG', mb_substr(trim((string) $trimmed), 0, 3));
        $this->assertEqualsWithDelta($sourceDuration - 2.0, $trimmedDuration, 0.3);
    }

    public function testThrowsWhenOffsetIsPastTheEndOfTheAudio(): void
    {
        $this->requireFfmpeg();

        $sourcePath = tempnam(sys_get_temp_dir(), 'viktor89-atrim-test') . '.ogg';
        exec(
            'ffmpeg -y -hide_banner -loglevel error -f lavfi -i sine=frequency=440:duration=1 '
            . '-c:a libopus -b:a 96k ' . escapeshellarg($sourcePath) . ' 2>&1',
            $output,
            $exitCode,
        );
        $this->assertSame(0, $exitCode, implode("\n", $output));

        try {
            (new AudioTrimmer())->trimLeadingSeconds((string) file_get_contents($sourcePath), 60.0);
            $this->fail('Expected an exception for an offset past the end of the audio');
        } finally {
            @unlink($sourcePath);
        }
    }
}
