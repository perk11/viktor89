<?php

namespace Perk11\Viktor89\ImageGeneration;

use Exception;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ImageTransformProcessor implements MessageChainProcessor
{

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImageByImageGenerator $generator,
        private readonly PhotoResponder $photoResponder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($messageChain->previous()?->photoFileId === null) {
            $response = new InternalMessage();
            $response->chatId = $lastMessage->chatId;
            $response->replyToMessageId = $lastMessage->id;
            $response->messageText = "Используйте эту команду в ответ на фото";

            return new ProcessingResult($response, true);
        }


        $this->logger->log(LogLevel::INFO, 'Transforming image...');
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
        $progressUpdateCallback(static::class, "Downloading image");
        try {
            $photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($messageChain->previous());
            $progressUpdateCallback(static::class,
                                    'Performing image transformation ' . get_class($this->generator),
                                    new ChatAction($lastMessage->chatId, ChatActionEnum::upload_photo),
            );
            $transformedPhotoResponse = $this->generator->processImage(
                $photo,
                $lastMessage->userId,
                trim($lastMessage->messageText),
            );
            $progressUpdateCallback(static::class, "Sending photo response");
            $this->photoResponder->sendPhoto(
                $lastMessage,
                $transformedPhotoResponse->getFirstImageAsPng(),
                $transformedPhotoResponse->sendAsFile,
                $transformedPhotoResponse->getCaption(),
            );
        } catch (ImageGeneratorBadRequestException $e) {
            $responseMessage = InternalMessage::asResponseTo($lastMessage, $e->getMessage());
            return new ProcessingResult($responseMessage, true);
        } catch  (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate image:\n" . $e->getMessage());
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
