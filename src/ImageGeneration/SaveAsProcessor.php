<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;

class SaveAsProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImageRepository $imageRepository,
    )
    {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($messageChain->previous()?->photo === null) {
            $response = InternalMessage::asResponseTo($lastMessage);
            $response->messageText = "Используйте эту команду в ответ на фото";

            return new ProcessingResult($response, true);
        }
        $name = str_replace(['<img>', '</img>'], '', trim($lastMessage->messageText));
        if ($name === '') {
            $response = InternalMessage::asResponseTo($lastMessage);
            $response->messageText = "Напишите имя для сохранения после команды, например /saveas viktor89";
            return new ProcessingResult($response, true);
        }
        $photo = $this->telegramFileDownloader->downloadPhoto($messageChain->previous()?->photo);
        if ($this->imageRepository->save($name, $lastMessage->userId, $photo)) {
            return new ProcessingResult(null, true,'👌', $lastMessage);
        }


        $response = InternalMessage::asResponseTo($lastMessage);
        $response->messageText = "Это имя уже занято, используйте другое имя";
        return new ProcessingResult($response, true);
    }
}
