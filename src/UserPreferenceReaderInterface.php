<?php

namespace Perk11\Viktor89;

interface UserPreferenceReaderInterface
{
    public function getCurrentPreferenceValue(int $userId): ?string;
}
