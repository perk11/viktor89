<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
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

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        $chatId = $lastMessage->chatId;

        $assistant = null;
        $modelName = $this->assistantPreference->getCurrentPreferenceValue($lastMessage->userId);
        if ($modelName !== null) {
            try {
                $candidate = $this->assistantFactory->getAssistantInstanceByName($modelName);
                // Honour per-model allowedChatIds: a model selected in one chat
                // must not be used in a chat it is restricted from.
                if ($this->assistantFactory->isModelNameAllowedInChat($modelName, $chatId)) {
                    $assistant = $candidate;
                }
            } catch (UnknownAssistantException) {
                // Fall through to the default for this chat.
            }
        }

        $assistant ??= $this->assistantFactory->getDefaultAssistantInstanceForChat($chatId);

        return $assistant->processMessageChain($messageChain, $progressUpdateCallback);
    }
}
