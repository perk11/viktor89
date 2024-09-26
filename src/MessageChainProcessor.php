<?php

namespace Perk11\Viktor89;

interface MessageChainProcessor
{
    /** @param InternalMessage[] $messageChain */
    public function processMessageChain(array $messageChain): ProcessingResult;

}
