<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Audio;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Video;
use Longman\TelegramBot\Entities\VideoNote;
use Longman\TelegramBot\Entities\Voice;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use Perk11\Viktor89\Util\TelegramRichMarkdown;
use Perk11\Viktor89\VoiceGeneration\MessageAudio;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class InternalMessage
{
    /**
     * Logger shared across all InternalMessage instances. InternalMessage is a
     * plain value object created all over the codebase, so the logger is injected
     * statically from the composition root instead of through a constructor.
     */
    private static ?LoggerInterface $logger = null;

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /** Maximum number of times edit() will back off and retry on a Telegram 429. */
    private const int EDIT_RATE_LIMIT_MAX_RETRIES = 5;

    public ?int $id = null;
    public ?int $draftId = null;

    public string $type;

    public ?int $messageThreadId = null;

    public int $userId;

    public $date;

    public ?int $replyToMessageId = null;

    public string $userName;

    // Message with bot mention removed.
    // Will be used for sending a message if $rawMessageText is null
    // Can contain a photo caption
    public string $messageText;

    public string $parseMode = 'Default';

    // Original message text.
    // Gets sent when calling send()
    // Contains bot mentions.
    // Cannot contain photo caption.
    public ?string $rawMessageText = null;

    public int $chatId;

    /**
     * When set, send() delivers the message as a Telegram ephemeral message
     * (visible only to this user) in group/supergroup chats; null in private
     * chats or after a fallback to a regular message. Persisted as
     * receiver_user_id so history can tell who an ephemeral message was for.
     */
    public ?int $receiverUserId = null;

    public ?string $photoFileId = null;
    public ?string $altText = null;
    public ?string $reasoning = null;

    /**
     * If set, this will be prepended to messageText when sending to Telegram,
     * but it will NOT be saved to the database.
     */
    public ?string $reasoningForDisplay = null;

    /** Everything below is currently not stored in the database */
    public ?Audio $audio = null;
    public ?Video $video = null;
    public ?VideoNote $videoNote = null;
    public ?Voice $voice = null;

    public bool $isSaved = false;

    public bool $removeKeyboard = false;
    public bool $forceReply = false;

    /** @var ToolCall[] */
    public array $toolCalls = [];
    public ?string $model = null;
    public ?string $systemPrompt = null;
    public ?int $personaId = null;
    public ?string $caption = null;
    public static function fromSqliteAssoc(array $result): self
    {
        $message = new self();
        $message->id = $result['id'];
        $message->type = $result['type'];
        $message->messageThreadId = $result['message_thread_id'];
        $message->userId = $result['user_id'];
        $message->chatId = $result['chat_id'];
        $message->date = $result['date'];
        $message->replyToMessageId = $result['reply_to_message'];
        $message->userName = $result['username'];
        $message->messageText = $result['message_text'];
        $message->photoFileId = $result['photo_file_id'];
        $message->altText = $result['alt_text'] ?? null;
        $message->reasoning = $result['reasoning'] ?? null;
        $message->receiverUserId = isset($result['receiver_user_id']) ? (int)$result['receiver_user_id'] : null;
        $message->isSaved = true;

        return $message;
    }
    public static function extractPropertiesFromTelegramMessage(InternalMessage $message, Message $telegramMessage)
    {
        $message->id = $telegramMessage->getMessageId();
        $message->type = $telegramMessage->getType();
        $message->messageThreadId = $telegramMessage->getMessageThreadId();
        $message->userId = $telegramMessage->getFrom()->getId();
        $message->date = $telegramMessage->getDate();
        $message->replyToMessageId = $telegramMessage->getReplyToMessage()?->getMessageId();
        $message->userName = $telegramMessage->getFrom()->getFirstName();
        if ($telegramMessage->getFrom()->getLastName() !== null) {
            $message->userName .= ' ' . $telegramMessage->getFrom()->getLastName();
        }
        $message->chatId = $telegramMessage->getChat()->getId();
        $message->rawMessageText = $telegramMessage->getText();
        if ($message->rawMessageText !== null) {
            $message->messageText = $message->rawMessageText;
        }
        if (!isset($message->messageText) || $message->messageText === '') {
            if ($telegramMessage->getCaption() !== null) {
                $message->messageText = $telegramMessage->getCaption();
            } else {
                $message->messageText = '';
            }
        }
        $message->messageText = preg_replace(
            '/@' . preg_quote($_ENV['TELEGRAM_BOT_USERNAME'], '/'). '?(\s+)/',
            '',
            $message->messageText,
        );
        if ($telegramMessage->getPhoto() !== null) {
            $maxSize = 0;
            foreach ($telegramMessage->getPhoto() as $photo) {
                if ($photo->getFileSize() >= $maxSize) {
                    $maxSize = $photo->getFileSize();
                    $message->photoFileId = $photo->getFileId();
                }
            }
        }
        if ($message->photoFileId === null)  {
            if ($telegramMessage->getDocument() !== null) {
                $convertToPhotoExtensions = [
                    'jpg',
                    'png',
                    'jpeg',
                    'webp',
                ];
                $filename = $telegramMessage->getDocument()->getFileName();
                foreach ($convertToPhotoExtensions as $extension) {
                    if (str_ends_with($filename, $extension)) {
                        $message->photoFileId = $telegramMessage->getDocument()->getFileId();
                        break;
                    }
                }
            } elseif ($telegramMessage->getSticker() !== null) {
                $message->photoFileId = $telegramMessage->getSticker()->getFileId();
            }
        }
        $message->audio = $telegramMessage->getAudio();
        $message->video = $telegramMessage->getVideo();
        $message->videoNote = $telegramMessage->getVideoNote();
        $message->voice = $telegramMessage->getVoice();

    }

    public static function fromTelegramMessage(Message $telegramMessage): self
    {
        $message = new self();
        self::extractPropertiesFromTelegramMessage($message, $telegramMessage);
        return $message;
    }

    public function ensureDraftId(): int
    {
        if ($this->draftId === null) {
            $this->draftId = random_int(1, 1000000000);
        }

        return $this->draftId;
    }

    public function sendAsDraft(): string
    {
        $this->ensureDraftId();

        $options = [
            'chat_id' => $this->chatId,
            'draft_id' => $this->draftId,
        ];
        if (isset($this->messageThreadId)) {
            $options['message_thread_id'] = $this->messageThreadId;
        }
        if ($this->parseMode === 'RichMarkdown') {
            $options['rich_message'] = [
                'markdown' => TelegramRichMarkdown::removeImages($this->rawMessageText ?? $this->messageText),
            ];

            return Request::execute('sendRichMessageDraft', $options);
        }
        $options['text'] = $this->rawMessageText ?? $this->messageText;
        if ($this->parseMode !== 'Default') {
            $options['parse_mode'] = $this->parseMode;
        }

        return Request::execute('sendMessageDraft', $options);
    }

    public function send(): ServerResponse
    {
        // Capture before doSend(): extractPropertiesFromTelegramMessage() overwrites
        // replyToMessageId from the (possibly reply-less) API response, but the
        // salute reaction must target the public message we replied to.
        $triggerMessageId = $this->replyToMessageId;
        $response = $this->doSend();
        if ($this->receiverUserId !== null && !$response->isOk()) {
            self::$logger?->log(LogLevel::WARNING, "Ephemeral message failed ({$response->getDescription()}), retrying as a regular message");
            $this->receiverUserId = null;
            $response = $this->doSend();
        }
        if ($this->receiverUserId !== null && $response->isOk()) {
            self::setEphemeralReaction($this->chatId, $triggerMessageId);
        }

        return $response;
    }

    /**
     * Ephemeral messages are invisible to other chat members, so we drop a salute
     * (🫡) reaction on the public triggering message so others can tell the bot
     * answered privately. No-op outside group/supergroup chats or without a
     * message to react to. Failures are non-fatal (e.g. reacting to a service msg).
     */
    public static function setEphemeralReaction(int $chatId, ?int $messageIdToReactTo): void
    {
        if ($chatId >= 0 || $messageIdToReactTo === null) {
            return;
        }
        try {
            Request::execute('setMessageReaction', [
                'chat_id'    => $chatId,
                'message_id' => $messageIdToReactTo,
                'reaction'   => [['type' => 'emoji', 'emoji' => '🫡']],
            ]);
        } catch (\Throwable $e) {
            self::$logger?->log(LogLevel::ERROR, "Failed to set ephemeral salute reaction: {$e->getMessage()}");
        }
    }

    private function doSend(): ServerResponse
    {
        $options = [
            'chat_id' => $this->chatId,
        ];
        if ($this->replyToMessageId !== null) {
            $options['reply_parameters'] = ['message_id' => $this->replyToMessageId];
        }
        if ($this->removeKeyboard) {
            $options['reply_markup'] = [
                'remove_keyboard' => true,
            ];
        } else if ($this->forceReply) {
            $options['reply_markup'] = [
                'force_reply' => true,
                'selective' => true,
            ];
        }
        // receiver_user_id (ephemeral delivery) is only valid in group/supergroup
        // chats AND only accepted when the bot is a chat administrator; otherwise
        // the message is already private (PM) or ephemeral would be rejected.
        if ($this->receiverUserId !== null && $this->chatId < 0 && BotAdminChecker::isBotAdminInChat($this->chatId)) {
            $options['receiver_user_id'] = $this->receiverUserId;
        } else {
            $this->receiverUserId = null;
        }
        if ($this->parseMode === 'RichMarkdown') {
            $options['rich_message'] = [
                'markdown' => TelegramRichMarkdown::removeImages($this->rawMessageText ?? $this->messageText),
            ];
            $rawResponse = Request::execute('sendRichMessage', $options);
            $response = json_decode($rawResponse, true);

            if (null === $response || json_last_error() !== JSON_ERROR_NONE) {
                TelegramLog::debug($rawResponse);
                throw new TelegramException('Telegram returned an invalid response!');
            }

            $response = new ServerResponse($response, $_ENV['TELEGRAM_BOT_USERNAME']);
        } else {
            $options['text'] = $this->rawMessageText ?? $this->messageText;
            if ($this->parseMode !== 'Default') {
                $options['parse_mode'] = $this->parseMode;
            }
            $response = Request::sendMessage($options);
        }

        if ($response->isOk()) {
            self::extractPropertiesFromTelegramMessage($this, $response->getResult());
            return $response;
        }
        if ($this->parseMode === 'MarkdownV2') {
            self::$logger?->log(LogLevel::ERROR, 'Message failed to send in ' . $options['parse_mode'] . ' mode, trying again in Markdown mode: ' . print_r($response->getRawData(), true));
            $options['parse_mode'] = 'markdown';

            $response =  Request::sendMessage($options);
            if ($response->isOk()) {
                self::extractPropertiesFromTelegramMessage($this, $response->getResult());
                return $response;
            }
        }

        self::$logger?->log(LogLevel::ERROR, 'Message failed to send in ' . $this->parseMode . ' mode, trying again in Default mode: ' . print_r($response->getRawData(), true));

        unset($options['parse_mode']);
        if ($this->parseMode === 'RichMarkdown') {
            $options['text'] = $this->rawMessageText ?? $this->messageText;
            unset($options['rich_message']);
        }

        $response = Request::sendMessage($options);
        if ($response->isOk()) {
            self::extractPropertiesFromTelegramMessage($this, $response->getResult());
        }
        return $response;
    }

    public function edit(string $newText, $autoRetry = true): ServerResponse
    {
        $textToEdit = $newText;
        if ($this->reasoningForDisplay !== null) {
            $textToEdit = $this->reasoningForDisplay . $newText;
        }

        $options = [
            'chat_id' => $this->chatId,
            'message_id' => $this->id,
        ];

        if ($this->parseMode === 'RichMarkdown') {
            $options['rich_message'] = [
                'markdown' => TelegramRichMarkdown::removeImages($textToEdit),
            ];
        } else {
            $options['text'] = $textToEdit;
            if ($this->parseMode !== 'Default') {
                $options['parse_mode'] = $this->parseMode;
            }
        }

        $response = Request::editMessageText($options);
        $attempts = 0;
        while (
            $autoRetry
            && !$response->isOk()
            && $response->getErrorCode() === 429
            && isset($response->getRawData()['parameters']['retry_after'])
            && $attempts < self::EDIT_RATE_LIMIT_MAX_RETRIES
        ) {
            $retryAfter = $response->getRawData()['parameters']['retry_after'];
            self::$logger?->log(LogLevel::INFO, "Got retry after {$retryAfter} when editing message, retrying: {$response->getDescription()}");
            sleep(min($retryAfter, 120));
            $attempts++;
            $response = Request::editMessageText($options);
        }

        if ($response->isOk()) {
            $this->messageText = $newText;
            if ($this->rawMessageText !== null) {
                $this->rawMessageText = $newText;
            }
        }

        return $response;
    }

    public static function asResponseTo(InternalMessage $messageToRespondTo, ?string $responseText = null): self
    {
        $message = new self();
        $message->replyToMessageId = $messageToRespondTo->id;
        $message->chatId = $messageToRespondTo->chatId;
        if ($responseText !== null) {
            $message->messageText = $responseText;
        }

        return $message;
    }

    public function isCommand(): bool
    {
        return str_starts_with($this->messageText, '/');
    }

    public function withReplacedText(string $newText): self
    {
        $clone = clone $this;
        $clone->messageText = $newText;
        return $clone;
    }

    public function getMessageAudio(): ?MessageAudio
    {
        if ($this->audio !== null) {
            return new MessageAudio($this->audio->getFileId(), $this->audio->getFileName() ?? 'audio.ogg', 'audio');
        }
        if ($this->video !== null) {
            return new MessageAudio($this->video->getFileId(), $this->video->getFileName() ?? 'video.mp4', 'video');
        }

        if ($this->voice !== null) {
            return new MessageAudio($this->voice->getFileId(), 'voice.ogg', 'voice');
        }

        if ($this->videoNote !== null) {
            return new MessageAudio($this->videoNote->getFileId(), 'videoNote.mp4', 'videoNote');
        }

        return null;
    }
}
