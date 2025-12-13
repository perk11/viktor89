<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class Llama3Assistant extends AbstractOpenAIAPICompletingAssistant
{

    private array $tokens = [
        '<|begin_of_text|>',
        '<|start_header_id|>',
        '<|end_header_id|>',
        '<|eot_id|>',
        '<|end_of_text|>',
    ];

    private readonly array $tokenReplacements;

    public function __construct(
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        AltTextProvider $altTextProvider,
        int $telegramBotId,
        string $url,
        OpenAiCompletionStringParser $openAiCompletionStringParser,
    ) {

        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegramBotId,
            $url,
            $openAiCompletionStringParser,
            false,
        );
        $this->tokenReplacements = array_fill(0, count($this->tokens) - 1, '');
    }
    protected function convertContextToPrompt(AssistantContext $assistantContext): string
    {
        $prompt = '';
        $firstUserMessage = true;
        foreach ($assistantContext->messages as $contextMessage) {
            $userName = $contextMessage->isUser ? 'user' : 'assistant';
            if ($assistantContext->systemPrompt !== null && $assistantContext->systemPrompt !== '' && $firstUserMessage && $contextMessage->isUser) {
                $prompt = "<|begin_of_text|><|start_header_id|>system<|end_header_id|>\n\n$assistantContext->systemPrompt<|eot_id|>";
                $firstUserMessage = false;
            }
            $escapedMessageText = str_replace($this->tokens, $this->tokenReplacements, $contextMessage->text);
            $prompt .= "<|start_header_id|>$userName<|end_header_id|>\n\n$escapedMessageText<|eot_id|>";
        }
        $prompt .= "<|start_header_id|>assistant<|end_header_id|>\n\n";
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
                "<|eot_id|>",
                "<|end_of_text|>",
            ],
        ];
    }
}
