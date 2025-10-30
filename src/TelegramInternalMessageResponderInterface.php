<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;

/** @deprecated Use TelegramChainBasedResponderInterface instead  */
interface TelegramInternalMessageResponderInterface
{
    public function getResponseByMessage(Message $message, ProgressUpdateCallback $progressUpdateCallback): ?InternalMessage;
}
