<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;

class VoiceResponder
{
    public function sendVoice(InternalMessage $messageToReplyTo, string $voiceOggContents): void
    {
        $temporaryVoiceFile = tempnam(sys_get_temp_dir(), 'viktor89-voice-generator');
        echo "Temporary voice recorded to $temporaryVoiceFile\n";
        file_put_contents($temporaryVoiceFile, $voiceOggContents);
        try {
            Request::sendVoice(
                [
                    'chat_id'             => $messageToReplyTo->chatId,
                    'reply_to_message_id' => $messageToReplyTo->replyToMessageId,
                    'voice'               => Request::encodeFile($temporaryVoiceFile),
                ]
            );
        } finally {
            echo "Deleting $temporaryVoiceFile\n";
            unlink($temporaryVoiceFile);
        }
    }
}
