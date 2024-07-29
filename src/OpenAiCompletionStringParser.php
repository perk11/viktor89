<?php

namespace Perk11\Viktor89;

class OpenAiCompletionStringParser
{
    public function parse(string $completionString): array
    {
        if (!str_starts_with($completionString, 'data: ')) {
            throw new \Exception("Unexpected completion string: $completionString");
        }

        return json_decode(
            substr($completionString, strlen('data: ')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
