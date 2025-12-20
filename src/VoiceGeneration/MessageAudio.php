<?php

namespace Perk11\Viktor89\VoiceGeneration;

readonly class MessageAudio
{
    public function __construct(public string $fileId, public string $fileName, public string $type)
    {

    }
}
