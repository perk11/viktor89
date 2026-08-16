<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class UserSelectedAssistant implements MessageChainProcessor
{
    public function __construct(
        private readonly AssistantFactory $assistantFactory,
        private readonly UserPreferenceReaderInterface $assistantPreference,
        private readonly TextDocumentReader $textDocumentReader,
        private readonly MessageRepository $messageRepository,
        private readonly LoggerInterface $logger,
        private readonly int $telegramBotUserId,
    )
    {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $documentError = $this->loadTextDocumentContents($messageChain);
        if ($documentError !== null) {
            return $documentError;
        }

        $lastMessage = $messageChain->last();
        $chatId = $lastMessage->chatId;

        $assistant = null;
        $modelName = $this->assistantPreference->getCurrentPreferenceValue($lastMessage->userId);
        if ($modelName !== null) {
            try {
                $candidate = $this->assistantFactory->getAssistantInstanceByName($modelName);
                // Honour per-model allowedChatIds: a model selected in one chat
                // must not be used in a chat it is restricted from.
                if ($this->assistantFactory->isModelNameAllowedInChat($modelName, $chatId)) {
                    $assistant = $candidate;
                }
            } catch (UnknownAssistantException) {
                // Fall through to the default for this chat.
            }
        }

        $assistant ??= $this->assistantFactory->getDefaultAssistantInstanceForChat($chatId);

        return $assistant->processMessageChain($messageChain, $progressUpdateCallback);
    }

    /**
     * For every text document in the chain, read its contents and fold them
     * into the message's text (so the model treats the file as a prompt) and
     * persist that text to the database (so it survives into future history).
     * Returns a ProcessingResult (error reply) if any document is too large,
     * in which case the chain must not be forwarded to the model.
     */
    private function loadTextDocumentContents(MessageChain $messageChain): ?ProcessingResult
    {
        foreach ($messageChain->getMessages() as $message) {
            // Documents sent by the bot (over-long rich markdown delivered as a
            // .md file) already carry their full text in messageText, so
            // re-reading the file would duplicate the content in the prompt.
            if ($message->userId === $this->telegramBotUserId) {
                continue;
            }
            if (!$this->textDocumentReader->isTextDocument($message)) {
                continue;
            }
            try {
                $content = $this->textDocumentReader->readContent($message);
            } catch (DocumentTooLargeException $e) {
                $this->logger->log(
                    LogLevel::INFO,
                    "Document {$message->documentFileName} ({$e->size} bytes) exceeds the limit, not forwarding to the assistant"
                );
                $reply = InternalMessage::asResponseTo(
                    $message,
                    sprintf(
                        '📄 "%s" слишком большой (%s). Максимальный размер %s.',
                        $message->documentFileName ?? 'document',
                        self::formatSize($e->size),
                        self::formatSize(TextDocumentReader::MAX_SIZE_BYTES),
                    )
                );

                return new ProcessingResult($reply, true);
            }

            $instruction = trim($message->messageText);
            $message->messageText = $instruction === '' ? $content : $instruction . "\n\n" . $content;
            $this->messageRepository->updateMessageText($message->id, $message->chatId, $message->messageText);
        }

        return null;
    }

    private static function formatSize(int $bytes): string
    {
        return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }
}
