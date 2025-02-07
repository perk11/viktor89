<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\UserPreferenceReaderInterface;

/**
 * <|im_start|>system
 * {system_prompt}<|im_end|>
 * <|im_start|>user
 * {prompt}<|im_end|>
 * <|im_start|>assistant
 */
class Qwen25Assistant extends AbstractOpenAIAPICompletingAssistant
{

    private array $tokens = [
        '<|im_start|>',
        '<|im_end|>',
    ];

    private readonly array $tokenReplacements;

    public function __construct(
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        string $url,
        OpenAiCompletionStringParser $openAiCompletionStringParser,
    ) {

        parent::__construct($systemPromptProcessor, $responseStartProcessor, $url, $openAiCompletionStringParser);
        $this->tokenReplacements = array_fill(0, count($this->tokens) - 1, '');
    }
    protected function convertContextToPrompt(AssistantContext $assistantContext): string
    {
        $prompt = '';
        $firstUserMessage = true;
        foreach ($assistantContext->messages as $contextMessage) {
            $userName = $contextMessage->isUser ? 'user' : 'assistant';
            if ($assistantContext->systemPrompt !== null && $assistantContext->systemPrompt !== '' && $firstUserMessage && $contextMessage->isUser) {
                $prompt = "<|im_start|>system\n$assistantContext->systemPrompt<|im_end|>";
                $firstUserMessage = false;
            }
            $escapedMessageText = str_replace($this->tokens, $this->tokenReplacements, $contextMessage->text);
            $prompt .= "<|im_start|>$userName\n$escapedMessageText<|im_end|>";
        }
        $prompt .= "<|im_start|>assistant\n";
        if ($assistantContext->responseStart !== null) {
            $prompt .= $assistantContext->responseStart;
        }

        return $prompt;
    }

    protected function getCompletionOptions(string $prompt): array
    {
        return [
            'temperature'        => 0.7,
            'top_p'              => 0.8,
            'repetition_penalty' => 1.05,
            'max_tokens'         => 2048,
            'prompt' => $prompt,
            'stream' => true,
            "stop"   => [
                "<|im_start|>",
                "<|im_end|>",
            ],
        ];
    }
}
