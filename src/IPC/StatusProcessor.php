<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;
use DateTimeImmutable;
use DateTimeInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class StatusProcessor implements MessageChainProcessor
{
    /** Max characters of a task status shown in the table. */
    private const int MAX_STATUS_LENGTH = 200;

    public function __construct(private readonly Channel $channel)
    {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        $this->channel->send(new RunningTasksQueryMessage());
        $report = $this->channel->receive();
        if (!$report instanceof RunningTasksReportMessage) {
            throw new \LogicException("Unexpected message received: " . get_class($report));
        }
        if (count($report->runningTasks) === 0) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($messageChain->last(), '💤 Ничего не происходит'),
                true,
            );
        }

        $message = InternalMessage::asResponseTo($messageChain->last());
        $message->parseMode = 'RichMarkdown';
        $message->messageText = $this->buildMarkdown($report->runningTasks);

        return new ProcessingResult($message, true);
    }

    /**
     * @param RunningTask[] $runningTasks
     */
    private function buildMarkdown(array $runningTasks): string
    {
        $markdown = "## ⚙️ Запущенные задачи (" . count($runningTasks) . ")\n\n";
        $markdown .= "| # | Задача | Статус | Время |\n";
        $markdown .= "| ---: | --- | --- | ---: |\n";
        $index = 1;
        foreach ($runningTasks as $task) {
            $markdown .= sprintf(
                "| %d | **%s** | %s | `%s` |\n",
                $index,
                self::escapeTableCell($this->shortProcessorName($task->processor)),
                self::escapeTableCell($this->truncateStatus($task->message)),
                self::escapeTableCell($this->elapsedTimeString($task->startTime)),
            );
            $index++;
        }

        return trim($markdown);
    }

    private function shortProcessorName(string $fullyQualifiedClassName): string
    {
        $parts = explode('\\', $fullyQualifiedClassName);

        return end($parts);
    }

    private function truncateStatus(string $status): string
    {
        if (mb_strlen($status) <= self::MAX_STATUS_LENGTH) {
            return $status;
        }

        return mb_substr($status, 0, self::MAX_STATUS_LENGTH) . '…';
    }

    private function elapsedTimeString(DateTimeInterface $dateTime): string
    {
        $secondsBetweenNowAndStart = new DateTimeImmutable()->getTimestamp() - $dateTime->getTimestamp();
        $signPrefix = $secondsBetweenNowAndStart < 0 ? '-' : '';
        $absoluteSecondsBetweenNowAndStart = abs($secondsBetweenNowAndStart);
        $totalMinutesAcrossEntireDuration = intdiv($absoluteSecondsBetweenNowAndStart, 60);
        $remainingSecondsWithinCurrentMinute = $absoluteSecondsBetweenNowAndStart % 60;

        return $signPrefix
            . $totalMinutesAcrossEntireDuration
            . ':'
            . str_pad((string)$remainingSecondsWithinCurrentMinute, 2, '0', STR_PAD_LEFT);
    }

    private static function escapeTableCell(string $content): string
    {
        return str_replace('|', '\\|', $content);
    }
}
