<?php

namespace Perk11\Viktor89\Assistant;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

abstract class AbstractOpenAIAPiAssistant  implements TelegramChainBasedResponderInterface,
                                                      ContextCompletingAssistantInterface

{
    protected readonly OpenAI $openAi;
    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor,
        string $url,
    )
    {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL(rtrim($url, '/'));
    }

    public function getResponseByMessageChain(array $messageChain): ?InternalMessage
    {
        $userId = $messageChain[count($messageChain) - 1]->userId;
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($userId);
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond in Russian.\n";

        $assistantContext = $this->convertMessageChainToAssistantContext($messageChain, $systemPrompt, $responseStart);

        /** @var InternalMessage $lastMessage */
        $lastMessage = $messageChain[count($messageChain) - 1];
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->parseMode = 'MarkdownV2';
        $message->messageText = $responseStart . trim($this->getCompletionBasedOnContext($assistantContext));
        for ($i = 0; $i < 5; $i++) {
            if (trim($message->messageText) === '') {
                echo "Bad response detected, trying again\n";
                $message->messageText = $responseStart . $this->getCompletionBasedOnContext($assistantContext);
            } else {
                break;
            }
        }

        return $message;
    }

    protected function convertMessageChainToAssistantContext(
        array $messageChain,
        ?string $systemPrompt,
        ?string $responseStart
    ): AssistantContext {
        $isUser = count($messageChain) % 2 === 1;
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = $systemPrompt;
        $assistantContext->responseStart = $responseStart;
        foreach ($messageChain as $message) {
            $assistantContextMessage = new AssistantContextMessage();
            $assistantContextMessage->isUser = $isUser;
            $assistantContextMessage->text = $message->messageText;
            $assistantContext->messages[] = $assistantContextMessage;
            $isUser = !$isUser;
        }

        return $assistantContext;
    }

}
