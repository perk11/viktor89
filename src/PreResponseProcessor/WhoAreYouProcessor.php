<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class WhoAreYouProcessor implements PreResponseProcessor
{
    private array $viktor89Stickers = [
        'CAACAgIAAxkBAAIGvGZhcMm-j-Fa2u-jsOXYTBpNHPGpAAKxTQACaw0oSRHS0GD7_dE6NQQ',
        'CAACAgIAAxkBAAIGvWZhcNXDnZVd9vZ4Rydl7KyKeDcCAAJyWwACpg2ISv8GUoIYyRcrNQQ',
    ];

    public function process(Message $message): false|string|null
    {
        $incomingMessageTextLower = mb_strtolower($message->getText());
        $whoAreYouTriggerPhrases = [
            'как тебя зовут',
            'тебя как зовут',
            'ты кто?',
            'кто ты?',
        ];
        foreach ($whoAreYouTriggerPhrases as $whoAreYouTriggerPhrase) {
            if (str_contains($incomingMessageTextLower, $whoAreYouTriggerPhrase)) {
                echo "Sending message with viktor89 sticker\n";
                Request::sendSticker([
                                         'chat_id'          => $message->getChat()->getId(),
                                         'reply_parameters' => [
                                             'message_id' => $message->getMessageId(),
                                         ],
                                         'sticker'          => $this->viktor89Stickers[array_rand(
                                             $this->viktor89Stickers
                                         )],
                                     ]);

                return null;
            }
        }

        return false;
    }
}
