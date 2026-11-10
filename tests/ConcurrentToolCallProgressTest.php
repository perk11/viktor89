<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\Tool\ConcurrentToolCallProgress;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunner;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\Test\Support\RecordingProgressUpdateCallback;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/AssistantTestFixtures.php';

#[CoversClass(ConcurrentToolCallProgress::class)]
class ConcurrentToolCallProgressTest extends TestCase
{
    public function testEveryCallIsListedInOneStatusWithItsLatestDetail(): void
    {
        $callback = new RecordingProgressUpdateCallback();
        $progress = new ConcurrentToolCallProgress($callback, [
            new ToolCall('1', 'image_gen_tool', '{}'),
            new ToolCall('2', 'web_search', '{}'),
            new ToolCall('3', 'get_url_contents', '{}'),
        ]);

        $progress->callbackFor(0)(ToolCallRunner::class, 'Executing image_gen_tool');
        $progress->callbackFor(2)(AltTextProvider::class, 'Describing image', new ChatAction(1, ChatActionEnum::typing));
        $progress->finish(1);
        $progress->finish(0);
        $progress->finish(2);

        $this->assertSame([
            [ToolCallRunner::class, 'Executing 3 tool calls: image_gen_tool; web_search; get_url_contents'],
            [ToolCallRunner::class, 'Executing 3 tool calls: image_gen_tool; web_search; get_url_contents: Describing image'],
            [ToolCallRunner::class, 'Executing 3 tool calls: image_gen_tool; ✓ web_search; get_url_contents: Describing image'],
            [ToolCallRunner::class, 'Executing 3 tool calls: ✓ image_gen_tool; ✓ web_search; get_url_contents: Describing image'],
        ], $callback->updates);
    }

    public function testStatusesNamingTheModelAreShownForEachCall(): void
    {
        $callback = new RecordingProgressUpdateCallback();
        $progress = new ConcurrentToolCallProgress($callback, [
            new ToolCall('1', 'generate_voice_xtts', '{}'),
            new ToolCall('2', 'image_gen_tool', '{}'),
        ]);

        $progress->callbackFor(0)(ToolCallRunner::class, '[xtts_v2] Generating audio');
        $progress->callbackFor(1)(ToolCallRunner::class, 'Executing image_gen_tool');
        $progress->callbackFor(1)(Automatic1111APiClient::class, '[flux] Generating image');

        $this->assertSame(
            'Executing 2 tool calls: generate_voice_xtts: [xtts_v2] Generating audio; image_gen_tool: [flux] Generating image',
            end($callback->updates)[1],
        );
    }
}
