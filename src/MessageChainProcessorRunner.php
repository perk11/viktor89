<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;

class MessageChainProcessorRunner
{
    private ?string $_triggeringCommandsRegex = null;

    public function __construct(
        private readonly ProcessingResultExecutor $processingResultExecutor,
        /** @var MessageChainProcessor[] $messageChainProcessors */
        private readonly array $messageChainProcessors,
    ) {
    }

    /** @return string[] */
    private function getTriggeringCommands(): array
    {
        $triggeringCommands = [];
        foreach ($this->messageChainProcessors as $messageChainProcessor) {
            if ($messageChainProcessor instanceof GetTriggeringCommandsInterface) {
                foreach ($messageChainProcessor->getTriggeringCommands() as $triggeringCommand) {
                    $triggeringCommands[] = $triggeringCommand;
                }
            }
        }

        return $triggeringCommands;
    }

    /** @return string A regex that matches any triggering command at the start of a line, and captures everything until the next triggering command or the end of the string. */
    private function getTriggeringCommandsRegex(): string
    {
        if ($this->_triggeringCommandsRegex === null) {
            $escapedCommands = array_map(
                static fn (string $cmd) => preg_quote($cmd, '/'),
                $this->getTriggeringCommands()
            );

            $commandRegex = implode('|', $escapedCommands);

            $this->_triggeringCommandsRegex =
                '/^(' . $commandRegex . ')\b.*?(?=(?:\R(?:' . $commandRegex . ')\b)|\z)/ms';
        }

        return $this->_triggeringCommandsRegex;
    }

    private function runChainWithSingleOrNoCommandsInLastMessage(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): bool
    {
        foreach ($this->messageChainProcessors as $processor) {
            $processingResult = $processor->processMessageChain($messageChain, $progressUpdateCallback);
            $this->processingResultExecutor->execute($processingResult);

            if ($processingResult->abortProcessing) {
                echo get_class($processor) . " has aborted checking for other message chain processors\n";

                return true;
            }
        }

        return false;
    }

    public function run(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): bool
    {
        $lastMessage = $messageChain->last();

        if (!$lastMessage->isCommand()) {
            return $this->runChainWithSingleOrNoCommandsInLastMessage($messageChain, $progressUpdateCallback);
        }
        $matchCount = preg_match_all($this->getTriggeringCommandsRegex(), $lastMessage->messageText, $parts);
        if ($matchCount < 2) {
            return $this->runChainWithSingleOrNoCommandsInLastMessage($messageChain, $progressUpdateCallback);
        }
        $processed = false;
        foreach ($parts[0] as $part) {
            $extractedMessage = $lastMessage->withReplacedText(trim($part));
            $replacedChain = $messageChain->withReplacedLastMessage($extractedMessage);
            $processed = $this->runChainWithSingleOrNoCommandsInLastMessage($replacedChain, $progressUpdateCallback) || $processed;
            sleep(0.3); //Decrease a chance of hitting Telegram rate limits
        }

        return $processed;
    }
}
