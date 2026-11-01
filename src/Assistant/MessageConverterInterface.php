<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

/** Converts an AssistantContext into the conversation format of one chat API. */
interface MessageConverterInterface extends ImagePartBuilderInterface
{
    /**
     * @param bool $includeToolCalls false for requests that define no tools; APIs reject tool calls in them
     * @return array<mixed> the API-specific conversation
     */
    public function convert(AssistantContext $context, bool $includeToolCalls): array;
}
