<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\Util\TelegramRichMarkdown;

/**
 * Accumulates an assistant response in two parallel tracks:
 *
 *  - clean:   exactly what the model produced (plus any content that must be
 *             replayed to it on later turns). This is what is persisted and fed
 *             back to the LLM.
 *  - display: the clean track plus display-only segments such as the
 *             "Executing tool" progress notice. This is what is shown in
 *             Telegram.
 *
 * Keeping the tracks separate from the moment content arrives means the
 * display-only notices never have to be stripped out of the stored text — they
 * are simply never added to the clean track.
 */
final class ResponseContentAccumulator
{
    public string $llmVisibleContent = '' {
        get {
            return $this->llmVisibleContent;
        }
    }

    public string $telegramDisplayedContent = '' {
        get {
            return $this->telegramDisplayedContent;
        }
    }

    /**
     * Append a chunk of the model's own output (or other content that must reach
     * both the user and the LLM) to both tracks, separating it from preceding
     * content with a newline. Model output may hallucinate image markup, which
     * is stripped from the display track here; the clean track keeps the
     * model's text verbatim for the LLM and the database.
     */
    public function appendSeparatingByANewLine(string $content): void
    {
        if ($content === '') {
            return;
        }
        if ($this->llmVisibleContent !== '') {
            $this->llmVisibleContent .= "\n";
        }
        if ($this->telegramDisplayedContent !== '') {
            $this->telegramDisplayedContent .= "\n";
        }
        $this->llmVisibleContent .= $content;
        $this->telegramDisplayedContent .= TelegramRichMarkdown::removeImages($content);
    }

    /**
     * Append tool-call output produced outside the model (the
     * automatic_output_markdown field of a tool result) verbatim to both
     * tracks: it is the only legitimate source of inline images, so unlike
     * model content it is not sanitized for display.
     */
    public function appendAutomaticOutput(string $content): void
    {
        $this->llmVisibleContent .= $content;
        $this->telegramDisplayedContent .= $content;
    }

    /**
     * Append content shown to the Telegram user only (e.g. the "Executing tool"
     * progress notice). It never reaches the LLM: the model already receives the
     * tool call through the structured tool_calls / tool-result messages.
     */
    public function appendTelegramDisplayOnly(string $content): void
    {
        $this->telegramDisplayedContent .= $content;
    }
}
