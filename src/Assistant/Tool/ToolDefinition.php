<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use InvalidArgumentException;
use JsonSerializable;

final class ToolDefinition implements JsonSerializable
{
    /**
     * @param list<ToolParameter> $parameters
     */
    public function __construct(
        private(set) readonly string $name,
        private(set) readonly ToolCallExecutorInterface $toolCallClass,
        private(set) readonly ?string $description,
        private(set) readonly array $parameters = [],
        private(set) readonly string $type = 'function',
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException('Tool name cannot be empty.');
        }

        if ($this->type !== 'function') {
            throw new InvalidArgumentException('Only "function" tools are supported.');
        }

        foreach ($this->parameters as $parameter) {
            if (!$parameter instanceof ToolParameter) {
                throw new InvalidArgumentException('All parameters must be instances of ToolParameter.');
            }
        }
    }

    public function toArray(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $parameter) {
            $properties[$parameter->name] = $parameter->properties;

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        $function = [
            'name'       => $this->name,
            'parameters' => [
                'type'       => 'object',
                'properties' => $properties,
            ],
        ];

        if ($this->description !== null && $this->description !== '') {
            $function['description'] = $this->description;
        }

        if ($required !== []) {
            $function['parameters']['required'] = array_values(array_unique($required));
        }

        return [
            'type'     => $this->type,
            'function' => $function,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
