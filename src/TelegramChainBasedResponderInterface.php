<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;

interface TelegramChainBasedResponderInterface
{
    /** @param InternalMessage[] $messageChain */
    public function getResponseByMessageChain(array $messageChain): InternalMessage;

}
