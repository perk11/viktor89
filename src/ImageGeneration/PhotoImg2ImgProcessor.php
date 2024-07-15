<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\TelegramPhotoDownloader;

class PhotoImg2ImgProcessor
{
    public function __construct(
        private readonly TelegramPhotoDownloader $telegramPhotoDownloader,
        private readonly PromptAndImg2ImgGenerator $automatic1111APiClient,
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
        $this->respondWithImg2ImgResultBasedOnPhotoInMessage($message, $message, $prompt);
    }

    public function respondWithImg2ImgResultBasedOnPhotoInMessage(
        Message $messageWithPhoto,
        Message $messageWithCommand,
        string $prompt,
    ): void
    {
        echo "Generating img2img for prompt: $prompt\n";
        Request::execute('setMessageReaction', [
            'chat_id'    => $messageWithCommand->getChat()->getId(),
            'message_id' => $messageWithCommand->getMessageId(),
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            $photo = $this->telegramPhotoDownloader->downloadPhotoFromMessage($messageWithPhoto);
            $transformedPhotoResponse = $this->automatic1111APiClient->generatePromptAndImageImg2Img(
                $photo,
                $prompt,
                $messageWithCommand->getFrom()->getId(),
            );
            $this->photoResponder->sendPhoto(
                $messageWithCommand,
                $transformedPhotoResponse->getFirstImageAsPng(),
                $transformedPhotoResponse->getCaption(),
            );
        } catch (\Exception $e) {
            echo "Failed to generate image:\n" . $e->getMessage(),
            Request::execute('setMessageReaction', [
                'chat_id'    => $messageWithCommand->getChat()->getId(),
                'message_id' => $messageWithCommand->getMessageId(),
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
