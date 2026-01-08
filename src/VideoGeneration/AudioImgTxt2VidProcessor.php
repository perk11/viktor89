<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;

class AudioImgTxt2VidProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly AudioImgTxt2VidClient $audioImgTxt2VidClient,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly VideoResponder $videoResponder,
    ) {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        $lastMessage = $messageChain->last();
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($lastMessage, 'Используйте эту команду в ответ на аудио. Добавьте промпт для генерации видео после команды. В промпте укажите исходное изображение сохранённое через /saveas в теге <img>image</img>'), true
            );
        }
        $audioFile = $messageChain->previous()->getMessageAudio();
        if ($audioFile === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($lastMessage, 'Сообщение на которое вы отвечаете не содержит аудио'), true
            );
        }
        $prompt = trim($lastMessage->messageText);
        if ($prompt === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'Добавьте промпт для генерации видео после команды. В промпте укажите исходное изображение сохранённое через /saveas в теге <img>image</img>',
                ), true
            );
        }
        $imageGenerationPrompt = new ImageGenerationPrompt($prompt);
        try {
            $imageGenerationPrompt = $this->imgTagExtractor->extractImageTags(
                $imageGenerationPrompt,
                'not implemented'
            );
        } catch (SavedImageNotFoundException $e) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    sprintf(
                        'Изображение с именем "%s" не найдено, создайте его используя команду /saveas',
                        $e->getMessage()
                    )
                ), true
            );
        }
        if (count($imageGenerationPrompt->sourceImagesContents) !== 1) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'В промпте укажите одно исходное изображение сохранённое через /saveas в теге <img>image</img>',
                ),
                true
            );
        }
        $progressUpdateCallback(static::class, "Donwloading source audio");
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
        Request::sendChatAction([
                                    'chat_id' => $messageChain->last()->chatId,
                                    'action'  => ChatAction::RECORD_VIDEO,
                                ]);
        try {
            $audioContents = $this->telegramFileDownloader->downloadFile($audioFile->fileId);
        } catch (Exception $e) {
            echo "Failed to download video:\n" . $e->getMessage();

            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'Не удалось скачать аудио, я не могу скачивать файлы больше 20 Мб'
                ), true, '🤔', $lastMessage
            );
        }
        $progressUpdateCallback(static::class, "Generating video based on image and audio for prompt: $prompt");

        try {
            $videoResponse = $this->audioImgTxt2VidClient->generateByPromptImageAndAudio(
                $audioContents,
                $imageGenerationPrompt->sourceImagesContents[0],
                $imageGenerationPrompt->text,
                $lastMessage->userId,
            );
            $progressUpdateCallback(static::class, "Sending video response for prompt: $prompt");
            $this->videoResponder->sendVideo(
                $lastMessage,
                $videoResponse->getFirstVideoAsMp4(),
                $videoResponse->getCaption(),
            );
        } catch (Exception $e) {
            echo "Failed to generate video:\n" . $e->getMessage() . "\n";

            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }

        return new ProcessingResult(null, true); //reaction is already set in sendVideo()
    }
}
