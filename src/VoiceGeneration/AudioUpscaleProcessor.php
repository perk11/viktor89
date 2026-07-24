<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Exception;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Implements the `/audioupscale` command: reply it on an audio/voice/video/video-note
 * to run the file through the audio-sr inference server (AudioSR), up-mixing it to
 * high-fidelity 48 kHz. Requires the top-level `audioSuperResolutionUrl` config key, so
 * it is only wired up in ProcessMessageTask when that key is present. The `/seed`
 * preference (if set) is forwarded for reproducibility.
 */
class AudioUpscaleProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly AudioSuperResolutionApiClient $audioSuperResolutionApiClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();

        // /audioupscale must be a reply to the audio you want to enhance.
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'Для использования этой команды, ваше сообщение должно быть ответом на аудио, голосовое сообщение, кружок или видео.'
                ),
                true,
            );
        }
        $sourceAudio = $messageChain->previous()->getMessageAudio();
        if ($sourceAudio === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    'Не найдено аудио в сообщении, на которое вы отвечаете. Для использования этой команды, '
                    . 'ваше сообщение должно быть ответом на аудио, голосовое сообщение, кружок или видео.'
                ),
                true,
            );
        }

        $seedPref = $this->seedPreference->getCurrentPreferenceValue($lastMessage->userId);
        $seed = $seedPref === null ? null : (int) $seedPref;

        $progressUpdateCallback(
            static::class,
            'Enhancing the audio with AudioSR',
            new ChatAction($lastMessage->chatId, ChatActionEnum::record_voice),
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
            $audioFile = $this->telegramFileDownloader->downloadFile($sourceAudio->fileId);
            $response = $this->audioSuperResolutionApiClient->enhance($audioFile, $seed);
            $this->voiceResponder->sendVoice($lastMessage, $response->voiceFileContents);
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to upscale audio:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());

            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }

        return new ProcessingResult(null, true, '😎', $lastMessage);
    }
}
