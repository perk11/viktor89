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
 * Implements the `/cover` command: reply it on an audio/voice/video/video-note to
 * re-render that song in a new style via ACE-Step's `task_type=cover`. The first line
 * after the command is the new style (genre/instruments/vocals); the following lines
 * are optional new lyrics. Strength (`audio_cover_strength`), cover noise
 * (`cover_noise_strength`, how closely to follow the original), model, duration and
 * seed come from user preferences (`/coverstrength`, `/covernoise`, `/covermodel`,
 * `/duration`, `/seed`).
 */
class CoverProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly CoverApiClient $coverApiClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly UserPreferenceReaderInterface $coverModelPreference,
        private readonly UserPreferenceReaderInterface $coverStrengthPreference,
        private readonly UserPreferenceReaderInterface $coverNoisePreference,
        private readonly UserPreferenceReaderInterface $durationPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();

        // /cover must be a reply to the song you want to cover.
        if ($messageChain->previous() === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    "Для использования этой команды, ваше сообщение должно быть ответом на аудио или видео. "
                    . "После команды напишите новый стиль кавера, например /cover female vocal, synthwave"
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
                    . "ваше сообщение должно быть ответом на аудио или видео. После команды напишите новый стиль кавера, "
                    . "например /cover female vocal, synthwave"
                ),
                true,
            );
        }

        $prompt = trim($lastMessage->messageText);
        if ($prompt === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    "После команды напишите новый стиль кавера (жанры, инструменты, вокал), например "
                    . "/cover female vocal, synthwave. На следующих строчках можно написать новый текст песни (необязательно)."
                ),
                true,
            );
        }

        $lines = explode("\n", $prompt);
        $style = trim($lines[0]);
        $lyrics = trim(implode("\n", array_slice($lines, 1)));

        $modelName = $this->coverModelPreference->getCurrentPreferenceValue($lastMessage->userId);
        $strengthPref = $this->coverStrengthPreference->getCurrentPreferenceValue($lastMessage->userId);
        $strength = $strengthPref === null ? 1.0 : (float) $strengthPref;
        $coverNoisePref = $this->coverNoisePreference->getCurrentPreferenceValue($lastMessage->userId);
        $coverNoise = $coverNoisePref === null ? null : (float) $coverNoisePref;
        $durationPref = $this->durationPreference->getCurrentPreferenceValue($lastMessage->userId);
        $duration = $durationPref === null ? null : (int) $durationPref * 1000;
        $seedPref = $this->seedPreference->getCurrentPreferenceValue($lastMessage->userId);
        $seed = $seedPref === null ? null : (int) $seedPref;

        $progressUpdateCallback(
            static::class,
            "Generating a cover with style: $style",
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
                $style,
                $modelName,
                $strength,
                $lyrics !== '' ? $lyrics : null,
                $duration,
                $seed,
                $coverNoise,
            );
            $this->voiceResponder->sendVoice($lastMessage, $response->voiceFileContents);
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate a cover:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());

            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }

        return new ProcessingResult(null, true, '😎', $lastMessage);
    }
}
