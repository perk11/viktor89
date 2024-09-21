<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;

class AllowedChatProcessor implements PreResponseProcessor
{
    public function __construct(private readonly array $allowedChatIds)
    {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getType() === 'command') {
            return false;
        }
        if (!in_array($message->getChat()->getId(), $this->allowedChatIds, false)) {
            return 'Эта функция отключена в вашем чате 🤣🤣🤣';
        }

        return false;
    }
}
