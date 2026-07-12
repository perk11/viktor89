<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\SystemPromptMetadataProviderInterface;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class PrependingSystemPromptProcessor implements UserPreferenceReaderInterface, SystemPromptMetadataProviderInterface
{
    public function __construct(
        private readonly UserPreferenceReaderInterface $userPreferenceReader,
        private readonly string $prependString
    )
    {

    }
    public function getCurrentPreferenceValue(int $userId): ?string
    {
        $userPrompt = $this->userPreferenceReader->getCurrentPreferenceValue($userId);
        if ($userPrompt === null) {
            return $this->prependString;
        }

        return $this->prependString . "\n" . $userPrompt;
    }

    public function getBaseSystemPrompt(int $userId): ?string
    {
        if ($this->userPreferenceReader instanceof SystemPromptMetadataProviderInterface) {
            $inner = $this->userPreferenceReader->getBaseSystemPrompt($userId);
        } else {
            $inner = $this->userPreferenceReader->getCurrentPreferenceValue($userId);
        }

        return $this->prependString . "\n" . $inner;
    }

    public function getActivePersonaId(int $userId): ?int
    {
        if ($this->userPreferenceReader instanceof SystemPromptMetadataProviderInterface) {
            return $this->userPreferenceReader->getActivePersonaId($userId);
        }

        return null;
    }
}
