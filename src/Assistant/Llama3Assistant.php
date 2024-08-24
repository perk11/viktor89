<?php

namespace Perk11\Viktor89\Assistant;

use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;

class Llama3Assistant extends AbstractOpenAIAPICompletionAssistant
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
        UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        UserPreferenceSetByCommandProcessor $responseStartProcessor,
        OpenAiCompletionStringParser $openAiCompletionStringParser,
        string $url,
    ) {
        $openAi = new OpenAi('');
        $openAi->setBaseURL(rtrim($url, '/'));
        parent::__construct($openAi, $systemPromptProcessor, $responseStartProcessor, $openAiCompletionStringParser);
        $this->tokenReplacements = array_fill(0, count($this->tokens) - 1, '');
    }

    protected function convertMessageChainToPrompt(
        array $messageChain,
        ?string $systemPrompt,
        ?string $responseStart
    ): string
    {
        $prompt = '';
        $firstUserMessage = true;
        $human = count($messageChain) % 2 === 1;
        foreach ($messageChain as $message) {
            $previousMessageUserName = $human ? 'user' : 'assistant';
            if ($systemPrompt !== null && $systemPrompt !== '' && $firstUserMessage && $previousMessageUserName === 'user') {
                $prompt = "<|begin_of_text|><|start_header_id|>system<|end_header_id|>\n\n$systemPrompt<|eot_id|>";
                $firstUserMessage = false;
            }
            $escapedMessageText = str_replace($this->tokens, $this->tokenReplacements, $message->messageText);
            $prompt .= "<|start_header_id|>$previousMessageUserName<|end_header_id|>\n\n$escapedMessageText<|eot_id|>";
            $human = !$human;
        }
        $prompt .= "<|start_header_id|>assistant<|end_header_id|>\n\n";
        if ($responseStart !== null) {
            $prompt .= $responseStart;
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
