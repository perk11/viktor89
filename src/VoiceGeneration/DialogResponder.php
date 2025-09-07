<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class DialogResponder implements MessageChainProcessor
{
    public function __construct(
        private readonly AssistantInterface $assistant,
        private readonly TtsApiClient $ttsApiClient,
        private readonly VoiceResponder $voiceResponder,
        private readonly array $voicesConfig,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $message = $messageChain->last();
        $commandText = $message->messageText;
        $commandTextParts = explode(",", $commandText);
        if (count($commandTextParts) < 3) {
            return $this->getHelpTextResult('', $message);
        }

        $voice1 = trim($commandTextParts[0]);
        $voice2 = trim($commandTextParts[1]);
        $prompt = ltrim($commandTextParts[2]);
        for ($i = 3, $iMax = count($commandTextParts); $i < $iMax; $i++) {
            $prompt .= "," . $commandTextParts[$i];
        }
        if (!array_key_exists($voice1, $this->voicesConfig)) {
            return $this->getHelpTextResult("Неизвествесный собеседник \"$voice1\"!\n", $message);
        }
        if (!array_key_exists($voice2, $this->voicesConfig)) {
            return $this->getHelpTextResult("Неподдерживаемый собеседник \"$voice2\"!\n", $message);
        }
        if (!array_key_exists( 'Bio', $this->voicesConfig[$voice1])) {
            echo "Error: No bio defined for voice $voice1\n";
            return new ProcessingResult(null, true, '🤔', $message);
        }
        if (!array_key_exists( 'Bio', $this->voicesConfig[$voice2])) {
            echo "Error: No bio defined for voice $voice2\n";
            return new ProcessingResult(null, true, '🤔', $message);
        }

        $bio1 = $this->voicesConfig[$voice1]['Bio'];
        $bio2 = $this->voicesConfig[$voice2]['Bio'];
        $context = new AssistantContext();
        $context->systemPrompt = "Write a dialog between two characters per user's prompt, at least 15 lines. All lines should be related to Dialog Prompt. For the dialog use the same language as the language after \"Dialog Prompt:\" text in user's prompt, regardless of characters native language. Prefix each line with [1]: for Character 1 and [2] for Character 2. Only speech.";
        $contextMessage = new AssistantContextMessage();
        $contextMessage->isUser = true;
        $contextMessage->text =
"Character 1 description: $bio1.
Character 2 description: $bio2.
Dialog Prompt: $prompt";
        $context->messages[] = $contextMessage;

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

        $dialogText = $this->assistant->getCompletionBasedOnContext($context);
        echo "Dialog: $dialogText\n";

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

        $voice1FileContents = file_get_contents(TtsProcessor::VOICE_STORAGE_DIR . '/' . $voice1 . '.ogg');
        if ($voice1FileContents === false) {
            echo "Error: Failed to read file for $voice1\n";
            return new ProcessingResult(null, true, '🤔', $message);
        }
        $voice2FileContents = file_get_contents(TtsProcessor::VOICE_STORAGE_DIR . '/' . $voice2 . '.ogg');
        if ($voice2FileContents === false) {
            echo "Error: Failed to read file for $voice2\n";
            return new ProcessingResult(null, true, '🤔', $message);
        }
        $voice = $this->ttsApiClient->text2Voice($dialogText, [$voice1FileContents, $voice2FileContents],  null, '', 'ogg', null, 'VibeVoice_viktor89');

        $this->voiceResponder->sendVoice($message, $voice->voiceFileContents);

        return new ProcessingResult(null, true, '😎', $message);
    }

    private function getHelpTextResult(string $prefix, InternalMessage $message): ProcessingResult
    {
        $responseText = "{$prefix}Используйте следующий синтаксис: Собеседник 1, Собеседник 2, Тема диалога. Например:
Жириновский, Якубович, Почему закрыли поле чудес.";

        $responseText .= "\nПоддерживаемые собеседники:\n" . implode("\n", array_keys($this->voicesConfig));
        return new ProcessingResult(
            InternalMessage::asResponseTo(
                $message,
                $responseText,
            ),
            true
        );
    }
}
