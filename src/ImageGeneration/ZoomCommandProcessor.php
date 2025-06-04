<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class ZoomCommandProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly ImageTransformProcessor $imageTransformProcessor,
        private readonly FixedValuePreferenceProvider $zoomValuePreferenceProvider,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessageText = trim($messageChain->last()->messageText);
        if ($lastMessageText === '') {
            $zoomLevel = 2;
        } else {
            if (!is_numeric($lastMessageText) || !ctype_digit($lastMessageText)) {
                return $this->getUnexpectedValueError($messageChain);
            }
            $zoomLevel = (int) $lastMessageText;
            if ($zoomLevel > 14 || $zoomLevel < 1) {
                return $this->getUnexpectedValueError($messageChain);
            }
        }
        $this->zoomValuePreferenceProvider->value = $zoomLevel;

        return $this->imageTransformProcessor->processMessageChain($messageChain);
    }

    private function getUnexpectedValueError(MessageChain $messageChain): ProcessingResult
    {
        $responseMessage = InternalMessage::asResponseTo($messageChain->last());
        $responseMessage->messageText = "Неверный формат команды, после /zoom должно идти целое число от 1 до 14";
        return new ProcessingResult($responseMessage, true);
    }
}
