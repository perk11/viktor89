<?php

namespace Perk11\Viktor89;

class PersonaAwareSystemPromptReader implements UserPreferenceReaderInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly UserPreferenceReaderInterface $systemPromptProcessor,
    ) {
    }

    public function getCurrentPreferenceValue(int $userId): ?string
    {
        $personaPrompt = $this->getActivePersonaPrompt($userId);
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId);

        if ($personaPrompt === '') {
            return $systemPrompt === null || $systemPrompt === '' ? null : $systemPrompt;
        }
        $personaPrompt = 'The user has required you to be the following persona: ' . $personaPrompt;
        if ($systemPrompt === null || $systemPrompt === '') {
            return $personaPrompt;
        }

        return $systemPrompt ."\n$personaPrompt";
    }

    private function getActivePersonaPrompt(int $userId): string
    {
        $personaName = $this->database->readUserPreference($userId, PersonaHelper::PERSONA_PREFERENCE);
        if ($personaName === null || $personaName === '') {
            return '';
        }
        if (mb_strtolower($personaName) === mb_strtolower(PersonaHelper::DEFAULT_PERSONA_NAME)) {
            return '';
        }

        return $this->database->findPersonaByName($personaName)?->systemPrompt ?? '';
    }
}
