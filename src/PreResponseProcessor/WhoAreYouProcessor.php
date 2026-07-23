<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class WhoAreYouProcessor implements MessageChainProcessor
{
    private array $viktor89Stickers = [
        'CAACAgIAAxkBAAIGvGZhcMm-j-Fa2u-jsOXYTBpNHPGpAAKxTQACaw0oSRHS0GD7_dE6NQQ',
        'CAACAgIAAxkBAAIGvWZhcNXDnZVd9vZ4Rydl7KyKeDcCAAJyWwACpg2ISv8GUoIYyRcrNQQ',
    ];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

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
                $this->logger->log(LogLevel::INFO, 'Sending message with viktor89 sticker');
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
