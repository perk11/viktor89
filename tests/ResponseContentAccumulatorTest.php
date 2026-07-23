<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\ResponseContentAccumulator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseContentAccumulator::class)]
class ResponseContentAccumulatorTest extends TestCase
{
    /**
     * The core invariant of the fix: the "Executing tool" notice is shown to the
     * user (display) but never reaches the model (clean), without any text
     * replacement — the two tracks are built in parallel from the start.
     */
    public function testDisplayOnlyContentReachesDisplayButNotClean(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('Here is your answer.');
        $acc->appendTelegramDisplayOnly("\n>Executing `search` with arguments `{}`\n\n");
        $acc->appendSeparatingByANewLine('Done.');

        $this->assertStringContainsString('Executing `search`', $acc->telegramDisplayedContent);
        $this->assertStringNotContainsString('Executing', $acc->llmVisibleContent);
        $this->assertSame("Here is your answer.\nDone.", $acc->llmVisibleContent);
    }

    /**
     * Interleaving must be preserved in the display track so the final message
     * edit does not move the notice relative to the streamed output.
     */
    public function testDisplayPreservesInterleavingOrder(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('Let me look that up.');
        $acc->appendTelegramDisplayOnly("\n>Executing `search` with arguments `{}`\n\n");
        $acc->appendSeparatingByANewLine('Here are the results.');

        $display = $acc->telegramDisplayedContent;
        $this->assertLessThan(
            mb_strpos($display, 'Here are the results.'),
            mb_strpos($display, 'Executing `search`'),
            'The notification must stay between the surrounding content chunks',
        );
    }

    public function testAppendReachesBothTracksVerbatim(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('text');
        $acc->append("\n\n<img src=\"x\">\n\n");

        $this->assertSame("text\n\n<img src=\"x\">\n\n", $acc->llmVisibleContent);
        $this->assertSame("text\n\n<img src=\"x\">\n\n", $acc->telegramDisplayedContent);
    }

    public function testAppendContentSeparatesChunksWithNewline(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('first');
        $acc->appendSeparatingByANewLine('second');

        $this->assertSame("first\nsecond", $acc->llmVisibleContent);
    }

    public function testEmptyContentIsIgnored(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('');
        $acc->appendSeparatingByANewLine('only');

        $this->assertSame('only', $acc->llmVisibleContent);
        $this->assertSame('only', $acc->telegramDisplayedContent);
    }

    /**
     * When the model emitted only a tool call (no text) before a notification,
     * the clean track must not start with a stray newline.
     */
    public function testCleanHasNoLeadingNewlineWhenOnlyNotificationPreceded(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendTelegramDisplayOnly("\n>Executing `gen` with arguments `{}`\n\n");
        $acc->appendSeparatingByANewLine('Result text');

        $this->assertSame('Result text', $acc->llmVisibleContent);
        $this->assertStringContainsString('Executing `gen`', $acc->telegramDisplayedContent);
    }

    public function testStartsEmpty(): void
    {
        $acc = new ResponseContentAccumulator();

        $this->assertSame('', $acc->llmVisibleContent);
        $this->assertSame('', $acc->telegramDisplayedContent);
    }
}
