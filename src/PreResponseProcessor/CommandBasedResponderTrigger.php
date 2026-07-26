<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Exception;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\GetTriggeringCommandsInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class CommandBasedResponderTrigger implements MessageChainProcessor, GetTriggeringCommandsInterface
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly MessageChainProcessor $responder,
        private readonly LoggerInterface $logger,
        private readonly ?int $alsoTriggerOnResponsesToThisUserIdIfCommandIsInChain = null,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessageText = $messageChain->last()->messageText;
        $triggerFound = false;
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if ($this->textStartsWithCommand($lastMessageText, $triggeringCommand)) {
                $triggerFound = true;
                $messageChain->last()->messageText = trim(str_replace($triggeringCommand, '', $lastMessageText));
                break;
            }
        }

        if (!$triggerFound) {
            if (!$this->alsoTriggerOnResponsesToThisUserIdIfCommandIsInChain) {
                return new ProcessingResult(null, false);
            }
            if ($messageChain->previous()?->userId !== $this->alsoTriggerOnResponsesToThisUserIdIfCommandIsInChain) {
                return new ProcessingResult(null, false);
            }

            foreach ($messageChain->getMessages() as $message) {
                foreach ($this->triggeringCommands as $triggeringCommand) {
                    if ($this->textStartsWithCommand($message->messageText, $triggeringCommand)) {
                        $triggerFound = true;
                        $message->messageText = trim(
                            str_replace($triggeringCommand, '', $message->messageText)
                        );
                        break; //Do not break from the outer loop to remove the command from all the messages
                    }
                }
            }
            if (!$triggerFound) {
                return new ProcessingResult(null, false);
            }
        }

        try {
            return $this->responder->processMessageChain($messageChain, $progressUpdateCallback);
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Got error when getting response to message chain from " . get_class($this->responder) . ": " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new ProcessingResult(null, true, '🤔', $messageChain->last());
        }
    }

    public function getTriggeringCommands(): array
    {
        return $this->triggeringCommands;
    }

    /**
     * A command matches only when it stands at the start of the text as a
     * complete command token — i.e. followed by a word boundary — so that a
     * shorter command cannot swallow a longer one. Without this, /mvid would
     * match /mvideo (leaving "eo" as the argument) and /image would match
     * /images. Mirrors the \b used by MessageChainProcessorRunner's splitter.
     */
    private function textStartsWithCommand(string $text, string $command): bool
    {
        return preg_match('/^' . preg_quote($command, '/') . '\b/', $text) === 1;
    }
}
