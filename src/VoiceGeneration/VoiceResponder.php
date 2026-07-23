<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VoiceResponder
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function sendVoice(InternalMessage $messageToReplyTo, string $voiceOggContents): void
    {
        $temporaryVoiceFile = tempnam(sys_get_temp_dir(), 'viktor89-voice-generator');
        $this->logger->log(LogLevel::INFO, "Temporary voice recorded to $temporaryVoiceFile");
        file_put_contents($temporaryVoiceFile, $voiceOggContents);
        try {
            Request::sendVoice(
                [
                    'chat_id'             => $messageToReplyTo->chatId,
                    'reply_to_message_id' => $messageToReplyTo->id,
                    'voice'               => Request::encodeFile($temporaryVoiceFile),
                ]
            );
        } finally {
            $this->logger->log(LogLevel::INFO, "Deleting $temporaryVoiceFile");
            unlink($temporaryVoiceFile);
        }
    }
}
