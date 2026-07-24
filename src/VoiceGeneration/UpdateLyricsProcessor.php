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
 * Implements the `/updatelyrics` command: reply it on an audio/voice/video/video-note to
 * re-render that song with new lyrics via ACE-Step's `task_type=cover`. The whole text
 * after the command is the new lyrics. The caption/genre, cover strength, cover noise and
 * model are no longer taken from the user (hardcoded in CoverApiClient); only duration and
 * seed come from user preferences (`/duration`, `/seed`).
 */
class UpdateLyricsProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly CoverApiClient $coverApiClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly UserPreferenceReaderInterface $durationPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();

        // /updatelyrics must be a reply to the song you want to re-render.
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    "Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео. "
                    . "После команды напишите новый текст песни, например /updatelyrics [Verse 1] ..."
                ),
                true,
            );
        }
        $sourceAudio = $messageChain->previous()->getMessageAudio();
        if ($sourceAudio === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    "Не найдено аудио в сообщении, на которое вы отвечаете. Для использования этой команды, "
                    . "ваше сообщение должно быть ответом на аудио или видео. После команды напишите новый текст песни."
                ),
                true,
            );
        }

        $lyrics = trim($lastMessage->messageText);
        if ($lyrics === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    "После команды напишите новый текст песни с разметкой [Verse], [Chorus] и т. п."
                ),
                true,
            );
        }

        $durationPref = $this->durationPreference->getCurrentPreferenceValue($lastMessage->userId);
        $duration = $durationPref === null ? null : (int) $durationPref * 1000;
        $seedPref = $this->seedPreference->getCurrentPreferenceValue($lastMessage->userId);
        $seed = $seedPref === null ? null : (int) $seedPref;

        $progressUpdateCallback(
            static::class,
            "Updating lyrics",
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
            $response = $this->coverApiClient->cover(
                $audioFile,
                $lyrics,
                $duration,
                $seed,
            );
            $this->voiceResponder->sendVoice($lastMessage, $response->voiceFileContents);
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to update lyrics:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());

            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }

        return new ProcessingResult(null, true, '😎', $lastMessage);
    }
}
