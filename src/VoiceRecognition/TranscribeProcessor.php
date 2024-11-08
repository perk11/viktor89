<?php

namespace Perk11\Viktor89\VoiceRecognition;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class TranscribeProcessor implements MessageChainProcessor
{

    public function __construct(
        private readonly InternalMessageTranscriber $internalMessageTranscriber,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    'Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео'
                ), true
            );
        }
        try {
            $transcribedText = $this->internalMessageTranscriber->transcribe($messageChain->previous());
        } catch (NothingToTranscribeException) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    'В сообщении, на которое вы отвечаете, не найдено аудио или видео. Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео'
                ), true
            );
        }
        if ($transcribedText !== null && $transcribedText !== '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    $transcribedText,
                ), true
            );
        }

        return new ProcessingResult(
            null, true,
            '🤔',
            $messageChain->last()
        );
    }
}
