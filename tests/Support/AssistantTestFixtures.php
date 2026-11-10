<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test\Support;

use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Util\Telegram\ChatAction;

use function Amp\delay;

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

class RecordingProgressUpdateCallback implements ProgressUpdateCallback
{
    /** @var list<array{string, string}> processor and status of every update */
    public array $updates = [];
    /** @var list<?ChatAction> */
    public array $chatActions = [];

    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
    {
        $this->updates[] = [$processor, $status];
        $this->chatActions[] = $chatAction;
    }

    public function subscribe(callable $subscriber): void
    {
    }
}

/** Tool executor that waits on the event loop, logging when it starts and finishes. */
class DelayingToolCallExecutor implements ToolCallExecutorInterface
{
    public function __construct(
        private readonly string $name,
        private readonly float $delaySeconds,
        private readonly \ArrayObject $events,
    ) {
    }

    public function executeToolCall(array $arguments): array
    {
        $this->events[] = "$this->name started";
        delay($this->delaySeconds);
        $this->events[] = "$this->name finished";

        return ['tool' => $this->name];
    }
}
