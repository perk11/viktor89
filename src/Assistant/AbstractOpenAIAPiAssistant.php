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

        $progressUpdateCallback(
            static::class,
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

            $streamFunction = $this->createStreamFunction($message, $responseStart, $editFrequency, $partialContent);

            $completion = $this->getCompletionBasedOnContext($assistantContext, $streamFunction, $messageChain);
            $message->messageText = TelegramMarkdownV2::makeValid($responseStart . trim($completion->content));
            $message->toolCalls = $completion->toolCalls;
        } catch (\Exception $e) {
            echo "Failed to get completion based on context: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }

        return new ProcessingResult($message, true);
    }

    private function createStreamFunction(
        InternalMessage $message,
        ?string $responseStart,
        float $editFrequency,
        string &$partialContent
    ): \Closure {
        $isDraft = $message->chatId > 0;
        $editingAborted = false;
        $lastActionTime = 0;
        $lastLength = 0;

        return function (string $chunk) use (
            $message, $isDraft, $responseStart, $editFrequency,
            &$partialContent, &$editingAborted, &$lastActionTime, &$lastLength
        ) {
            echo $chunk;
            $partialContent .= $chunk;

            if ($editingAborted) {
                return;
            }

            $currentTime = microtime(true);
            $frequency = $isDraft ? self::DRAFT_FREQUENCY_SECONDS : $editFrequency;

            if ($currentTime - $lastActionTime < $frequency) {
                return;
            }

            // Edit-specific throttling
            if (!$isDraft) {
                if ($lastLength === 0) {
                    if (mb_strlen($partialContent) < 32) {
                        return;
                    }
                } elseif (($currentTime - $lastActionTime) < self::SMALL_EDIT_MIN_TIME_SECONDS && (mb_strlen($partialContent) - $lastLength < 64)) {
                    return;
                }
            }

            $lastActionTime = $currentTime;
            $lastLength = mb_strlen($partialContent);

            if ($isDraft) {
                $this->processDraftStream($message, $partialContent, $frequency, $editingAborted, $lastActionTime);
            } else {
                $this->processEditStream($message, $partialContent, $responseStart, $frequency, $editingAborted, $lastActionTime);
            }
        };
    }

    private function processDraftStream(InternalMessage $message, string $partialContent, float $frequency, bool &$aborted, float &$lastActionTime): void
    {
        $message->messageText = TelegramMarkdownV2::makeValid($partialContent);
        $sendAsDraftResult = $message->sendAsDraft();
        $sendAsDraftResultObject = json_decode($sendAsDraftResult, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Failed to parse result of sending message as draft: " . json_last_error_msg() . "\n";
            $aborted = true;
            return;
        }

        if ($sendAsDraftResultObject->ok === false) {
            var_dump($sendAsDraftResultObject);
            $retryAfter = $sendAsDraftResultObject->parameters->retry_after ?? null;
            $this->handleRateLimitError(
                $sendAsDraftResultObject->error_code,
                $retryAfter,
                $sendAsDraftResultObject->description,
                "sending draft",
                "send message as draft",
                $frequency,
                $aborted,
                $lastActionTime
            );
        }
    }

    private function processEditStream(InternalMessage $message, string $partialContent, ?string $responseStart, float $frequency, bool &$editingAborted, float &$lastActionTime): void
    {
        $messageText = $responseStart . TelegramMarkdownV2::makeValid($partialContent) . ' **\.\.\.**';

        if ($message->id === null) {
            $message->messageText = $messageText;
            $sendResult = $message->send();
            if (!$sendResult->isOk()) {
                echo "Failed to send initial message for streaming: " . $sendResult->getErrorCode() . ' ' . $sendResult->getDescription() . "\n";
                $editingAborted = true;
            }
        } else {
            if (mb_strlen($messageText) > 4000) {
                $messageText = TelegramMarkdownV2::makeValid(mb_substr($responseStart . $partialContent, 0, 4000));
                $editingAborted = true; // Truncating early and aborting updates once standard bounds are met
            }
            $editResult = $message->edit($messageText, false);
            if (!$editResult->isOk()) {
                var_dump($editResult);
                $rawData = $editResult->getRawData();
                $retryAfter = $rawData['parameters']['retry_after'] ?? null;
                $this->handleRateLimitError(
                    $editResult->getErrorCode(),
                    $retryAfter,
                    $editResult->getDescription(),
                    "editing message",
                    "edit message for streaming",
                    $frequency,
                    $editingAborted,
                    $lastActionTime
                );
            }
        }
    }

    private function handleRateLimitError(
        int $errorCode,
        ?int $retryAfter,
        string $description,
        string $retryActionContext,
        string $failActionContext,
        float $frequency,
        bool &$aborted,
        float &$lastActionTime
    ): void {
        if ($errorCode === 429 && $retryAfter !== null) {
            echo "Got retry after {$retryAfter} when {$retryActionContext}.: {$errorCode} {$description}\n";
            $lastActionTime = microtime(true) + $retryAfter - $frequency;
        } else {
            echo "Failed to {$failActionContext}: {$errorCode} {$description}\n";
            $aborted = true;
        }
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
            $assistantContextMessage->toolCalls = $message->toolCalls;
            if ($message->photoFileId !== null) {
                if ($this->supportsImages) {
                    $assistantContextMessage->photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($message);
                } else {
                    $assistantContextMessage->text .= $this->altTextProvider->provide($message, $progressUpdateCallback);
                }
            } elseif ($assistantContextMessage->text === '') {
                $altText = $this->altTextProvider->provide($message, $progressUpdateCallback);
                if ($altText !== null) {
                    $assistantContextMessage->text = $altText;
                }
            }
            $assistantContext->messages[] = $assistantContextMessage;
        }

        return $assistantContext;
    }

}
