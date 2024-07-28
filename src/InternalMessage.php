<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class InternalMessage
{
    public int $id;

    public ?int $messageThreadId = null;

    public int $userId;

    public $date;

    public ?int $replyToMessageId = null;

    public string $userName;

    public string $messageText;

    public string $parseMode = 'Default';

    public ?string $actualMessageText = null;

    public int $chatId;

    public static function fromSqliteAssoc(array $result): self
    {
        $message = new self();
        $message->id = $result['id'];
        $message->messageThreadId = $result['message_thread_id'];
        $message->userId = $result['user_id'];
        $message->chatId = $result['chat_id'];
        $message->date = $result['date'];
        $message->replyToMessageId = $result['reply_to_message'];
        $message->userName = $result['username'];
        $message->messageText = $result['message_text'];

        return $message;
    }

    public static function fromTelegramMessage(Message $telegramMessage): self
    {
        $message = new self();
        $message->id = $telegramMessage->getMessageId();
        $message->messageThreadId = $telegramMessage->getMessageThreadId();
        $message->userId = $telegramMessage->getFrom()->getId();
        $message->date = $telegramMessage->getDate();
        $message->replyToMessageId = $telegramMessage->getReplyToMessage()?->getMessageId();
        $message->userName = $telegramMessage->getFrom()->getFirstName();
        if ($telegramMessage->getFrom()->getLastName() !== null) {
            $message->userName .= ' ' . $telegramMessage->getFrom()->getLastName();
        }
        $message->chatId = $telegramMessage->getChat()->getId();
        $message->actualMessageText = $telegramMessage->getText();
        $message->messageText = ltrim(str_replace(
            '@' . $_ENV['TELEGRAM_BOT_USERNAME'],
            '',
            $message->actualMessageText)
        );

        return $message;
    }

    public function send(): ServerResponse
    {
        $options = [
            'chat_id' => $this->chatId,

            'text' => $this->actualMessageText ?? $this->messageText,
        ];
        if ($this->replyToMessageId !== null) {
            $options['reply_parameters'] = ['message_id' => $this->replyToMessageId];
        }
        if ($this->parseMode !== 'Default') {
            $options['parse_mode'] = $this->parseMode;
        }

        $response =  Request::sendMessage($options);
        if ($options['parse_mode'] === 'Default') {
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
}
