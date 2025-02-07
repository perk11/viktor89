<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class ReactProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly UserPreferenceReaderInterface $enabledProcessor,
        private readonly string $reactEmoji,
    )
    {
    }
    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($this->enabledProcessor->getCurrentPreferenceValue($lastMessage->userId) === null) {
            return new ProcessingResult(null, false);
        }

        return new ProcessingResult(null, false, $this->reactEmoji, $lastMessage);
    }
}
