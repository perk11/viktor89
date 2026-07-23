<?php

namespace Perk11\Viktor89\VoiceGeneration;

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

class SoundAndPromptToTargetAndResidualProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly VoiceResponder $voiceResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly SoundAndPromptToTargetAndResidualApiClient $andPromptToTargetAndResidualApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback,
    ): ProcessingResult {
        $lastMessage = $messageChain->last();
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    'Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео. После команды напишите что надо извлечь, например /aextract man\'s voice'
                ), true
            );
        }
        $messageAudio = $messageChain->previous()->getMessageAudio();
        if ($messageAudio === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    'Не найдено аудио в сообщении, на которое вы отвечаете. Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео. После команды напишите что надо извлечь, например /aextract man\'s voice'
                ), true
            );
        }
        $prompt = trim($lastMessage->messageText);
        if ($prompt === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    'После команды напишите что надо извлечь, например /aextract man\'s voice'
                ), true
            );
        }

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
            $audioFile = $this->telegramFileDownloader->downloadFile($messageAudio->fileId);
            $chatAction = new ChatAction($lastMessage->chatId, ChatActionEnum::record_voice);
            $progressUpdateCallback(static::class, "Extracting $prompt from audio", $chatAction);
            $result = $this->andPromptToTargetAndResidualApiClient->soundAndPromptToTargetAndResidual(
                $prompt,
                $audioFile,
            );

            $progressUpdateCallback(static::class, "Sending target audio", $chatAction);
            $this->voiceResponder->sendVoice($lastMessage, $result->target);
            $progressUpdateCallback(static::class, "Sending residual audio", $chatAction);
            $this->voiceResponder->sendVoice($lastMessage, $result->residual);
            return new ProcessingResult(null , true, '😎', $lastMessage);
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to run aextract:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new ProcessingResult(null , true, '🤔', $lastMessage);
        }

    }
}
