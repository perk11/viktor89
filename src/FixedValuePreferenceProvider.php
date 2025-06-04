<?php

namespace Perk11\Viktor89;

class FixedValuePreferenceProvider implements UserPreferenceReaderInterface
{
    public function __construct(public ?string $value)
    {
    }

    public function getCurrentPreferenceValue(int $userId): ?string
    {
        return $this->value;
    }
}
