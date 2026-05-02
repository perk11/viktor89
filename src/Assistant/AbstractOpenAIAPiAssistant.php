<?php

namespace Perk11\Viktor89\Assistant;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\TelegramHtml;
use Perk11\Viktor89\Util\TelegramMarkdownV2;

abstract class AbstractOpenAIAPiAssistant implements AssistantInterface
{
    private const float DRAFT_FREQUENCY_SECONDS = 0.7;
    private const float EDIT_FREQUENCY_SECONDS = 0.7;

    protected readonly OpenAI $openAi;
    public function __construct(
        private readonly UserPreferenceReaderInterface $systemPromptProcessor,
        private readonly UserPreferenceReaderInterface $responseStartProcessor,
        private readonly TelegramFileDownloader$telegramFileDownloader,
        private readonly AltTextProvider $altTextProvider,
        private readonly int $telegramBotUserId,
        string $url,
        string $apiKey = '',
        public bool $supportsImages = false,
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
        $assistantContext = $this->convertMessageChainToAssistantContext($messageChain, $systemPrompt, $responseStart, $progressUpdateCallback);

        $progressUpdateCallback(static::class, 'Generating assistant response');
        Request::sendChatAction([
                                    'chat_id' => $messageChain->last()->chatId,
                                    'action'  => ChatAction::TYPING,
                                ]);
        $lastMessage = $messageChain->last();
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->parseMode = 'MarkdownV2';
        try {
            $partialContent = '';
            if ($message->chatId > 0) {
                $draftAborted = false;
                $lastDraftTime = 0;
                $streamFunction = static function ($chunk) use (&$partialContent, &$message, &$draftAborted, &$lastDraftTime) {
                    echo $chunk;
                    $partialContent .= $chunk;
                    if ($draftAborted) {
                        return;
                    }
                    $currentDraftTime = microtime(true);
                    if ($currentDraftTime - $lastDraftTime < self::DRAFT_FREQUENCY_SECONDS) {
                        return;
                    }
                    $lastDraftTime = $currentDraftTime;
                    $message->messageText = TelegramMarkdownV2::makeValid($partialContent);
                    $sendAsDraftResult = $message->sendAsDraft();
                    $sendAsDraftResultObject = json_decode($sendAsDraftResult, false);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo "Failed to parse result of sending message as draft: " . json_last_error_msg() . "\n";
                        $draftAborted = true;
                    }
                    if ($sendAsDraftResultObject->ok === false) {
                        var_dump($sendAsDraftResultObject);
                        if ($sendAsDraftResultObject->error_code === 429 && isset($sendAsDraftResultObject->parameters->retry_after)) {
                            echo "Got retry after " . $sendAsDraftResultObject->parameters->retry_after ." when sending draft.: " . $sendAsDraftResultObject->error_code . ' '. $sendAsDraftResultObject->description . "\n";
                            $lastDraftTime = microtime(true) + $sendAsDraftResultObject->parameters->retry_after - self::DRAFT_FREQUENCY_SECONDS;
                        } else {
                            echo "Failed to send message as draft: " . $sendAsDraftResultObject->error_code . ' ' . $sendAsDraftResultObject->description . "\n";
                            $draftAborted = true;
                        }
                    }
                };
            } else {
                $editAborted = false;
                $lastEditTime = 0;
                $streamFunction = static function ($chunk) use (&$partialContent, &$message, &$editAborted, &$lastEditTime, $responseStart) {
                    echo $chunk;
                    $partialContent .= $chunk;
                    if ($editAborted) {
                        return;
                    }
                    if (mb_strlen($partialContent) < 32) {
                        return;
                    }
                    $currentEditTime = microtime(true);
                    if ($currentEditTime - $lastEditTime < self::EDIT_FREQUENCY_SECONDS) {
                        return;
                    }
                    $lastEditTime = $currentEditTime;
                    $messageText = $responseStart . TelegramMarkdownV2::makeValid($partialContent) . ' **\.\.\.**';
                    if ($message->id === null) {
                        $message->messageText = $messageText;
                        $sendResult = $message->send();
                        if (!$sendResult->isOk()) {
                            echo "Failed to send initial message for streaming: " . $sendResult->getErrorCode() . ' ' . $sendResult->getDescription() . "\n";
                            $editAborted = true;
                        }
                    } else {
                        $editResult = $message->edit($messageText, false);
                        if (!$editResult->isOk()) {
                            var_dump($editResult);
                            if ($editResult->getErrorCode() === 429 && isset($editResult->getRawData()['parameters']['retry_after'])) {
                                echo "Got retry after " . $editResult->getRawData()['parameters']['retry_after'] . " when editing message.: " . $editResult->getErrorCode() . ' ' . $editResult->getDescription() . "\n";
                                $lastEditTime = microtime(true) + $editResult->getRawData()['parameters']['retry_after'] - self::EDIT_FREQUENCY_SECONDS;
                            } else {
                                echo "Failed to edit message for streaming: " . $editResult->getErrorCode() . ' ' . $editResult->getDescription() . "\n";
                                $editAborted = true;
                            }
                        }
                    }
                };
            }
            $message->messageText = TelegramMarkdownV2::makeValid($responseStart . trim(
                    $this->getCompletionBasedOnContext($assistantContext, $streamFunction, $messageChain)
                ));
        } catch (\Exception $e) {
            echo "Failed to get completion based on context: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }

        return new ProcessingResult($message, true);
    }

    protected function convertMessageChainToAssistantContext(
        MessageChain $messageChain,
        ?string $systemPrompt,
        ?string $responseStart,
        ProgressUpdateCallback $progressUpdateCallback
    ): AssistantContext {
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = $systemPrompt;
        $assistantContext->responseStart = $responseStart;
        foreach ($messageChain->getMessages() as $message) {
            $assistantContextMessage = new AssistantContextMessage();
            $assistantContextMessage->text = $message->messageText;
            $assistantContextMessage->isUser = $message->userId !== $this->telegramBotUserId;
            if ($message->photoFileId !== null) {
                if ($this->supportsImages) {
                    $assistantContextMessage->photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($message);
                } else {
                    $assistantContextMessage->text .= $this->altTextProvider->provide($message, $progressUpdateCallback);
                }
            } elseif ($assistantContextMessage->text === '') {
                $altText = $this->altTextProvider->provide($message, $progressUpdateCallback);
                if ($altText !== null) {
                    $assistantContextMessage->text = $this->altTextProvider->provide($message, $progressUpdateCallback);
                }
            }
            $assistantContext->messages[] = $assistantContextMessage;
        }

        return $assistantContext;
    }

}
