<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Perk11\Viktor89\Database;

class ListBasedPreferenceByCommandProcessor extends UserPreferenceSetByCommandProcessor
{
    public function __construct(
        Database $database,
        array $triggeringCommands,
        string $preferenceName,
        private readonly array $acceptedValuesList,
    ) {
        parent::__construct($database, $triggeringCommands, $preferenceName);
    }


    protected function getValueValidationErrors(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!in_array($value, $this->acceptedValuesList, true)) {
            return ["Эта настройка принимает следующие значения:\n\n" . implode("\n\n", $this->acceptedValuesList)];
        }

        return [];
    }
}
