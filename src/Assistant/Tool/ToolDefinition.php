<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use InvalidArgumentException;
use JsonSerializable;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;

final class ToolDefinition implements JsonSerializable
{
    /**
     * @param list<ToolParameter> $parameters
     * @param bool $silent If true, no "Executing tool" notification is sent to the user
     * @param ?string $runningStatus progress status shown while the tool runs, instead of "Executing <name>"
     * @param ?ChatActionEnum $runningChatAction Telegram chat action shown while the tool runs
     */
    public function __construct(
        private(set) readonly string $name,
        private(set) readonly ToolCallExecutorInterface|MessageChainAwareToolCallExecutorInterface|ProgressReportingToolCallExecutorInterface $toolCallClass,
        private(set) readonly ?string $description,
        private(set) readonly array $parameters = [],
        private(set) readonly string $type = 'function',
        private(set) readonly bool $silent = false,
        private(set) readonly ?string $runningStatus = null,
        private(set) readonly ?ChatActionEnum $runningChatAction = null,
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
                'properties' => $properties === [] ? new \stdClass() : $properties,
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
