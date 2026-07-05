<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatAction::class)]
class ChatActionTest extends TestCase
{
    public function testConstructorStoresChatIdAndAction(): void
    {
        $action = new ChatAction(-100123, ChatActionEnum::typing);

        $this->assertSame(-100123, $action->chatId);
        $this->assertSame(ChatActionEnum::typing, $action->action);
    }

    public function testConstructorWithUploadPhoto(): void
    {
        $action = new ChatAction(-100456, ChatActionEnum::upload_photo);

        $this->assertSame(ChatActionEnum::upload_photo, $action->action);
    }

    public function testConstructorWithPositiveChatId(): void
    {
        $action = new ChatAction(12345678, ChatActionEnum::record_voice);

        $this->assertSame(12345678, $action->chatId);
        $this->assertSame(ChatActionEnum::record_voice, $action->action);
    }

    public function testChatIdCanBeMutated(): void
    {
        $action = new ChatAction(-100123, ChatActionEnum::typing);
        $action->chatId = -100789;

        $this->assertSame(-100789, $action->chatId);
    }

    public function testActionCanBeMutated(): void
    {
        $action = new ChatAction(-100123, ChatActionEnum::typing);
        $action->action = ChatActionEnum::upload_video;

        $this->assertSame(ChatActionEnum::upload_video, $action->action);
    }
}
