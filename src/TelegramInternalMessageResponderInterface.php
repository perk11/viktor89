<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;

interface TelegramInternalMessageResponderInterface
{
    public function getResponseByMessage(Message $message): ?InternalMessage;
}
