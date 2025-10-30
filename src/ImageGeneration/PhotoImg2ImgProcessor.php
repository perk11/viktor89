<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;

class PhotoImg2ImgProcessor
{
    public function __construct(
        private readonly ImageGenerateProcessor $imageGenerateProcessor,
        private readonly ProcessingResultExecutor $processingResultExecutor,
    ) {
    }

    public function processMessage(Message $message, ProgressUpdateCallback $progressUpdateCallback): void
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
        $internalMessage = InternalMessage::fromTelegramMessage($message);
        $result = $this->imageGenerateProcessor->processMessageChain(new MessageChain([$internalMessage]), $progressUpdateCallback);
        $this->processingResultExecutor->execute($result);
    }
}
