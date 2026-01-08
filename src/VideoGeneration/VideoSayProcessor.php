<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\VoiceGeneration\TtsApiClient;
use Perk11\Viktor89\VoiceGeneration\TtsProcessor;

class VideoSayProcessor implements MessageChainProcessor
{
    private const MAX_AUDIO_DURATION_SECONDS = 20.0;

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly AudioImgTxt2VidClient $audioImgTxt2VidClient,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly VideoResponder $videoResponder,
        private readonly AltTextProvider $altTextProvider,
        private readonly ContextCompletingAssistantInterface $promptAssistant,
        private readonly TtsApiClient $voiceClient,
        private readonly UserPreferenceReaderInterface $voiceModelPreference,
        private readonly array $modelConfig,
    ) {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        $message = $messageChain->last();
        $promptText = $message->messageText;
        if ($promptText === '' && $messageChain->count() > 1) {
            $promptText = trim($messageChain->previous()->messageText . "\n\n" . $promptText);
        }
        if ($promptText === '' && $messageChain->count() > 1) {
            $promptText = trim($this->altTextProvider->provide($messageChain->previous(), $progressUpdateCallback));
        }
        if ($promptText === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    'После команды укажите текст вашего сообщения, например, /vsay Всем привет!',
                ),
                true
            );
        }

        $prompt = new ImageGenerationPrompt($promptText);
        $prompt = $this->imgTagExtractor->extractImageTags($prompt, 'not implemented');
        if (mb_strlen($prompt->text) > 512) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    'Не больше 512 символов!',
                ),
                true
            );
        }
        if ($messageChain->previous()?->photoFileId !== null) {
            if (count($prompt->sourceImagesContents) > 0) {
                return new ProcessingResult(
                    InternalMessage::asResponseTo(
                        $message,
                        'Используйте тег <img> либо ответ на фото, не и то и другое одновременно!',
                    ),
                    true
                );
            }
            $progressUpdateCallback(static::class, "Downloading source photo");
            try {
                $prompt->sourceImagesContents[] = $this->telegramFileDownloader->downloadPhotoFromInternalMessage(
                    $messageChain->previous()
                );
            } catch (Exception $e) {
                echo "Failed to download source image from Telegram: " . $e->getMessage() . "\n";

                return new ProcessingResult(null, true, '🤔', $messageChain->last());
            }
        }

        if (count($prompt->sourceImagesContents) === 0) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    'Эта команда пока работает только в ответ на фото',
                ),
                true
            );
        }

        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);

        $progressUpdateCallback(static::class, "Generating voice for video");
        $modelName = $this->voiceModelPreference->getCurrentPreferenceValue($message->userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $model = current($this->modelConfig);
            $modelName = key($this->modelConfig);
        } else {
            $model = $this->modelConfig[$modelName];
        }

        if (isset($model['voice_source'])) {
            $voiceSource = file_get_contents(TtsProcessor::VOICE_STORAGE_DIR . '/' . $model['voice_source']);
        } else {
            $voiceSource = null;
        }

        try {
            $voiceResponse = $this->voiceClient->text2Voice(
                $prompt->text,
                [$voiceSource],
                $model['speakerId'] ?? null,
                'ru',
                'ogg',
                $model['speed'] ?? null,
                $modelName,
            );
        } catch (Exception $e) {
            echo "Failed to generate voice:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

            return new ProcessingResult(null, true, '🤔', $message);
        }

        $progressUpdateCallback(static::class, "Adjusting audio to fit max duration");
        [$voiceFileContents, $actualAudioDurationSeconds] = $this->speedUpOggAudioToFitMaxDurationSecondsAndGetDurationSeconds(
            $voiceResponse->voiceFileContents,
            self::MAX_AUDIO_DURATION_SECONDS
        );

        if ($actualAudioDurationSeconds <= 0.0) {
            $actualAudioDurationSeconds = self::MAX_AUDIO_DURATION_SECONDS;
        }
        $actualAudioDurationSecondsText = $this->formatSecondsForPrompt($actualAudioDurationSeconds);


        Request::sendChatAction([
                                    'chat_id' => $messageChain->last()->chatId,
                                    'action'  => ChatAction::RECORD_VIDEO,
                                ]);

        $progressUpdateCallback(static::class, "Generating a prompt for video");
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '✍',
                ],
            ],
        ]);

        $context = new AssistantContext();
        $context->systemPrompt = 'You a world class expert on video prompt generation. Only output text that will be used for video generation and nothing else. Do not describe audio, only video. Avoid too many scene changes. Do not output specific timestamps.';

        $contextMessage = new AssistantContextMessage();
        $contextMessage->isUser = true;
        $contextMessage->text =
            'Generate a prompt for a creative ' . $actualAudioDurationSecondsText . '-second video where a following speech is said: ' .
        $prompt->text .
            "\nUse the image as first frame of the video. If an image contains a person, make sure this person is a part of your scenario.";
        $contextMessage->photo = $prompt->sourceImagesContents[0];

        $context->messages[] = $contextMessage;

        $videoPrompt = $this->promptAssistant->getCompletionBasedOnContext($context);
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '⚡',
                ],
            ],
        ]);

        $progressUpdateCallback(static::class, "Generating video");

        $videoResponse = $this->audioImgTxt2VidClient->generateByPromptImageAndAudio(
            $voiceFileContents,
            $prompt->sourceImagesContents[0],
            $videoPrompt,
            $message->userId
        );

        $this->videoResponder->sendVideo(
            $message,
            $videoResponse->getFirstVideoAsMp4(),
            $videoResponse->getCaption()
        );

        return new ProcessingResult(null, true, '😎', $message);
    }

    private function speedUpOggAudioToFitMaxDurationSecondsAndGetDurationSeconds(string $oggFileContents, float $maxDurationSeconds): array
    {
        if ($maxDurationSeconds <= 0.0) {
            return [$oggFileContents, 0.0];
        }

        $inputOggTempFilePath = null;
        $outputOggTempFilePath = null;

        try {
            $inputOggTempFilePath = $this->createTempFilePathWithSuffix('vitkor89-vsay_in_', '.ogg');
            $outputOggTempFilePath = $this->createTempFilePathWithSuffix('viktor89-vsay_out_', '.ogg');

            file_put_contents($inputOggTempFilePath, $oggFileContents);

            $audioDurationSeconds = $this->getAudioDurationSecondsViaFfprobe($inputOggTempFilePath);
            if ($audioDurationSeconds === null || $audioDurationSeconds <= 0.0) {
                return [$oggFileContents, 0.0];
            }

            if ($audioDurationSeconds <= $maxDurationSeconds) {
                return [$oggFileContents, $audioDurationSeconds];
            }

            $speedUpFactor = $audioDurationSeconds / $maxDurationSeconds;
            $atempoFilterGraph = implode(',', $this->buildAtempoFiltersForSpeedUpFactor($speedUpFactor));

            $ffmpegCommand = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($inputOggTempFilePath) . ' -filter:a ' . escapeshellarg($atempoFilterGraph) . ' -c:a libopus -application audio -vbr on -b:a 96k ' . escapeshellarg($outputOggTempFilePath) . ' 2>&1';
            exec($ffmpegCommand, $ffmpegOutputLines, $ffmpegExitCode);

            if ($ffmpegExitCode !== 0 || !is_file($outputOggTempFilePath)) {
                return [$oggFileContents, $audioDurationSeconds];
            }

            $spedUpOggFileContents = file_get_contents($outputOggTempFilePath);
            if ($spedUpOggFileContents === false || $spedUpOggFileContents === '') {
                return [$oggFileContents, $audioDurationSeconds];
            }

            return [$spedUpOggFileContents, min($audioDurationSeconds, $maxDurationSeconds)];
        } catch (Exception $e) {
            return [$oggFileContents, 0.0];
        } finally {
            if (is_string($inputOggTempFilePath) && $inputOggTempFilePath !== '' && is_file($inputOggTempFilePath)) {
                @unlink($inputOggTempFilePath);
            }
            if (is_string($outputOggTempFilePath) && $outputOggTempFilePath !== '' && is_file($outputOggTempFilePath)) {
                @unlink($outputOggTempFilePath);
            }
        }
    }

    private function formatSecondsForPrompt(float $seconds): string
    {
        $rounded = round($seconds, 1);
        $formatted = number_format($rounded, 1, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        return $formatted;
    }

    private function getAudioDurationSecondsViaFfprobe(string $audioFilePath): ?float
    {
        $ffprobeCommand = 'ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($audioFilePath) . ' 2>&1';
        $ffprobeOutput = trim((string) shell_exec($ffprobeCommand));
        if ($ffprobeOutput === '') {
            return null;
        }

        $durationSeconds = (float) str_replace(',', '.', $ffprobeOutput);
        if ($durationSeconds <= 0.0) {
            return null;
        }

        return $durationSeconds;
    }

    private function buildAtempoFiltersForSpeedUpFactor(float $speedUpFactor): array
    {
        if ($speedUpFactor <= 1.0) {
            return [];
        }

        $filters = [];
        $remainingFactor = $speedUpFactor;

        while ($remainingFactor > 2.0) {
            $filters[] = 'atempo=2.0';
            $remainingFactor /= 2.0;
        }

        $filters[] = 'atempo=' . number_format($remainingFactor, 6, '.', '');

        return $filters;
    }

    private function createTempFilePathWithSuffix(string $prefix, string $suffix): string
    {
        $tempFileBasePath = tempnam(sys_get_temp_dir(), $prefix);
        if ($tempFileBasePath === false) {
            throw new Exception('Failed to allocate temp file path');
        }

        @unlink($tempFileBasePath);

        $tempFilePathWithSuffix = $tempFileBasePath . $suffix;
        return $tempFilePathWithSuffix;
    }
}
