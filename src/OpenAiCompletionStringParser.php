<?php

namespace Perk11\Viktor89;

class OpenAiCompletionStringParser
{
    public function parse(string $completionString): ?array
    {
        if (!str_starts_with($completionString, 'data: ')) {
            throw new \Exception("Unexpected completion string: $completionString");
        }

        $stringWithoutDataPrefix = substr($completionString, strlen('data: '));
        if (str_starts_with($stringWithoutDataPrefix, '[DONE]')) {
            return null;
        }
        return json_decode(
            $stringWithoutDataPrefix,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
