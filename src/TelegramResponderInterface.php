<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;

/** @deprecated Use TelegramChainBasedResponderInterface instead */
interface TelegramResponderInterface
{
    public function getResponseByMessage(Message $message): string;
}
