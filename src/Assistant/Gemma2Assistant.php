<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class Gemma2Assistant extends AbstractOpenAIAPICompletingAssistant
{

    private array $tokens = [
        '<end_of_turn>',
        '<start_of_turn>',
        '<bos>',
        '<eos>',
        '<pad>',
        '<unk>',
    ];

    private readonly array $tokenReplacements;

    public function __construct(
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        private readonly AltTextProvider $altTextProvider,
        int $telegramBotId,
        string $url,
        OpenAiCompletionStringParser $openAiCompletionStringParser,
    ) {
        parent::__construct($systemPromptProcessor, $responseStartProcessor, $telegramFileDownloader,$this->altTextProvider, $telegramBotId, $url, $openAiCompletionStringParser, false);
        $this->tokenReplacements = array_fill(0, count($this->tokens) - 1, '');
    }

    protected function convertContextToPrompt(AssistantContext $assistantContext): string
    {
        $prompt = '';
        $firstUserMessage = true;
        foreach ($assistantContext->messages as $contextMessage) {
            $userName = $contextMessage->isUser ? 'user' : 'model';
            if ($firstUserMessage && $contextMessage->isUser) {
                $prompt = "<start_of_turn>user\n";
                if ($assistantContext->systemPrompt !== null && $assistantContext->systemPrompt !== '') {
                    $prompt .= "$assistantContext->systemPrompt\n";
                }
                $firstUserMessage = false;
            } else {
                $prompt .= "<start_of_turn>$userName\n";
            }
            $escapedMessageText = str_replace($this->tokens, $this->tokenReplacements, $contextMessage->text);
            $prompt .= "$escapedMessageText<end_of_turn>";
        }
        $prompt .= "<start_of_turn>model\n";
        if ($assistantContext->responseStart !== null) {
            $prompt .= $assistantContext->responseStart;
        }

        return $prompt;
    }

    protected function getCompletionOptions(string $prompt): array
    {
        return [
            'prompt' => $prompt,
            'stream' => true,
            "stop"   => [
                "<end_of_turn>",
                "<eos>",
            ],
        ];
    }
}
