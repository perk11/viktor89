<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class HelloProcessor implements MessageChainProcessor
{
    private array $responseStickers = [
        'CAACAgIAAxkBAAIHmGZiqlXg4Xu_vdudCfhdVlxFwkoyAAKfUQACrcMZSqpvZIWPpX_uNQQ',
        'CAACAgIAAxkBAAIHmWZiqtzEzuJ8IjZwpw0RcsJkMCzKAAJPVwACB675SKEDKWsW3b-wNQQ',
        'CAACAgIAAxkBAAIHmmZiqu2u7XfyUyc2UNsTQZIhkZChAAIvSwACMjMpSQABIO6vm_ba0DUE',
        'CAACAgIAAxkBAAIHm2ZiqxRI6qN4j9Oez-BnBPDcW-cVAALSRgACVR94SXnh337D0AoQNQQ',
        'CAACAgIAAx0CfgRFTAACejtmyK_6_fwWAAEJfF6uqYOEuyI2874AAqdbAAIyVyhK1C-bb5CVf001BA', //хаухрен
        'CAACAgIAAx0CfgRFTAACejxmyK_8v5UXST8LbhiV236jAAFfwmkAAtZSAALgOPhLRGX-azlQ4uo1BA', //кучерт
        'CAACAgIAAx0CfgRFTAACej9myLAhM2_CccQtR9oXpLDDsVapxQACj0MAAsZ-oEikwEx-jhVAPTUE', //дарова хрены

    ];

    private array $triggerPhrases = [
        'дарова',
        'даровч',
        'привет',
        'хау',
    ];

    private array $triggerUsers = [
        5461833561,
        7010262656,
    ];

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        if (!in_array($messageChain->last()->userId, $this->triggerUsers, true)) {
            return new ProcessingResult(null, false);
        }
        $lastMessage = $messageChain->last();
        $incomingMessageTextLower = mb_strtolower($lastMessage->messageText);

        foreach ($this->triggerPhrases as $triggerPhrase) {
            if (str_contains($incomingMessageTextLower, $triggerPhrase)) {
                return new ProcessingResult(null, true, callback: function () use ($lastMessage) {
                    echo "Sending message with hello sticker\n";
                    Request::sendSticker([
                                             'chat_id'          => $lastMessage->chatId,
                                             'reply_parameters' => [
                                                 'message_id' => $lastMessage->id,
                                             ],
                                             'sticker'          => $this->responseStickers[array_rand(
                                                 $this->responseStickers
                                             )],
                                         ]);
                });
            }
        }

        return new ProcessingResult(null, false);
    }
}
