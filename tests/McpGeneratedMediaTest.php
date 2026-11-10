<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\McpGeneratedMedia;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpGeneratedMedia::class)]
class McpGeneratedMediaTest extends TestCase
{
    public function testToolMetaRoundTripsToARunningStatusWithTheModel(): void
    {
        $media = McpGeneratedMedia::fromToolMeta(McpGeneratedMedia::toToolMeta('xtts_v2', 'audio'));

        $this->assertNotNull($media);
        $this->assertSame('[xtts_v2] Generating audio', $media->runningStatus());
        $this->assertSame(ChatActionEnum::record_voice, $media->chatAction());
    }

    public function testChatActionMatchesTheMedia(): void
    {
        $this->assertSame(ChatActionEnum::upload_photo, McpGeneratedMedia::fromToolMeta(McpGeneratedMedia::toToolMeta('m', 'image'))?->chatAction());
        $this->assertSame(ChatActionEnum::record_video, McpGeneratedMedia::fromToolMeta(McpGeneratedMedia::toToolMeta('m', 'video'))?->chatAction());
        $this->assertNull(McpGeneratedMedia::fromToolMeta(McpGeneratedMedia::toToolMeta('m', '3d'))?->chatAction());
    }

    public function testToolsOfOtherServersHaveNoGeneratedMedia(): void
    {
        $this->assertNull(McpGeneratedMedia::fromToolMeta(null));
        $this->assertNull(McpGeneratedMedia::fromToolMeta(['other/key' => 'x']));
        $this->assertNull(McpGeneratedMedia::fromToolMeta(['viktor89/model' => 'xtts_v2']));
    }
}
