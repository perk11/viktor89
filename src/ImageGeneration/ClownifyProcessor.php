<?php

namespace Perk11\Viktor89\ImageGeneration;

use Exception;
use Perk11\Viktor89\Util\Telegram\ReactionSetter;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ClownifyProcessor implements MessageChainProcessor
{

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ClownifyApiClient $clownifyApiClient,
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


        $this->logger->log(LogLevel::INFO, 'Clownifying image...');
        ReactionSetter::setMessageReaction($lastMessage, '👀');
        try {
            $progressUpdateCallback(static::class, "Generating first frame for video prompt: $newPrompt");
            $photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($messageChain->previous());
            $transformedPhotoResponse = $this->clownifyApiClient->clownifyImage(
                $photo,
                $lastMessage->userId,
            );
            $this->photoResponder->sendPhoto(
                $lastMessage,
                $transformedPhotoResponse->getFirstImageAsPng(),
                $transformedPhotoResponse->sendAsFile,
                $transformedPhotoResponse->getCaption(),
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate image:\n" . $e->getMessage());
            ReactionSetter::setMessageReaction($lastMessage, '🤔');
        }

        return new ProcessingResult(null, true);
    }
}
