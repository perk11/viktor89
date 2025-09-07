<?php

namespace Perk11\Viktor89\Assistant;

class VibeVoiceDialogGeneratingAssistant extends OpenAiChatAssistant
{
    protected function getResponseParameters(AssistantContext $assistantContext): array
    {
        $parameters = parent::getResponseParameters($assistantContext);

        $parameters['grammar'] = <<<'GBNF'
root        ::= line (line)*
line        ::= "[" speaker "]:" " " speech "\n"
speaker     ::= "1" | "2"
speech      ::= nonl+
nonl        ::= [^\n]
GBNF;
        $parameters['max_tokens'] = 2048;

        return $parameters;
    }
}
