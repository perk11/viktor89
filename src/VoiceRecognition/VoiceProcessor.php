<?php

namespace Perk11\Viktor89\VoiceRecognition;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Util\TelegramHtml;

class VoiceProcessor implements MessageChainProcessor
{

    public function __construct(
        private readonly InternalMessageTranscriber $internalMessageTranscriber,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        try {
            $transcribedText = $this->internalMessageTranscriber->transcribe($messageChain->last(), $progressUpdateCallback);
        } catch (NothingToTranscribeException) {
            return new ProcessingResult(null, false);
        }
        if ($transcribedText !== null && $transcribedText !== '') {

            $message = InternalMessage::asResponseTo($messageChain->last(), "<blockquote expandable>" . TelegramHtml::escape($transcribedText) . "</blockquote>");
            $message->parseMode = 'HTML';
            return new ProcessingResult($message, false);
        }

        return new ProcessingResult(null, false);
    }
}
