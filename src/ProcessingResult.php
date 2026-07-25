<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Util\Telegram\ReactionSetter;

class ProcessingResult
{
    public $callback;
    public function __construct(
        public readonly ?InternalMessage $response,
        public readonly bool $abortProcessing,
        public readonly ?string $reaction = null,
        public readonly ?InternalMessage $messageToReactTo = null,
        ?callable $callback = null,
    )
    {
        if ($reaction !== null && !ReactionSetter::isReactionAllowed($reaction)) {
            throw new \InvalidArgumentException(
                "Unsupported reaction '$reaction': must be one of the emoji allowed by Telegram "
                . '(see ReactionSetter::ALLOWED_REACTIONS).'
            );
        }
        $this->callback = $callback;
    }
}
