<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\TelegramFileDownloader;

class VideoImg2VidProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly Img2VideoClient $img2VideoClient,
        private readonly VideoResponder $videoResponder,
    ) {
    }

    public function respondWithImg2VidResultBasedOnPhotoInMessage(
        InternalMessage $messageWithPhoto,
        InternalMessage $messageWithCommand,
        string $prompt,
        ProgressUpdateCallback $progressUpdateCallback,
    ): void
    {
        Request::execute('setMessageReaction', [
            'chat_id'    => $messageWithCommand->chatId,
            'message_id' => $messageWithCommand->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            $progressUpdateCallback(static::class, "Downloading source photo for prompt: $prompt\n");
            $photoContents = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($messageWithPhoto);
            $progressUpdateCallback(static::class, "Generating img2vid for prompt: $prompt\n");
            Request::sendChatAction([
                                        'chat_id' => $messageWithCommand->chatId,
                                        'action'  => ChatAction::RECORD_VIDEO,
                                    ]);
            $videoResponse = $this->img2VideoClient->generateByPromptImg2Vid(
                $photoContents,
                $prompt,
                $messageWithCommand->userId,
            );
            $progressUpdateCallback(static::class, "Sending video for prompt: $prompt\n");
            $this->videoResponder->sendVideo(
                $messageWithCommand,
                $videoResponse->getFirstVideoAsMp4(),
                $videoResponse->getCaption(),
            );
        } catch (Exception $e) {
            echo "Failed to generate video:\n" . $e->getMessage(),
            Request::execute('setMessageReaction', [
                'chat_id'    => $messageWithCommand->chatId,
                'message_id' => $messageWithCommand->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }
    }
}
