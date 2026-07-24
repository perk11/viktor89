<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Exception;
use LanguageDetection\Language;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class SingProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly SingApiClient $songApiClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly UserPreferenceReaderInterface $durationPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $singModelPreference,
        private readonly array $singModelsConfig,
        private readonly ?AudioSuperResolutionApiClient $audioSuperResolutionApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A sing model entry may set `audioSR: true` to run the generated song through the
     * AudioSR server (audioSuperResolutionUrl) before posting it. Requires the client to
     * be wired (i.e. an `audioSuperResolutionUrl` in config); false otherwise.
     */
    private function audioSuperResolutionEnabledForModel(string $modelName): bool
    {
        return $this->audioSuperResolutionApiClient !== null
            && ($this->singModelsConfig[$modelName]['audioSR'] ?? false) === true;
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $prompt = $message->messageText;
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($messageChain->previous()->messageText . "\n\n" . $prompt);
        }
        if ($prompt === '' || substr_count($prompt, "\n") < 2) {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Напишите после команды сначала теги (жанры) через запятую, на следующей строчке пишите текст песни, используя разделители [Verse], [Chorus], и т.п.';

            return new ProcessingResult($response, true);
        }
        $lines = explode("\n", $prompt);
        $tags = $lines[0];
        $lyrics = implode("\n", array_slice($lines, 1));
        $modelName = $this->singModelPreference->getCurrentPreferenceValue($message->userId);
        $seed = $this->seedPreference->getCurrentPreferenceValue($message->userId);
        $durationSeconds = $this->durationPreference->getCurrentPreferenceValue($message->userId);
        if ($durationSeconds === null) {
            $duration = null;
        } else {
            $duration = $durationSeconds * 1000;
        }
        $progressUpdateCallback(
            static::class,
            "Generating a song with tags: $tags",
           new ChatAction($message->chatId, ChatActionEnum::record_voice),
        );
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
        try {
            $response = $this->songApiClient->txtTags2Music(
                $lyrics,
                $tags,
                $modelName,
                $duration,
                $seed
            );
            $audio = $response->voiceFileContents;
            if ($this->audioSuperResolutionEnabledForModel($modelName)) {
                $progressUpdateCallback(
                    static::class,
                    "Enhancing the song with AudioSR",
                    new ChatAction($message->chatId, ChatActionEnum::record_voice),
                );
                try {
                    $audio = $this->audioSuperResolutionApiClient->enhance($audio, $seed === null ? null : (int) $seed)
                        ->voiceFileContents;
                } catch (Exception $audioSrException) {
                    // Enhancement failed; fall back to the original generation rather than
                    // dropping the song entirely.
                    $this->logger->log(
                        LogLevel::WARNING,
                        "AudioSR enhancement failed, sending the original song:\n"
                        . $audioSrException->getMessage(),
                    );
                }
            }
            $this->voiceResponder->sendVoice(
                $message,
                $audio,
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate a song:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());

            return new ProcessingResult(null, true, '🤔', $message);
        }

        return new ProcessingResult(null, true, '😎', $message);
    }
}
