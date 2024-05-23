<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;

interface TelegramResponderInterface
{
    public function getResponseByMessage(Message $message): string;
}
