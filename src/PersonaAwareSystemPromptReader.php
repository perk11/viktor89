<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Repository\PersonaRepository;
use Perk11\Viktor89\Repository\UserPreferenceRepository;

class PersonaAwareSystemPromptReader implements UserPreferenceReaderInterface, SystemPromptMetadataProviderInterface
{
    public function __construct(
        private readonly UserPreferenceRepository $userPreferenceRepository,
        private readonly PersonaRepository $personaRepository,
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

    public function getActivePersonaId(int $userId): ?int
    {
        $personaName = $this->userPreferenceRepository->readUserPreference($userId, PersonaHelper::PERSONA_PREFERENCE);
        if ($personaName === null || $personaName === '') {
            return null;
        }
        if (mb_strtolower($personaName) === mb_strtolower(PersonaHelper::DEFAULT_PERSONA_NAME)) {
            return null;
        }

        return $this->personaRepository->findPersonaByName($personaName)?->id;
    }

    public function getBaseSystemPrompt(int $userId): ?string
    {
        return $this->systemPromptProcessor->getCurrentPreferenceValue($userId);
    }

    private function getActivePersonaPrompt(int $userId): string
    {
        $personaName = $this->userPreferenceRepository->readUserPreference($userId, PersonaHelper::PERSONA_PREFERENCE);
        if ($personaName === null || $personaName === '') {
            return '';
        }
        if (mb_strtolower($personaName) === mb_strtolower(PersonaHelper::DEFAULT_PERSONA_NAME)) {
            return '';
        }

        return $this->personaRepository->findPersonaByName($personaName)?->systemPrompt ?? '';
    }
}
