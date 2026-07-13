<?php

namespace Perk11\Viktor89\Assistant;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdateCallback;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;

abstract class AbstractOpenAIAPiAssistant implements AssistantInterface
{
    private const float EDIT_FREQUENCY_MIN_SECONDS = 1.5;
    private const float EDIT_FREQUENCY_MAX_SECONDS = 120;
    private const float SMALL_EDIT_MIN_TIME_SECONDS = 10;

    /**
     * Minimum interval between consecutive draft updates sent while streaming.
     * Declared as a static property so tests can override it to speed things up.
     */
    protected static float $draftFrequencySeconds = 0.7;

    protected readonly OpenAI $openAi;
    protected bool $suppressDraftUpdates = false;
    private ?string $progressUpdateStatus = null;
    protected ?string $modelName = null;

    private ?DraftUpdateCallback $draftUpdateCallback = null;

    public function setDraftUpdateCallback(DraftUpdateCallback $draftUpdateCallback): void
    {
        $this->draftUpdateCallback = $draftUpdateCallback;
    }
    public function setModelName(string $modelName): void
    {
        $this->modelName = $modelName;
    }
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
        $systemPrompt = 'Use Github Markdown for your responses, but never embed images. Today is ' . date('Y-m-d') . "\n";
        $systemPrompt .= $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond to the user in the language they use or request.\n";

        // Capture metadata now, before the (slow) completion call, so that
        // changes to the user's preferences mid-generation do not corrupt it.
        $metadataModel = $this->modelName;
        if ($this->systemPromptProcessor instanceof \Perk11\Viktor89\SystemPromptMetadataProviderInterface) {
            $metadataSystemPrompt = $this->systemPromptProcessor->getBaseSystemPrompt($userId);
            $metadataPersonaId = $this->systemPromptProcessor->getActivePersonaId($userId);
        } else {
            $metadataSystemPrompt = $systemPrompt;
            $metadataPersonaId = null;
        }

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
        $message->parseMode = 'RichMarkdown';

        $editFrequency = $this->getEditFrequency($userId);

        try {
            $partialContent = '';
            $this->progressUpdateStatus = 'Thinking...';
            $streamFunction = $this->createStreamFunction($message, $responseStart, $editFrequency, $partialContent);
            $progressUpdateCallback->subscribe(function (TaskUpdateMessage $taskUpdateMessage) use ($streamFunction) {
                $this->progressUpdateStatus = $taskUpdateMessage->status;
                $streamFunction(''); //Update status
            });


            $completion = $this->getCompletionBasedOnContext($assistantContext, $streamFunction, $messageChain, $progressUpdateCallback);
            $message->messageText = $responseStart . trim($completion->content);
            $message->toolCalls = $completion->toolCalls;
            $message->reasoning = $completion->reasoning;
            $message->model = $metadataModel;
            $message->systemPrompt = $metadataSystemPrompt;
            $message->personaId = $metadataPersonaId;

            if ($message->reasoning !== null) {
                $isPrivateChat = $lastMessage->chatId > 0;
                $message->reasoningForDisplay = $isPrivateChat
                    ? '<tg-thinking>' . $message->reasoning . "\n</tg-thinking>"
                    : $this->formatThinkingAsDetailsBlock($message->reasoning);
            }

            return new ProcessingResult($message, true);
        } catch (\Exception $e) {
            echo "Failed to get completion based on context: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            echo "Assistant context that failed:\n" . $assistantContext->describeForLog() . "\n";
            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }
    }

    private function createStreamFunction(
        InternalMessage $message,
        ?string $responseStart,
        float $editFrequency,
        string &$partialContent,
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

            if ($editingAborted || $this->suppressDraftUpdates) {
                return;
            }

            $currentTime = microtime(true);
            $frequency = $isDraft ? static::$draftFrequencySeconds : $editFrequency;

            if ($chunk !== '') { //Not a status update
                $timeSinceLastAction = $currentTime - $lastActionTime;
                if ($timeSinceLastAction < $frequency) {
                    return;
                }

                if (!$isDraft && $timeSinceLastAction < self::SMALL_EDIT_MIN_TIME_SECONDS && (mb_strlen(
                            $partialContent
                        ) - $lastLength < 64)) {
                    return;
                }
            }

            $lastActionTime = $currentTime;
            $lastLength = mb_strlen($partialContent);

            if ($isDraft) {
                $this->processDraftStream($message, $partialContent);
            } else {
                $this->processEditStream($message, $partialContent, $responseStart, $frequency, $editingAborted, $lastActionTime);
            }
        };
    }

    private function processDraftStream(InternalMessage $message, string $partialContent): void
    {
        if ($this->draftUpdateCallback === null) {
            return;
        }

        $text = $partialContent;
        if ($message->parseMode === 'RichMarkdown') {
            $text .= '<tg-thinking>' . $this->progressUpdateStatus . "...\n</tg-thinking>";
        }

        $this->draftUpdateCallback->updateDraft(
            new DraftState(
                $message->chatId,
                $message->ensureDraftId(),
                $text,
                $message->parseMode,
                $message->messageThreadId,
            )
        );
    }

    private function processEditStream(InternalMessage $message, string $partialContent, ?string $responseStart, float $frequency, bool &$editingAborted, float &$lastActionTime): void
    {
        $messageText = $responseStart . $partialContent . "\n```status\n" . $this->progressUpdateStatus ."...\n```";

        if ($message->id === null) {
            if (mb_strlen(trim($partialContent)) < 10) {
                echo "Refusing to send a message for edit stream that is too short: " . mb_strlen(trim($partialContent)) . "\n";
                return;
            }
            $message->messageText = $messageText;
            $sendResult = $message->send();
            if (!$sendResult->isOk()) {
                echo "Failed to send initial message for streaming: " . $sendResult->getErrorCode() . ' ' . $sendResult->getDescription() . "\n";
                $editingAborted = true;
            }
        } else {
            if (mb_strlen($messageText) > 32768) {
                $messageText = mb_substr($responseStart . $partialContent, 0, 32768);
                $editingAborted = true; // Truncating early and aborting updates once standard bounds are met
            }
            if ($this->draftUpdateCallback !== null) {
                // Route through DraftUpdater so edits share the same per-chat
                // throttle as drafts (no refresh timer needed for edits).
                $this->draftUpdateCallback->updateDraft(
                    new DraftState(
                        chatId: $message->chatId,
                        draftId: null,
                        text: $messageText,
                        parseMode: $message->parseMode,
                        editMessageId: $message->id,
                    )
                );

                return;
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

    protected function formatThinkingAsDetailsBlock(string $thinking): string
    {
        $thinking = trim($thinking);
        if ($thinking === '') {
            return '';
        }

        return "<details>\n<summary>Thinking</summary>\n$thinking\n</details>\n";
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
           $assistantContextMessage->reasoning = $message->reasoning;
           $assistantContextMessage->messageId = $message->id;
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
