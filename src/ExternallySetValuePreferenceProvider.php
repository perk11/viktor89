<?php

namespace Perk11\Viktor89;

class ExternallySetValuePreferenceProvider implements UserPreferenceReaderInterface
{
    public ?string $value;
    public function getCurrentPreferenceValue(int $userId): ?string
    {
        return $this->value;
    }
}
