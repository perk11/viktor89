<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Exception;
use LanguageDetection\Language;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class TtsProcessor implements MessageChainProcessor
{
    public const VOICE_STORAGE_DIR = __DIR__ . '/../../data/voice';
    public function __construct(
        private readonly TtsApiClient $voiceClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly AltTextProvider $altTextProvider,
        private readonly UserPreferenceReaderInterface $voiceModelPreference,
        private readonly array $modelConfig,
        private readonly Language $languageDetection, //TODO construct only with the languages supported by the model
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $prompt = $message->messageText;
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($messageChain->previous()->messageText . "\n\n" . $prompt);
        }
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($this->altTextProvider->provide($messageChain->previous(), $progressUpdateCallback));
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что говорить... Напишите, что сказать после команды, например /say "Привет, мир!" или используйте команду в ответ на изображение или видео/аудио содержащее голос';

            return new ProcessingResult($response, true);
        }
        $modelName = $this->voiceModelPreference->getCurrentPreferenceValue($message->userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $model = current($this->modelConfig);
            $modelName = key($this->modelConfig);
        } else {
            $model = $this->modelConfig[$modelName];
        }
        if (isset($model['voice_source'])) {
            $voiceSource = file_get_contents(self::VOICE_STORAGE_DIR . '/' . $model['voice_source']);
        } else {
            $voiceSource = null;
        }
        $language = array_key_first($this->languageDetection->detect($prompt)->close()) ?? 'ru';
        $progressUpdateCallback(static::class, "Generating voice for prompt: $prompt");
        try {
            $response = $this->voiceClient->text2Voice(
                $prompt,
                [$voiceSource],
                $model['speakerId'] ?? null,
                $language,
                'ogg',
                $model['speed'] ?? null,
                $modelName,
            );
            $this->voiceResponder->sendVoice(
                $message,
                $response->voiceFileContents,
            );
        } catch (Exception $e) {
            echo "Failed to generate voice:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

            return new ProcessingResult(null, true, '🤔', $message);
        }

        return new ProcessingResult(null, true);
    }
}
