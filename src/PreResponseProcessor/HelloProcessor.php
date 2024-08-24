<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class HelloProcessor implements PreResponseProcessor
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
        'хау'
    ];

    private array $triggerUsers = [
        5461833561,
    ];

    public function process(Message $message): false|string|null
    {
        if (!in_array($message->getFrom()->getId(), $this->triggerUsers, true)) {
            return false;
        }
        $incomingMessageTextLower = mb_strtolower($message->getText());

        foreach ($this->triggerPhrases as $triggerPhrase) {
            if (str_contains($incomingMessageTextLower, $triggerPhrase)) {
                echo "Sending message with hello sticker\n";
                Request::sendSticker([
                                         'chat_id'          => $message->getChat()->getId(),
                                         'reply_parameters' => [
                                             'message_id' => $message->getMessageId(),
                                         ],
                                         'sticker'          => $this->responseStickers[array_rand(
                                             $this->responseStickers
                                         )],
                                     ]);

                return null;
            }
        }

        return false;
    }
}
