<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramFileDownloader;

class PhotoImg2ImgProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImageByPromptAndImageGenerator $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
    ) {
    }

    public function processMessage(Message $message): void
    {
        $caption = $message->getCaption();
        echo "Photo received with caption $caption\n";
        if (!str_contains($caption, '@' . $_ENV['TELEGRAM_BOT_USERNAME'])) {
            return;
        }

        $prompt = trim(
            str_replace(
                '@' . $_ENV['TELEGRAM_BOT_USERNAME'],
                '',
                $message->getCaption()
            )
        );
        if ($prompt === '') {
            return;
        }
        $this->respondWithImg2ImgResultBasedOnPhotoInMessage($message->getPhoto(), InternalMessage::fromTelegramMessage($message), $prompt);
    }

    public function respondWithImg2ImgResultBasedOnPhotoInMessage(
        array $photoArray,
        InternalMessage $messageToReplyTo,
        string $prompt,
    ): void
    {
        echo "Generating img2img for prompt: $prompt\n";
        Request::execute('setMessageReaction', [
            'chat_id'    => $messageToReplyTo->chatId,
            'message_id' => $messageToReplyTo->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            $photo = $this->telegramFileDownloader->downloadPhoto($photoArray);
            $transformedPhotoResponse = $this->automatic1111APiClient->generateImageByPromptAndImages(
                [$photo],
                $prompt,
                $messageToReplyTo->userId,
            );
            $this->photoResponder->sendPhoto(
                $messageToReplyTo,
                $transformedPhotoResponse->getFirstImageAsPng(),
                $transformedPhotoResponse->getCaption(),
            );
        } catch (\Exception $e) {
            echo "Failed to generate image:\n" . $e->getMessage(),
            Request::execute('setMessageReaction', [
                'chat_id'    => $messageToReplyTo->chatId,
                'message_id' => $messageToReplyTo->id,
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
