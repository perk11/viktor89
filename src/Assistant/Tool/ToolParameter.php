<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use InvalidArgumentException;
use JsonSerializable;

final class ToolParameter
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public private(set) string $name,
        public private(set) readonly array $properties,
        public private(set) readonly bool $required = false,
    ) {
        $this->name = trim($this->name);

        if ($this->name === '') {
            throw new InvalidArgumentException('Parameter name cannot be empty.');
        }

        if ($this->properties === []) {
            throw new InvalidArgumentException('Properties definition cannot be empty.');
        }
    }
//
//    public function jsonSerialize(): array
//    {
//        return [
//            'name' => $this->name,
//            'properties' => $this->properties,
//            'required' => $this->required,
//        ];
//    }
}
