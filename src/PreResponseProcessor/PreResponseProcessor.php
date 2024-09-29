<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;

/**
 * @deprecated Use MessageChainProcessor interface instead
 */
interface PreResponseProcessor
{
    /*
     * false = do nothing, let response continue
     * string = respond with this text
     * null = do not respond
     */
    public function process(Message $message): false|string|null;
}
