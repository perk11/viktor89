<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\VoiceGeneration\MessageAudio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageAudio::class)]
class MessageAudioTest extends TestCase
{
    public function testConstructorStoresFields(): void
    {
        $audio = new MessageAudio('file_123', 'voice.ogg', 'audio/ogg');
        $this->assertSame('file_123', $audio->fileId);
        $this->assertSame('voice.ogg', $audio->fileName);
        $this->assertSame('audio/ogg', $audio->type);
    }

    public function testPropertiesAreReadonly(): void
    {
        $audio = new MessageAudio('f', 'n', 't');
        $this->expectException(\Error::class);
        $audio->fileId = 'changed';
    }

    public function testWithOgaType(): void
    {
        $audio = new MessageAudio('f1', 'audio.oga', 'audio/ogg');
        $this->assertSame('audio/ogg', $audio->type);
    }

    public function testWithMp3Type(): void
    {
        $audio = new MessageAudio('f2', 'voice.mp3', 'audio/mpeg');
        $this->assertSame('audio/mpeg', $audio->type);
    }
}
