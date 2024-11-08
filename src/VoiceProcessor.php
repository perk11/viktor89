<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;

class VoiceProcessor implements PreResponseProcessor
{

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly VoiceRecogniser $voiceRecogniser,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getVoice() !== null) {
            $file = $this->telegramFileDownloader->downloadVoice($message);
            $extension = 'ogg';
        } elseif ($message->getVideoNote() !== null) {
            $file = $this->telegramFileDownloader->downloadVideoNote($message);
            $extension = 'mp4';
        } else {
            return false;
        }

        return $this->voiceRecogniser->recogniseByFileContents($file, $extension);
    }
}
