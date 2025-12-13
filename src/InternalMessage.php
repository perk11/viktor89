<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Audio;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Video;
use Longman\TelegramBot\Entities\VideoNote;
use Longman\TelegramBot\Entities\Voice;
use Longman\TelegramBot\Request;

class InternalMessage
{
    public int $id;

    public string $type;

    public ?int $messageThreadId = null;

    public int $userId;

    public $date;

    public ?int $replyToMessageId = null;

    public string $userName;

    // Message with bot mention removed.
    // Will be used for sending a message if $rawMessageText is null
    // Can contains a photo caption
    public string $messageText;

    public string $parseMode = 'Default';

    // Original message text.
    // Gets sent when calling send()
    // Contains bot mentions.
    // Cannot contain photo caption.
    public ?string $rawMessageText = null;

    public int $chatId;

    public ?string $photoFileId = null;

    /** Everything below is currently not stored in the database */
    public ?Audio $audio = null;
    public ?Video $video = null;
    public ?VideoNote $videoNote = null;
    public ?Voice $voice = null;

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

        return $message;
    }

    public static function fromTelegramMessage(Message $telegramMessage): self
    {
        $message = new self();
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

        return $message;
    }

    public function send(): ServerResponse
    {
        $options = [
            'chat_id' => $this->chatId,
            'text' => $this->rawMessageText ?? $this->messageText,
        ];
        if ($this->replyToMessageId !== null) {
            $options['reply_parameters'] = ['message_id' => $this->replyToMessageId];
        }
        if ($this->parseMode !== 'Default') {
            $options['parse_mode'] = $this->parseMode;
        }

        $response =  Request::sendMessage($options);
        if (!isset($options['parse_mode']) || $options['parse_mode'] === 'Default') {
            return $response;
        }
        if ($response->isOk()) {
            return $response;
        }
        if ($options['parse_mode'] === 'MarkdownV2') {
            print_r($response->getRawData());
            echo "\nMessage failed to send in " . $options['parse_mode'] . " mode, trying again in Markdown mode\n";
            $options['parse_mode'] = 'markdown';

            $response =  Request::sendMessage($options);
            if ($response->isOk()) {
                return $response;
            }
        }

        print_r($response->getRawData());
        echo "\nMessage failed to send in ". $options['parse_mode'] . " mode, trying again in Default mode\n";

        unset($options['parse_mode']);
        return Request::sendMessage($options);
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
}
