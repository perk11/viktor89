<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;

class DownscaleProcessor implements MessageChainProcessor
{

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly PhotoResponder $photoResponder,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($messageChain->previous()?->photoFileId === null) {
            $response = new InternalMessage();
            $response->chatId = $lastMessage->chatId;
            $response->replyToMessageId = $lastMessage->id;
            $response->messageText = "Используйте эту команду в ответ на фото";

            return new ProcessingResult($response, true);
        }


        echo "Downscaling image...\n";
        Request::execute('setMessageReaction', [
            'chat_id'    => $lastMessage->chatId,
            'message_id' => $lastMessage->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            $photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($messageChain->previous());
            $image = \imagecreatefromstring($photo);
            ob_start();
            \imagejpeg($image, null, 7);
            $jpegData = ob_get_clean();
            \imagedestroy($image);
            $this->photoResponder->sendPhoto(
                $lastMessage,
                $jpegData,
                false,
            );
        } catch (\Exception $e) {
            echo "Failed to generate image:\n" . $e->getMessage(),
            Request::execute('setMessageReaction', [
                'chat_id'    => $lastMessage->chatId,
                'message_id' => $lastMessage->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }

        return new ProcessingResult(null, true);
    }
}
