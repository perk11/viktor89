<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\UserPreferenceReaderInterface;

class PrependingSystemPromptProcessor implements UserPreferenceReaderInterface
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
}
