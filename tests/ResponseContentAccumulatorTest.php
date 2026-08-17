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

    /**
     * The display track is what Telegram sees, so model output appended via
     * appendSeparatingByANewLine() has hallucinated image markup stripped
     * there, while the clean track keeps the model's text verbatim for the
     * LLM and the database.
     */
    public function testModelContentWithImagesIsSanitizedForDisplayButKeptForLlm(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('Look ![fake](https://evil.example.com/x.png "a (b)")');

        $this->assertSame('Look ![fake](https://evil.example.com/x.png "a (b)")', $acc->llmVisibleContent);
        $this->assertSame('Look `<invalid image: fake>`', $acc->telegramDisplayedContent);
    }

    public function testModelContentWithoutImagesPassesThroughUnchangedForDisplay(): void
    {
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('Plain **markdown** and `code`.');

        $this->assertSame('Plain **markdown** and `code`.', $acc->telegramDisplayedContent);
    }

    public function testAutomaticOutputImagesAreKeptVerbatimInBothTracks(): void
    {
        // automatic_output_markdown is the only legitimate source of inline
        // images, so it must never be sanitized.
        $image = '![](https://example.com/generated-images/x.png "a cat (really)")';
        $acc = new ResponseContentAccumulator();
        $acc->appendAutomaticOutput("\n\n$image\n\n");

        $this->assertSame("\n\n$image\n\n", $acc->llmVisibleContent);
        $this->assertSame("\n\n$image\n\n", $acc->telegramDisplayedContent);
    }

    public function testAutomaticOutputIsNeverSanitizedEvenNextToModelImages(): void
    {
        $image = '![](https://example.com/generated-images/1.png)';
        $acc = new ResponseContentAccumulator();
        $acc->appendSeparatingByANewLine('model ![fake](https://evil.example.com/x.png)');
        $acc->appendAutomaticOutput("\n\n$image\n\n");

        $this->assertSame("model `<invalid image: fake>`\n\n$image\n\n", $acc->telegramDisplayedContent);
        $this->assertSame("model ![fake](https://evil.example.com/x.png)\n\n$image\n\n", $acc->llmVisibleContent);
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
