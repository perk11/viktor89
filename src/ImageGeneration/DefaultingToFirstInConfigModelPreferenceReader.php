<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\UserPreferenceReaderInterface;

class DefaultingToFirstInConfigModelPreferenceReader implements UserPreferenceReaderInterface
{
    public function __construct(private readonly UserPreferenceReaderInterface $originalPreference, private readonly array $modelConfig)
    {
    }

    public function getCurrentPreferenceValue(int $userId): ?string
    {
        $modelName = $this->originalPreference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            return key($this->modelConfig);
        }

        return $modelName;
    }
}
