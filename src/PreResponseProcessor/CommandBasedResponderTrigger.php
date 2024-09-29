<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class CommandBasedResponderTrigger implements MessageChainProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly bool $responsesAlsoTrigger,
        private readonly MessageChainProcessor $responder,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        if (!$this->responsesAlsoTrigger && $messageChain->count() > 1) {
            return new ProcessingResult(null, false);
        }
        $firstMessageText = $messageChain->first()->messageText;
        $triggerFound = false;
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($firstMessageText, $triggeringCommand)) {
                $triggerFound = true;
                $messageChain->first()->messageText = trim(str_replace($triggeringCommand, '', $firstMessageText));
                break;
            }
        }
        if (!$triggerFound) {
            return new ProcessingResult(null, false);
        }

        Request::sendChatAction([
                                    'chat_id' => $messageChain->last()->chatId,
                                    'action'  => ChatAction::TYPING,
                                ]);

        try {
            return $this->responder->processMessageChain($messageChain);
        } catch (\Exception $e) {
            echo "Got error when getting response to message chain from " . get_class($this->responder) .": \n";
            echo $e->getMessage();
            echo $e->getTraceAsString();
            return new ProcessingResult(null, true, '🤔', $messageChain->last());
        }
    }
}
