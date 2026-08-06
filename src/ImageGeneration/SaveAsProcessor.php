<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Audio;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;

class SaveAsProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImageRepository $imageRepository,
        private readonly AudioRepository $audioRepository,
    )
    {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        $name = str_replace(['<img>', '</img>'], '', trim($lastMessage->messageText));
        if ($name === '') {
            $response = InternalMessage::asResponseTo($lastMessage);
            $response->messageText = "Напишите имя для сохранения после команды, например /saveas viktor89";
            return new ProcessingResult($response, true);
        }

        $previous = $messageChain->previous();
        if ($previous?->photoFileId !== null) {
            $fileContents = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($previous);
            $saved = $this->imageRepository->save($name, $lastMessage->userId, $fileContents);
        } elseif ($previous !== null && $previous->audio !== null) {
            $fileContents = $this->telegramFileDownloader->downloadFile($previous->audio->getFileId());
            $saved = $this->audioRepository->save(
                $name,
                $lastMessage->userId,
                $fileContents,
                $this->audioExtension($previous->audio),
            );
        } else {
            $response = InternalMessage::asResponseTo($lastMessage);
            $response->messageText = "Используйте эту команду в ответ на фото или аудио";

            return new ProcessingResult($response, true);
        }

        if ($saved) {
            return new ProcessingResult(null, true, '👌', $lastMessage);
        }

        $response = InternalMessage::asResponseTo($lastMessage);
        $response->messageText = "Это имя уже занято, используйте другое имя";
        return new ProcessingResult($response, true);
    }

    private function audioExtension(Audio $audio): string
    {
        $fileName = $audio->getFileName();
        if ($fileName !== null) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            if ($extension !== '') {
                return $extension;
            }
        }

        return 'mp3';
    }
}
