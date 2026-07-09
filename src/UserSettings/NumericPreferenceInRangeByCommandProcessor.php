<?php

namespace Perk11\Viktor89\UserSettings;

use Perk11\Viktor89\Repository\UserPreferenceRepository;

class NumericPreferenceInRangeByCommandProcessor extends UserPreferenceSetByCommandProcessor
{
    public function __construct(
        UserPreferenceRepository $userPreferenceRepository,
        array $triggeringCommands,
        string $preferenceName,
        string $botUserName,
        private readonly float $minValue,
        private readonly float $maxValue,
    ) {
        parent::__construct($userPreferenceRepository, $triggeringCommands, $preferenceName, $botUserName);
    }

    private function getExpectedValueHelpMessage(): string
    {
        return "Эта настройка принимает числа: " . $this->minValue . '-' . $this->maxValue;
    }

    protected function getValueValidationErrors(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_numeric($value)) {
            return [
                "\"$value\" - не число. " . $this->getExpectedValueHelpMessage(),
            ];
        }

        if ($value < $this->minValue) {
            return [
                "Значение \"$value\" слишком маленькое. " . $this->getExpectedValueHelpMessage(),
            ];
        }

        if ($value > $this->maxValue) {
            return [
                "Значение \"$value\" слишком большое. " . $this->getExpectedValueHelpMessage(),
            ];
        }

        return [];
    }
}
