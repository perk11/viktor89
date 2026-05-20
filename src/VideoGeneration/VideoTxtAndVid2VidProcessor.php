<?php

namespace Perk11\Viktor89\VideoGeneration;

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

class VideoTxtAndVid2VidProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly TxtAndVid2VideoClient $txtAndVid2VideoClient,
        private readonly VideoResponder $videoResponder,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($lastMessage, 'Используйте эту команду в ответ на видео'), true
            );
        }
        if ($messageChain->previous()->video === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($lastMessage, 'Сообщение на которое вы отвечаете не содержит видео'), true
            );
        }
        $prompt = trim($lastMessage->messageText);
        if ($prompt === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'Добавьте промпт для редактирования видео после команды',
                ), true
            );
        }
        $progressUpdateCallback(
            static::class,
            "Donwloading source video",
            new ChatAction($lastMessage->chatId, ChatActionEnum::record_video)
        );
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
            $videoContents = $this->telegramFileDownloader->downloadFile($messageChain->previous()->video->getFileId());
        } catch (Exception $e) {
            echo "Failed to download video:\n" . $e->getMessage();

            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'Не удалось скачать видео, я не могу скачивать видео больше 20 Мб'
                ), true, '🤔', $lastMessage
            );
        }
        $progressUpdateCallback(
            static::class,
            "Generating txtAndVid2vid for prompt: $prompt",
            new ChatAction($lastMessage->chatId, ChatActionEnum::upload_video)
        );
        try {
            $videoResponse = $this->txtAndVid2VideoClient->generateByPromptAndVid(
                $videoContents,
                $prompt,
                $lastMessage->userId,
            );
            $progressUpdateCallback(static::class,"Sending video response for prompt: $prompt", new ChatAction($lastMessage->chatId, ChatActionEnum::upload_video));
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
