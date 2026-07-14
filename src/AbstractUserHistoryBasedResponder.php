<?php

namespace Perk11\Viktor89;

use Exception;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Repository\MessageRepository;

/**
 * Shared machinery for commands that pick a target user (the author of the
 * replied-to message, or the caller themselves when not used as a reply),
 * read that user's recent messages from the DB and feed them to an LLM to
 * produce a short creative response (see RoastProcessor / ComplimentProcessor).
 */
abstract class AbstractUserHistoryBasedResponder implements MessageChainProcessor
{
    private const PER_MESSAGE_TEXT_LIMIT = 280;
    private const MAX_TRANSCRIPT_LENGTH = 32000;

    public function __construct(
        protected readonly MessageRepository $messageRepository,
        protected readonly AssistantInterface $assistant,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $triggeringMessage = $messageChain->last();
        $chatId = $triggeringMessage->chatId;

        [$targetUserId, $targetUserName] = $this->determineTarget($messageChain);
        $displayName = $targetUserName !== '' ? $targetUserName : 'User' . $targetUserId;

        $messages = $this->messageRepository->findLastMessagesByUserInChat(
            $chatId,
            $targetUserId,
            $this->getMessageLimit(),
        );

        $responseMessage = InternalMessage::asResponseTo($triggeringMessage);

        if ($messages === []) {
            $responseMessage->messageText = $this->getNoMessagesMessage($displayName);
            return new ProcessingResult($responseMessage, true);
        }

        [$transcript, $includedCount] = $this->buildTranscript($messages);

        $progressUpdateCallback(static::class, $this->getProgressMessage($displayName));

        try {
            $completion = $this->assistant->getCompletionBasedOnContext(
                $this->buildContext($transcript, $displayName, $triggeringMessage->messageText),
            )->content;
        } catch (Exception $e) {
            echo get_class($this) . ': assistant completion failed: ' . $e->getMessage() . "\n";
            $responseMessage->messageText = $this->getFailureMessage($displayName);
            return new ProcessingResult($responseMessage, true);
        }

        $responseMessage->messageText = $this->renderResponse(
            trim($completion),
            $displayName,
            $includedCount,
            $triggeringMessage->messageText,
        );

        return new ProcessingResult($responseMessage, true);
    }

    /**
     * @return array{0: int, 1: string} [$targetUserId, $targetUserName]
     */
    private function determineTarget(MessageChain $messageChain): array
    {
        $triggeringMessage = $messageChain->last();
        $previous = $messageChain->previous();
        if ($previous !== null) {
            return [$previous->userId, $previous->userName];
        }

        return [$triggeringMessage->userId, $triggeringMessage->userName];
    }

    /**
     * Turns the newest-first message list into a chronological transcript of
     * the target user's own text, keeping the most recent messages when the
     * overall length budget is exceeded.
     *
     * @param InternalMessage[] $messages newest-first
     * @return array{0: string, 1: int} [$transcript, $includedCount]
     */
    private function buildTranscript(array $messages): array
    {
        $lines = [];
        $length = 0;
        foreach ($messages as $message) {
            $text = trim($message->messageText);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > self::PER_MESSAGE_TEXT_LIMIT) {
                $text = mb_strimwidth($text, 0, self::PER_MESSAGE_TEXT_LIMIT, '…');
            }
            $line = '- ' . $text;
            if ($lines !== [] && $length + mb_strlen($line) > self::MAX_TRANSCRIPT_LENGTH) {
                break;
            }
            $lines[] = $line;
            $length += mb_strlen($line);
        }

        return [implode("\n", array_reverse($lines)), count($lines)];
    }

    private function buildContext(string $transcript, string $displayName, string $arguments): AssistantContext
    {
        $context = new AssistantContext();
        $context->systemPrompt = $this->getSystemPrompt($displayName, $arguments);

        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = "Recent messages written by {$displayName} (newest last):\n\n" . $transcript;
        $context->messages[] = $message;

        return $context;
    }

    /** Maximum number of the user's past messages to fetch. */
    abstract protected function getMessageLimit(): int;

    /** LLM system prompt, possibly shaped by the command's arguments (e.g. heat level). */
    abstract protected function getSystemPrompt(string $displayName, string $arguments): string;

    abstract protected function getProgressMessage(string $displayName): string;

    abstract protected function getNoMessagesMessage(string $displayName): string;

    abstract protected function getFailureMessage(string $displayName): string;

    abstract protected function renderResponse(string $completion, string $displayName, int $includedCount, string $arguments): string;
}
