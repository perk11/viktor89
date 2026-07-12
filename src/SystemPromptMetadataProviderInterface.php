<?php

namespace Perk11\Viktor89;

/**
 * Implemented by system-prompt readers that can provide the metadata relevant
 * parts of the prompt separately: the base user-configured system prompt
 * (without the persona suffix) and the active persona id. Wrapper decorators
 * (e.g. PrependingSystemPromptProcessor) delegate to their inner reader so the
 * assistant can reach through the chain.
 */
interface SystemPromptMetadataProviderInterface
{
    public function getBaseSystemPrompt(int $userId): ?string;

    public function getActivePersonaId(int $userId): ?int;
}
