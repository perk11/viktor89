<?php

namespace Perk11\Viktor89\Assistant;

use Longman\TelegramBot\Entities\PhotoSize;

class AssistantContextMessage
{
    public bool $isUser;
    public string $text;
    public ?string $photo = null;
}
