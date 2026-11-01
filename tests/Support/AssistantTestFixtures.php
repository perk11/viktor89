<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test\Support;

use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TestImages
{
    public static function png(int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function jpeg(int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public static function webp(int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }
}

/** Tool executor that records the arguments of every call and returns a fixed result. */
class RecordingToolCallExecutor implements ToolCallExecutorInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public int $callCount {
        get => count($this->calls);
    }

    public function __construct(private readonly array $result = ['status' => 'image_succesfully_generated_and_sent_to_user'])
    {
    }

    public function executeToolCall(array $arguments): array
    {
        $this->calls[] = $arguments;

        return $this->result;
    }
}
