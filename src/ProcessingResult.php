<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class ProcessingResult
{
    public $callback;
    public function __construct(
        public readonly ?InternalMessage $response,
        public readonly bool $abortProcessing,
        public readonly ?string $reaction = null,
        public readonly ?InternalMessage $messageToReactTo = null,
        callable $callback = null,
    )
    {
        $this->callback = $callback;
    }
}
