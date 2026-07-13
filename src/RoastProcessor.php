<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Repository\MessageRepository;

/**
 * `/roast [mild|medium|savage]` — reply to a message to roast its author (or
 * yourself when used without a reply). Reads the target's recent messages and
 * has an LLM deliver a short roast tuned to the requested heat level.
 */
class RoastProcessor extends AbstractUserHistoryBasedResponder implements GetTriggeringCommandsInterface
{
    private const DEFAULT_MESSAGE_LIMIT = 100;

    /** @var array<string, string> canonical level => user-visible label */
    private const HEAT_LABELS = [
        'mild'   => 'mild 🌶️',
        'medium' => 'medium 🔥',
        'savage' => 'savage 💀',
    ];

    /** @var array<string, string> accepted alias => canonical level */
    private const HEAT_ALIASES = [
        'mild'    => 'mild',
        'soft'    => 'mild',
        'gentle'  => 'mild',
        'lite'    => 'mild',
        'light'   => 'mild',
        'medium'  => 'medium',
        'med'     => 'medium',
        'normal'  => 'medium',
        'default' => 'medium',
        'savage'  => 'savage',
        'brutal'  => 'savage',
        'spicy'   => 'savage',
        'hot'     => 'savage',
        'max'     => 'savage',
        'merciless' => 'savage',
    ];

    public function __construct(MessageRepository $messageRepository, AssistantInterface $assistant)
    {
        parent::__construct($messageRepository, $assistant);
    }

    public function getTriggeringCommands(): array
    {
        return ['/roast'];
    }

    protected function getMessageLimit(): int
    {
        return self::DEFAULT_MESSAGE_LIMIT;
    }

    protected function getSystemPrompt(string $displayName, string $arguments): string
    {
        $level = $this->parseHeatLevel($arguments);
        $intensity = match ($level) {
            'mild'   => 'gentle, playful and good-natured. Tease them warmly and affectionately — no genuinely mean, hurtful or offensive material. Keep it lighthearted and friendly.',
            'savage' => 'brutal, biting and relentless. Pull absolutely no punches and be as harsh as a roast can be — relentless and savage — but stay within comedic roast territory: no slurs, no real threats, nothing genuinely hateful.',
            default  => 'funny and clever with some bite. Punchy and sharp but still comedic rather than cruel.',
        };

        return <<<PROMPT
You are a sharp-witted stand-up comedian performing a roast.
Write a short, creative roast of "{$displayName}" based ONLY on the messages they wrote.
Reference their specific interests, recurring topics, opinions, catchphrases and writing style.
Tone: {$intensity}
Write in the same language "{$displayName}" mostly uses in their messages.
Keep it to roughly 3-6 sentences. No markdown headings, no code blocks, no lists, no preface — output only the roast itself.
PROMPT;
    }

    protected function getProgressMessage(string $displayName): string
    {
        return "🔥 Готовлю рост для {$displayName}…";
    }

    protected function getNoMessagesMessage(string $displayName): string
    {
        return "🤷 Не нашёл сообщений от {$displayName} — не из чего готовить рост.";
    }

    protected function getFailureMessage(string $displayName): string
    {
        return "🤔 Не получилось сочинить рост для {$displayName}, попробуйте ещё раз.";
    }

    protected function renderResponse(string $completion, string $displayName, int $includedCount, string $arguments): string
    {
        $heat = self::HEAT_LABELS[$this->parseHeatLevel($arguments)];

        return "🔥 Рост для {$displayName} ({$heat}):\n\n{$completion}";
    }

    private function parseHeatLevel(string $arguments): string
    {
        $arguments = strtolower(trim($arguments));
        // Tolerate "/roast savage extra words" by reading only the first token.
        $firstToken = explode(' ', $arguments)[0];

        return self::HEAT_ALIASES[$firstToken] ?? 'medium';
    }
}
