<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Reads the plain-text documents attached to messages of a chain and folds
 * their contents into each message's text (instruction + "\n\n" + file
 * contents), persisting the result so it survives into future history. This
 * is what lets any prompt-driven consumer (assistants, /image, /video, …)
 * treat an attached text file as part of the prompt.
 *
 * Every document is handled exactly once: processed documents are marked via
 * alt_text, so when the same document re-enters a later chain (e.g. the user
 * replies to it and Engine re-attaches the fresh Telegram file id), it is
 * skipped instead of being re-folded or re-reporting the too-large error.
 */
class MessageChainTextDocumentLoader
{
    /** Set on a document whose contents were folded into message_text. */
    private const string ALT_TEXT_DOCUMENT_LOADED = '[содержимое документа уже добавлено к тексту сообщения]';

    /** Set on a document that exceeded the size cap and was reported to the user. */
    private const string ALT_TEXT_DOCUMENT_TOO_LARGE = '[документ слишком большой, содержимое не используется]';

    public function __construct(
        private readonly TextDocumentReader $textDocumentReader,
        private readonly MessageRepository $messageRepository,
        private readonly LoggerInterface $logger,
        private readonly int $telegramBotUserId,
    ) {
    }

    /**
     * @return ProcessingResult|null an error reply when a document exceeds the
     *                               size cap (the chain must not be forwarded
     *                               further), null on success
     */
    public function loadIntoChain(MessageChain $messageChain): ?ProcessingResult
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
            // Already processed in an earlier turn: the contents are folded
            // into messageText, or the too-large error was reported then.
            // Replying to the message later must not repeat either.
            if ($message->altText === self::ALT_TEXT_DOCUMENT_LOADED
                || $message->altText === self::ALT_TEXT_DOCUMENT_TOO_LARGE) {
                continue;
            }
            try {
                $content = $this->textDocumentReader->readContent($message);
            } catch (DocumentTooLargeException $e) {
                $this->logger->log(
                    LogLevel::INFO,
                    "Document {$message->documentFileName} ({$e->size} bytes) exceeds the limit, not using it as a prompt"
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
                $this->markProcessed($message, self::ALT_TEXT_DOCUMENT_TOO_LARGE);

                return new ProcessingResult($reply, true);
            }

            $instruction = trim($message->messageText);
            $message->messageText = $instruction === '' ? $content : $instruction . "\n\n" . $content;
            $this->messageRepository->updateMessageText($message->id, $message->chatId, $message->messageText);
            $this->markProcessed($message, self::ALT_TEXT_DOCUMENT_LOADED);
        }

        return null;
    }

    /**
     * Persist the marker (so it survives DB round-trips — the message re-read
     * from history keeps its alt_text while Engine re-attaches the fresh
     * Telegram file id) and consume the document in memory (so a repeated
     * load of the same chain cannot fold the contents in twice).
     */
    private function markProcessed(InternalMessage $message, string $marker): void
    {
        $message->altText = $marker;
        $this->messageRepository->updateAltText($message->id, $message->chatId, $marker);
        $message->documentFileId = null;
        $message->documentFileName = null;
        $message->documentMimeType = null;
        $message->documentFileSize = null;
    }

    private static function formatSize(int $bytes): string
    {
        return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }
}
