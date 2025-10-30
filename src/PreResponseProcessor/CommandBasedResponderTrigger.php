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

class CommandBasedResponderTrigger implements MessageChainProcessor, GetTriggeringCommandsInterface
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly MessageChainProcessor $responder,
        private readonly ?int $alsoTriggerOnResponsesToThisUserIdIfCommandIsInChain = null,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessageText = $messageChain->last()->messageText;
        $triggerFound = false;
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($lastMessageText, $triggeringCommand)) {
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

            $firstMessageText = $messageChain->first()->messageText;
            foreach ($messageChain->getMessages() as $message) {
                foreach ($this->triggeringCommands as $triggeringCommand) {
                    if (str_starts_with($message->messageText, $triggeringCommand)) {
                        $triggerFound = true;
                        $message->messageText = trim(
                            str_replace($triggeringCommand, '', $firstMessageText)
                        );
                        break; //Do not break from the outer loop to remove the command from all the messages
                    }
                }
            }
            if (!$triggerFound) {
                return new ProcessingResult(null, false);
            }
        }

        Request::sendChatAction([
                                    'chat_id' => $messageChain->last()->chatId,
                                    'action'  => ChatAction::TYPING,
                                ]);

        try {
            return $this->responder->processMessageChain($messageChain, $progressUpdateCallback);
        } catch (Exception $e) {
            echo "Got error when getting response to message chain from " . get_class($this->responder) .": \n";
            echo $e->getMessage();
            echo $e->getTraceAsString();
            return new ProcessingResult(null, true, '🤔', $messageChain->last());
        }
    }

    public function getTriggeringCommands(): array
    {
        return $this->triggeringCommands;
    }
}
