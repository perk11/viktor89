<?php

namespace Perk11\Viktor89\RateLimiting;

class BanTimeLeft
{
    public function __construct(public readonly int $chatId, public readonly int $timeInSeconds) {}
}
