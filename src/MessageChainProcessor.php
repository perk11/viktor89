<?php

namespace Perk11\Viktor89;

interface MessageChainProcessor
{
    public function processMessageChain(MessageChain $messageChain): ProcessingResult;

}
