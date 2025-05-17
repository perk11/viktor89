<?php

namespace Perk11\Viktor89\RateLimiting;

class RateLimit
{
    public function __construct(public readonly int $chatId, public readonly int $maxMessages) {}
}
