<?php

namespace Perk11\Viktor89\VoiceGeneration;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Log\LoggerInterface;

/**
 * Implements the `/song` command: takes a theme/idea, has an LLM write song
 * lyrics and pick fitting genre tags for it, then hands the tags + lyrics
 * to SingProcessor to render and send the song exactly like `/sing`.
 */
class SongProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SingProcessor $singProcessor,
        private readonly ContextCompletingAssistantInterface $lyricsAssistant,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $theme = trim($message->messageText);
        if ($theme === '' && $messageChain->count() > 1) {
            $theme = trim($messageChain->previous()->messageText);
        }
        if ($theme === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    'Напишите после команды тему или идею для песни, например /song летняя ночь на пляже.',
                ),
                true,
            );
        }

        $progressUpdateCallback(
            static::class,
            "Writing lyrics and picking a genre for: $theme",
            new ChatAction($message->chatId, ChatActionEnum::record_voice),
        );

        $formatted = trim($this->generateLyricsAndTags($theme));
        $this->logger->info('Generated song: ' . $formatted);
        $lines = explode("\n", $formatted);
        // The model occasionally prefixes its output with blank lines.
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        if (count($lines) < 2) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Не удалось сгенерировать текст песни, попробуйте ещё раз.'),
                true,
                '🤔',
                $message,
            );
        }
        $tags = trim($lines[0]);
        $lyrics = trim(implode("\n", array_slice($lines, 1)));

        return $this->singProcessor->generateSong($message, $tags, $lyrics, $progressUpdateCallback);
    }

    private function generateLyricsAndTags(string $theme): string
    {
        $context = new AssistantContext();
        $context->systemPrompt = <<<PROMPT
You are a songwriter and music producer. Given a theme, idea, or description, write original song lyrics and choose musical genres that fit it.

Respond in EXACTLY this format and nothing else:
- The FIRST line must be a comma-separated list of genre/style tags in English (e.g. "synthpop, upbeat, female vocals, electronic").
- Every following line is the lyrics, structured with section markers in square brackets: [Verse 1], [Chorus], [Verse 2], [Bridge], [Outro], etc.
- Write the lyrics in the same language as the user's message.
- Do NOT output any explanations, introductions, titles, or markdown/code formatting. Output only the tags line followed by the lyrics.
PROMPT;
        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;
        $userMessage->text = $theme;
        $context->messages[] = $userMessage;

        return $this->lyricsAssistant->getCompletionBasedOnContext($context)->content;
    }
}
