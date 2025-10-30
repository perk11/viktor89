<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class WhoAreYouProcessor implements MessageChainProcessor
{
    private array $viktor89Stickers = [
        'CAACAgIAAxkBAAIGvGZhcMm-j-Fa2u-jsOXYTBpNHPGpAAKxTQACaw0oSRHS0GD7_dE6NQQ',
        'CAACAgIAAxkBAAIGvWZhcNXDnZVd9vZ4Rydl7KyKeDcCAAJyWwACpg2ISv8GUoIYyRcrNQQ',
    ];

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $incomingMessageTextLower = mb_strtolower($messageChain->last()->messageText);
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
                                         'chat_id'          => $messageChain->last()->chatId,
                                         'reply_parameters' => [
                                             'message_id' => $messageChain->last()->id,
                                         ],
                                         'sticker'          => $this->viktor89Stickers[array_rand(
                                             $this->viktor89Stickers
                                         )],
                                     ]);

                return new ProcessingResult(null, true);
            }
        }

        return new ProcessingResult(null, false);
    }
}
