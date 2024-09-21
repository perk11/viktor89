<?php

namespace Perk11\Viktor89\VideoGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\PromptAndImg2ImgGenerator;
use Perk11\Viktor89\InternalMessage;
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
        array $photo,
        InternalMessage $messageWithCommand,
        string $prompt,
    ): void
    {
        echo "Generating img2vid for prompt: $prompt\n";
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
            $photoContents = $this->telegramFileDownloader->downloadPhoto($photo);
            $videoResponse = $this->img2VideoClient->generateByPromptImg2Vid(
                $photoContents,
                $prompt,
                $messageWithCommand->userId,
            );
            $this->videoResponder->sendVideo(
                $messageWithCommand,
                $videoResponse->getFirstVideoAsMp4(),
                $videoResponse->getCaption(),
            );
        } catch (\Exception $e) {
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
