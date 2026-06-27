<?php

namespace Perk11\Viktor89\Assistant;

use Longman\TelegramBot\Entities\PhotoSize;

use Perk11\Viktor89\Assistant\Tool\ToolCall;

class AssistantContextMessage
{
    public bool $isUser;
    public string $text;
    public ?string $photo = null;
    public ?string $reasoning = null;

    /** @var ToolCall[] */
    public array $toolCalls = [];
}
