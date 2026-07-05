<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use DateTimeImmutable;
use Perk11\Viktor89\IPC\RunningTask;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunningTask::class)]
class RunningTaskTest extends TestCase
{
    public function testConstructorWithAllFields(): void
    {
        $startTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $chatAction = new ChatAction(-100123, ChatActionEnum::typing);
        $actionTime = new DateTimeImmutable('2024-01-01 12:00:05');

        $task = new RunningTask('ImageProcessor', 'Generating image...', $startTime, $chatAction, $actionTime);

        $this->assertSame('ImageProcessor', $task->processor);
        $this->assertSame('Generating image...', $task->message);
        $this->assertSame($startTime, $task->startTime);
        $this->assertSame($chatAction, $task->chatAction);
        $this->assertSame($actionTime, $task->actionAddedTime);
    }

    public function testConstructorWithoutChatAction(): void
    {
        $startTime = new DateTimeImmutable('2024-06-15 08:00:00');

        $task = new RunningTask('TextProcessor', 'Writing text...', $startTime);

        $this->assertSame('TextProcessor', $task->processor);
        $this->assertNull($task->chatAction);
        $this->assertNull($task->actionAddedTime);
    }

    public function testConstructorWithOnlyChatAction(): void
    {
        $startTime = new DateTimeImmutable('2024-03-20 14:30:00');
        $chatAction = new ChatAction(-100456, ChatActionEnum::upload_photo);

        $task = new RunningTask('UploadProcessor', 'Uploading...', $startTime, $chatAction);

        $this->assertSame($chatAction, $task->chatAction);
        $this->assertNull($task->actionAddedTime);
    }

    public function testPropertiesAreReadonly(): void
    {
        $startTime = new DateTimeImmutable();
        $task = new RunningTask('Test', 'Test message', $startTime);

        $this->expectException(\Error::class);

        $task->processor = 'Changed';
    }

    public function testConstructorWithEmptyProcessor(): void
    {
        $startTime = new DateTimeImmutable();
        $task = new RunningTask('', 'Empty processor', $startTime);

        $this->assertSame('', $task->processor);
    }

    public function testConstructorWithLongMessage(): void
    {
        $startTime = new DateTimeImmutable();
        $message = str_repeat('word ', 1000);
        $task = new RunningTask('Processor', $message, $startTime);

        $this->assertSame($message, $task->message);
    }
}
