<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\StreamingTurnPresenter;
use PHPUnit\Framework\TestCase;

class StreamingTurnPresenterTest extends TestCase
{
    private string $streamed = '';

    public function testPrivateChatStreamsThinkingLiveAndClosesTheTagBeforeText(): void
    {
        $presenter = $this->presenter(isPrivateChat: true);

        $presenter->thinking('Let me ');
        $presenter->thinking('think');
        $this->assertSame('<tg-thinking>Let me think', $this->streamed);
        $presenter->text('Hello');
        $presenter->endThinking();

        $this->assertSame("<tg-thinking>Let me think\n</tg-thinking>Hello", $this->streamed);
        $this->assertSame('Let me think', $presenter->reasoning);
        $this->assertSame('Hello', $presenter->text);
    }

    public function testGroupChatBuffersThinkingUntilItEnds(): void
    {
        $presenter = $this->presenter(isPrivateChat: false);

        $presenter->thinking('Plan');
        $this->assertSame('', $this->streamed);
        $presenter->endThinking();
        $presenter->endThinking();

        $this->assertSame("<details>\n<summary>Thinking</summary>\nPlan\n</details>\n", $this->streamed);
    }

    public function testRepeatingTextIsReportedAndGetsTheAbortNotice(): void
    {
        $presenter = $this->presenter(isPrivateChat: true);

        $repeated = false;
        for ($i = 0; $i < 1000 && !$repeated; $i++) {
            $repeated = $presenter->text('all work and no play. ');
        }

        $this->assertTrue($repeated);
        $this->assertStringEndsWith('(Response was aborted due to repetition)', $presenter->text);
    }

    public function testFormatReasoningForDisplay(): void
    {
        $this->assertSame("<tg-thinking>Plan\n</tg-thinking>", StreamingTurnPresenter::formatReasoningForDisplay('Plan', true));
        $this->assertSame("<details>\n<summary>Thinking</summary>\nPlan\n</details>\n", StreamingTurnPresenter::formatReasoningForDisplay(' Plan ', false));
        $this->assertSame('', StreamingTurnPresenter::formatReasoningForDisplay('  ', false));
    }

    private function presenter(bool $isPrivateChat): StreamingTurnPresenter
    {
        $this->streamed = '';

        return new StreamingTurnPresenter(
            $isPrivateChat,
            function (string $chunk): void {
                $this->streamed .= $chunk;
            },
            null,
            'test',
        );
    }
}
