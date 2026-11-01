<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;

/**
 * Streams one model turn to Telegram and collects its text and reasoning.
 * Thinking is shown live in a <tg-thinking> block in private chats and as a
 * collapsed details block in group chats, where it is buffered until it ends.
 */
final class StreamingTurnPresenter
{
    private const int REPETITION_THRESHOLD_CHARACTERS = 2048;
    private const float PROGRESS_UPDATE_INTERVAL_SECONDS = 3;
    private const string REPETITION_ABORT_NOTICE = "\n\n(Response was aborted due to repetition)";

    public private(set) string $text = '';
    public private(set) string $reasoning = '';
    private string $thinkingBuffer = '';
    private bool $thinkingTagOpened = false;
    private float $lastProgressUpdateTime = 0;

    /**
     * @param int $precedingContentLength characters produced by earlier turns of the same completion
     */
    public function __construct(
        private readonly bool $isPrivateChat,
        private readonly ?\Closure $streamFunction,
        private readonly ?ProgressUpdateCallback $progressUpdateCallback,
        private readonly string $processorName,
        private readonly int $precedingContentLength = 0,
    ) {
    }

    public static function formatReasoningForDisplay(string $reasoning, bool $isPrivateChat): string
    {
        return $isPrivateChat
            ? '<tg-thinking>' . $reasoning . "\n</tg-thinking>"
            : self::formatThinkingAsDetailsBlock($reasoning);
    }

    public function thinking(string $chunk): void
    {
        $this->reasoning .= $chunk;
        if ($this->isPrivateChat) {
            if (!$this->thinkingTagOpened) {
                $this->stream('<tg-thinking>');
                $this->thinkingTagOpened = true;
            }
            $this->stream($chunk);
        } else {
            $this->thinkingBuffer .= $chunk;
        }
    }

    /**
     * @return bool true when the text started repeating itself; the caller
     *              must stop generating, the abort notice is already appended
     */
    public function text(string $chunk): bool
    {
        $this->endThinking();
        $this->text .= $chunk;
        $this->stream($chunk);
        $this->reportProgress();
        if (!self::isStringStartingToRepeat($this->text, self::REPETITION_THRESHOLD_CHARACTERS)) {
            return false;
        }
        $this->text .= self::REPETITION_ABORT_NOTICE;

        return true;
    }

    /** Call when a non-thinking block starts and when the turn ends. */
    public function endThinking(): void
    {
        if ($this->thinkingTagOpened) {
            $this->stream("\n</tg-thinking>");
            $this->thinkingTagOpened = false;
        }
        if ($this->thinkingBuffer !== '') {
            $this->stream(self::formatThinkingAsDetailsBlock($this->thinkingBuffer));
            $this->thinkingBuffer = '';
        }
    }

    private function stream(string $chunk): void
    {
        if ($this->streamFunction !== null) {
            ($this->streamFunction)($chunk);
        }
    }

    private function reportProgress(): void
    {
        $now = microtime(true);
        if ($this->progressUpdateCallback === null || $now - $this->lastProgressUpdateTime <= self::PROGRESS_UPDATE_INTERVAL_SECONDS) {
            return;
        }
        ($this->progressUpdateCallback)(
            $this->processorName,
            'Streaming response: (' . ($this->precedingContentLength + mb_strlen($this->text)) . ') characters',
        );
        $this->lastProgressUpdateTime = $now;
    }

    private static function formatThinkingAsDetailsBlock(string $thinking): string
    {
        $thinking = trim($thinking);
        if ($thinking === '') {
            return '';
        }

        return "<details>\n<summary>Thinking</summary>\n$thinking\n</details>\n";
    }

    private static function isStringStartingToRepeat(string $str, int $charactersToCheck): bool
    {
        if (mb_strlen($str) < $charactersToCheck) {
            return false;
        }
        $lastCharacters = mb_substr($str, -$charactersToCheck);
        $earlierPart = mb_substr($str, 0, -$charactersToCheck);

        return str_contains($earlierPart, $lastCharacters);
    }
}
