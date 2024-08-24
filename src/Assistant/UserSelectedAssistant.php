<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class UserSelectedAssistant implements TelegramChainBasedResponderInterface
{
    public function __construct(
        private readonly AssistantFactory $assistantFactory,
        private readonly UserPreferenceSetByCommandProcessor $assistantPreference,
    )
    {
    }

    public function getResponseByMessageChain(array $messageChain): ?InternalMessage
    {
        $lastMessage = $messageChain[count($messageChain) - 1];

        $modelName = $this->assistantPreference->getCurrentPreferenceValue($lastMessage->userId);
        if ($modelName === null) {
            $assistant = $this->assistantFactory->getDefaultAssistantInstance();
        } else {
            try {
                $assistant = $this->assistantFactory->getAssistantInstanceByName($modelName);
            } catch (UnknownAssistantException) {
                $assistant = $this->assistantFactory->getDefaultAssistantInstance();
            }
        }

        return $assistant->getResponseByMessageChain($messageChain);
    }
}
