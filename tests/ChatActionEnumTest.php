<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatActionEnum::class)]
class ChatActionEnumTest extends TestCase
{
    public function testTypingCaseExists(): void
    {
        $this->assertSame('typing', ChatActionEnum::typing->name);
    }

    public function testUploadPhotoCaseExists(): void
    {
        $this->assertSame('upload_photo', ChatActionEnum::upload_photo->name);
    }

    public function testRecordVideoCaseExists(): void
    {
        $this->assertSame('record_video', ChatActionEnum::record_video->name);
    }

    public function testUploadVideoCaseExists(): void
    {
        $this->assertSame('upload_video', ChatActionEnum::upload_video->name);
    }

    public function testRecordVoiceCaseExists(): void
    {
        $this->assertSame('record_voice', ChatActionEnum::record_voice->name);
    }

    public function testUploadVoiceCaseExists(): void
    {
        $this->assertSame('upload_voice', ChatActionEnum::upload_voice->name);
    }

    public function testUploadDocumentCaseExists(): void
    {
        $this->assertSame('upload_document', ChatActionEnum::upload_document->name);
    }

    public function testChooseStickerCaseExists(): void
    {
        $this->assertSame('choose_sticker', ChatActionEnum::choose_sticker->name);
    }

    public function testFindLocationCaseExists(): void
    {
        $this->assertSame('find_location', ChatActionEnum::find_location->name);
    }

    public function testRecordVideoNoteCaseExists(): void
    {
        $this->assertSame('record_video_note', ChatActionEnum::record_video_note->name);
    }

    public function testUploadVideoNoteCaseExists(): void
    {
        $this->assertSame('upload_video_note', ChatActionEnum::upload_video_note->name);
    }

    public function testEnumHasCorrectCaseCount(): void
    {
        $this->assertCount(11, ChatActionEnum::cases());
    }
}
