<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use JsonException;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class Gemma2Assistant extends AbstractOpenAIAPICompletionAssistant
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
        UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        UserPreferenceSetByCommandProcessor $responseStartProcessor,
        OpenAiCompletionStringParser $openAiCompletionStringParser,
    ) {
        $openAi = new OpenAi('');
        $openAi->setBaseURL($_ENV['OPENAI_ASSISTANT_SERVER']);
        parent::__construct($openAi, $systemPromptProcessor, $responseStartProcessor, $openAiCompletionStringParser);
        $this->tokenReplacements = array_fill(0, count($this->tokens) - 1, '');
    }

    protected function convertMessageChainToPrompt(
        array $messageChain,
        string $systemPrompt,
        ?string $responseStart
    ): string
    {
        $prompt = '';
        $firstUserMessage = true;
        $human = count($messageChain) % 2 === 1;
        foreach ($messageChain as $message) {
            $previousMessageUserName = $human ? 'user' : 'model';
            if ($firstUserMessage && $previousMessageUserName === 'user') {
                $prompt = "<start_of_turn>user\n$systemPrompt\n";
                $firstUserMessage = false;
            } else {
                $prompt .= "<start_of_turn>$previousMessageUserName\n";
            }
            $escapedMessageText = str_replace($this->tokens, $this->tokenReplacements, $message->messageText);
            $prompt .= "$escapedMessageText<end_of_turn>";
            $human = !$human;
        }
        $prompt .= "<start_of_turn>model\n";
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
                "<end_of_turn>",
                "<eos>",
            ],
        ];
    }
}
