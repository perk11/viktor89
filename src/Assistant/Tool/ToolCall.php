<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

final class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $arguments,
        public ?string $result = null,
    ) {
    }
}
