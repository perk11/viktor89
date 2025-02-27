<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class UserSelectedAssistant implements MessageChainProcessor
{
    public function __construct(
        private readonly AssistantFactory $assistantFactory,
        private readonly UserPreferenceReaderInterface $assistantPreference,
    )
    {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain->last();

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

        return $assistant->processMessageChain($messageChain);
    }
}
