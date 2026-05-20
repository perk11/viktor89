<?php

namespace Perk11\Viktor89\Assistant;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Perk11\Viktor89\Util\TelegramMarkdownV2;

abstract class AbstractOpenAIAPiAssistant implements AssistantInterface
{
    private const float DRAFT_FREQUENCY_SECONDS = 0.7;
    private const float EDIT_FREQUENCY_MIN_SECONDS = 1.5;
    private const float EDIT_FREQUENCY_MAX_SECONDS = 120;
    private const float SMALL_EDIT_MIN_TIME_SECONDS = 10;

    protected readonly OpenAI $openAi;
    public function __construct(
        private readonly UserPreferenceReaderInterface $systemPromptProcessor,
        private readonly UserPreferenceReaderInterface $responseStartProcessor,
        private readonly UserPreferenceReaderInterface $editFrequencyProcessor,
        private readonly TelegramFileDownloader $telegramFileDownloader,
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
        $systemPrompt = 'Use Telegram Markdown for your responses. Today is ' . date('Y-m-d') . "\n";
        $systemPrompt .= $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond to the user in the language they use or request.\n";

        $userName = trim($messageChain->last()->userName);
        if ($userName !== "") {
            $systemPrompt = "User's name is \"$userName\".\n" . $systemPrompt;
        }
        $assistantContext = $this->convertMessageChainToAssistantContext($messageChain, $systemPrompt, $responseStart, $progressUpdateCallback);

        $progressUpdateCallback(static::class,
                                'Generating assistant response',
                                new ChatAction($messageChain->last()->chatId, ChatActionEnum::typing)
        );
        $lastMessage = $messageChain->last();
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->parseMode = 'MarkdownV2';
        $editFrequency = $this->getEditFrequency($userId);
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
                $lastLength = 0;
                $streamFunction = static function ($chunk) use (&$partialContent, &$message, &$editAborted, &$lastEditTime, &$lastLength, $responseStart, $editFrequency) {
                    echo $chunk;
                    $partialContent .= $chunk;
                    if ($editAborted) {
                        return;
                    }
                    $currentEditTime = microtime(true);
                    $timeSinceLastEdit = $currentEditTime - $lastEditTime;
                    if ($timeSinceLastEdit < $editFrequency) {
                        return;
                    }
                    if ($lastLength === 0) {
                      if (mb_strlen($partialContent) < 32) {
                          return;
                      }
                    } elseif ($timeSinceLastEdit < self::SMALL_EDIT_MIN_TIME_SECONDS && (mb_strlen($partialContent) - mb_strlen($lastLength) < 64)) {
                        return;
                    }
                    $lastLength = mb_strlen($partialContent);
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
                        if (mb_strlen($messageText) > 4000) {
                            $messageText = TelegramMarkdownV2::makeValid(mb_substr($responseStart . ($partialContent), 0, 4000));
                            $editAborted = true;
                        }
                        $editResult = $message->edit($messageText, false);
                        if (!$editResult->isOk()) {
                            var_dump($editResult);
                            if ($editResult->getErrorCode() === 429 && isset($editResult->getRawData()['parameters']['retry_after'])) {
                                echo "Got retry after " . $editResult->getRawData()['parameters']['retry_after'] . " when editing message.: " . $editResult->getErrorCode() . ' ' . $editResult->getDescription() . "\n";
                                $lastEditTime = microtime(true) + $editResult->getRawData()['parameters']['retry_after'] - $editFrequency;
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

    private function getEditFrequency(int $userId): float
    {
        $value = $this->editFrequencyProcessor->getCurrentPreferenceValue($userId);
        if ($value === null) {
            return self::EDIT_FREQUENCY_MIN_SECONDS;
        }
        $frequency = (float)$value;
        return max(self::EDIT_FREQUENCY_MIN_SECONDS, min(self::EDIT_FREQUENCY_MAX_SECONDS, $frequency));
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
