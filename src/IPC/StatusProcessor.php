<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;
use DateTimeImmutable;
use DateTimeInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

use function Amp\now;

class StatusProcessor implements MessageChainProcessor
{
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
            return new ProcessingResult(InternalMessage::asResponseTo($messageChain->last(), 'Ничего не происходит'), true);
        }
        $message = InternalMessage::asResponseTo($messageChain->last());
        $message->messageText = "Запущенные задачи:\n\n";
        $message->parseMode = 'HTML';
        $index = 1;
        foreach ($report->runningTasks as $task) {
            $processorNameParts = explode('\\', $task->processor);
            $message->messageText .= "$index: <b>" . htmlspecialchars(end($processorNameParts)) . "</b>: " . htmlspecialchars($task->message) . " (<i>". $this->elapsedTimeString($task->startTime) ."</i>)\n";
            $index++;
        }

        return new ProcessingResult($message, true);
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
}
