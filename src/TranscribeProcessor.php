<?php

namespace Perk11\Viktor89;

class TranscribeProcessor implements MessageChainProcessor
{


    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly VoiceRecogniser $voiceRecogniser,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($lastMessage->replyToAudio !== null) {
            $fileId =  $lastMessage->replyToAudio->getFileId();
            $fileName = $lastMessage->replyToAudio->getFileName();
        } elseif ($lastMessage->replyToVideo !== null) {
            $fileId =  $lastMessage->replyToVideo->getFileId();
            $fileName = $lastMessage->replyToVideo->getFileName();
        } elseif ($lastMessage->replyToVoice !== null) {
            $fileId =  $lastMessage->replyToVoice->getFileId();
            $fileName = 'voice.ogg';
        } elseif ($lastMessage->replyToVideoNote !== null) {
            $fileId =  $lastMessage->replyToVoice->getFileId();
            $fileName = 'video.mp4';
        } else {
            return new ProcessingResult(InternalMessage::asResponseTo($lastMessage, 'Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео'), true);
        }
        echo "Transcribing file $fileName with fileId $fileId\n";
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

            $file = $this->telegramFileDownloader->downloadFile($fileId);
            $recognizedText = $this->voiceRecogniser->recogniseByFileContents($file, $extension);
            if ($recognizedText !== null && $recognizedText !== '') {
                return new ProcessingResult(
                    InternalMessage::asResponseTo(
                        $lastMessage,
                        $recognizedText,
                    ), true
                );
            }

        return new ProcessingResult(
          null, true,
          '🤔',
          $lastMessage
        );
    }
}
