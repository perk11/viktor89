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

    // Database id of the message this represents, if it came from history.
    public ?int $messageId = null;

    /** @var ToolCall[] */
    public array $toolCalls = [];
}
