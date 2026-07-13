<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Repository\MessageRepository;

/**
 * `/compliment` — the opposite of `/roast`. Reply to a message to compliment
 * its author (or yourself when used without a reply). Analyzes the target's
 * last 500 messages and has an LLM write a warm, specific tribute. No heat
 * setting.
 */
class ComplimentProcessor extends AbstractUserHistoryBasedResponder implements GetTriggeringCommandsInterface
{
    private const MESSAGE_LIMIT = 500;

    public function __construct(MessageRepository $messageRepository, AssistantInterface $assistant)
    {
        parent::__construct($messageRepository, $assistant);
    }

    public function getTriggeringCommands(): array
    {
        return ['/compliment'];
    }

    protected function getMessageLimit(): int
    {
        return self::MESSAGE_LIMIT;
    }

    protected function getSystemPrompt(string $displayName, string $arguments): string
    {
        return <<<PROMPT
You are a warm, observant and sincere friend.
Read the messages "{$displayName}" wrote and write a short, heartfelt compliment about them.
Base it ONLY on what actually comes through in their messages — their personality, sense of humor, passions, kindness, intelligence, creativity, the way they treat others, or the interests that shine through.
Be specific and genuine, never generic or saccharine, and never invent facts that are not supported by the messages.
Write in the same language "{$displayName}" mostly uses in their messages.
Keep it to roughly 3-6 sentences. No markdown headings, no code blocks, no lists, no preface — output only the compliment itself.
PROMPT;
    }

    protected function getProgressMessage(string $displayName): string
    {
        return "💛 Готовлю комплимент для {$displayName}…";
    }

    protected function getNoMessagesMessage(string $displayName): string
    {
        return "🤷 Не нашёл сообщений от {$displayName} — не из чего собирать комплимент.";
    }

    protected function getFailureMessage(string $displayName): string
    {
        return "🤔 Не получилось сочинить комплимент для {$displayName}, попробуйте ещё раз.";
    }

    protected function renderResponse(string $completion, string $displayName, int $includedCount, string $arguments): string
    {
        $suffix = $includedCount > 0 ? " (на основе {$includedCount} сообщений)" : '';

        return "💛 {$displayName}{$suffix}:\n\n{$completion}";
    }
}
