<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\MessageChain;

class TtsProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly TtsApiClient $voiceClient,
        private readonly VoiceResponder $voiceResponder,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $message = $messageChain->last();
        $prompt = $message->messageText;
        if ($messageChain->count() > 1) {
            $prompt = trim($messageChain->getMessages()[$messageChain->count() - 2]->messageText . "\n\n" . $prompt);
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что говорить...';

            return new ProcessingResult($response, true);
        }
        echo "Generating voice for prompt: $prompt\n";
        try {
            $response = $this->voiceClient->text2Voice($prompt, null, 'Claribel Dervla', 'ru', 'ogg', '');
            $this->voiceResponder->sendVoice(
                $message,
                $response->voiceFileContents,
            );
        } catch (\Exception $e) {
            echo "Failed to generate voice:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

            return new ProcessingResult(null, true, '🤔', $message);
        }

        return new ProcessingResult(null, true);
    }
}
