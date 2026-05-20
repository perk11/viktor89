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

class SingProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly SingApiClient $songApiClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly UserPreferenceReaderInterface $durationPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $singModelPreference,
    ) {
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
                $this->seedPreference->getCurrentPreferenceValue($message->userId)
            );
            $this->voiceResponder->sendVoice(
                $message,
                $response->voiceFileContents,
            );
        } catch (Exception $e) {
            echo "Failed to generate a song:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

            return new ProcessingResult(null, true, '🤔', $message);
        }

        return new ProcessingResult(null, true, '😎', $message);
    }
}
