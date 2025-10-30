<?php

namespace Perk11\Viktor89\Assistant;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

abstract class AbstractOpenAIAPiAssistant implements AssistantInterface
{
    protected readonly OpenAI $openAi;
    public function __construct(
        private readonly UserPreferenceReaderInterface $systemPromptProcessor,
        private readonly UserPreferenceReaderInterface $responseStartProcessor,
        private readonly TelegramFileDownloader$telegramFileDownloader,
        private readonly int $telegramBotUserId,
        string $url,
        string $apiKey = '',
    )
    {
        $this->openAi = new OpenAi($apiKey);
        $this->openAi->setBaseURL(rtrim($url, '/'));
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $userId = $messageChain->last()->userId;
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($userId);
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond to the user in the language they use or request.\n";

        $userName = trim($messageChain->last()->userName);
        if ($userName !== "") {
            $systemPrompt = "User's name is \"$userName\".\n" . $systemPrompt;
        }
        $assistantContext = $this->convertMessageChainToAssistantContext($messageChain, $systemPrompt, $responseStart);

        $progressUpdateCallback(static::class, 'Generating assistant response');
        $lastMessage = $messageChain->last();
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

        return new ProcessingResult($message, true);
    }

    protected function convertMessageChainToAssistantContext(
        MessageChain $messageChain,
        ?string $systemPrompt,
        ?string $responseStart
    ): AssistantContext {
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = $systemPrompt;
        $assistantContext->responseStart = $responseStart;
        foreach ($messageChain->getMessages() as $message) {
            $assistantContextMessage = new AssistantContextMessage();
            $assistantContextMessage->text = $message->messageText;
            $assistantContextMessage->isUser = $message->userId !== $this->telegramBotUserId;
            if ($message->photoFileId !== null) {
                $assistantContextMessage->photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($message);
            }
            $assistantContext->messages[] = $assistantContextMessage;
        }

        return $assistantContext;
    }

}
