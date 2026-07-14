<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\TelegramHtml;

class VibeCheckProcessor implements MessageChainProcessor
{
    private const DEFAULT_MESSAGE_COUNT = 50;
    private const MAX_MESSAGE_COUNT = 200;
    private const MIN_MESSAGE_COUNT = 5;
    private const PER_MESSAGE_TEXT_LIMIT = 200;
    private const BAR_WIDTH = 10;
    private const ENERGY_BAR_WIDTH = 10;

    /** @var string[] */
    private const AXES = ['chaos', 'wholesome', 'brainrot', 'thirst', 'drama'];

    /** @var array<string, string> */
    private const AXIS_LABELS = [
        'chaos'     => 'Chaos',
        'wholesome' => 'Wholesome',
        'brainrot'  => 'Brainrot',
        'thirst'    => 'Thirst',
        'drama'     => 'Drama',
    ];

    /** @var array<string, string> */
    private const TIER_EMOJI = [
        'S' => '🏆',
        'A' => '🥇',
        'B' => '🥈',
        'C' => '🥉',
        'D' => '😬',
        'F' => '💀',
    ];

    /** @var array<string, int> energy floor (inclusive) → tier */
    private const TIER_ENERGY_FLOORS = [
        'S' => 85,
        'A' => 70,
        'B' => 55,
        'C' => 40,
        'D' => 25,
        'F' => 0,
    ];

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly AssistantInterface $assistant,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $triggeringMessage = $messageChain->last();
        $chatId = $triggeringMessage->chatId;
        $messageCount = $this->parseMessageCount($triggeringMessage->messageText);

        $recentMessages = $this->messageRepository->findNPreviousMessagesInChat(
            $chatId,
            $triggeringMessage->id,
            $messageCount,
            [],
        );
        // findNPreviousMessagesInChat returns newest-first; chronological is clearer for the model.
        $recentMessages = array_reverse($recentMessages);

        $transcript = $this->buildTranscript($recentMessages);

        $responseMessage = InternalMessage::asResponseTo($triggeringMessage);
        $responseMessage->parseMode = 'HTML';

        if ($transcript === '') {
            $responseMessage->messageText = '🤷 В чате пока слишком тихо — не из чего считать вайб. Напишите что-нибудь и попробуйте снова.';
            return new ProcessingResult($responseMessage, true);
        }

        $progressUpdateCallback(static::class, 'Замеряю вайб чата…');

        try {
            $completion = $this->assistant->getCompletionBasedOnContext($this->buildContext($transcript))->content;
        } catch (\Exception $e) {
            echo "VibeCheck: assistant completion failed: " . $e->getMessage() . "\n";
            $responseMessage->messageText = '🤔 Не получилось оценить вайб, попробуйте ещё раз.';
            return new ProcessingResult($responseMessage, true);
        }

        $responseMessage->messageText = $this->renderReport($completion, count($recentMessages));

        return new ProcessingResult($responseMessage, true);
    }

    private function parseMessageCount(string $argument): int
    {
        $argument = trim($argument);
        if ($argument === '' || !ctype_digit($argument)) {
            return self::DEFAULT_MESSAGE_COUNT;
        }

        return max(self::MIN_MESSAGE_COUNT, min(self::MAX_MESSAGE_COUNT, (int) $argument));
    }

    /**
     * @param InternalMessage[] $recentMessages
     */
    private function buildTranscript(array $recentMessages): string
    {
        $lines = [];
        foreach ($recentMessages as $message) {
            $text = trim($message->messageText);
            if ($text === '') {
                continue;
            }
            $author = $message->userName !== '' ? $message->userName : 'User' . $message->userId;
            if (mb_strlen($text) > self::PER_MESSAGE_TEXT_LIMIT) {
                $text = mb_strimwidth($text, 0, self::PER_MESSAGE_TEXT_LIMIT, '…');
            }
            $lines[] = $author . ': ' . $text;
        }

        return implode("\n", $lines);
    }

    private function buildContext(string $transcript): AssistantContext
    {
        $context = new AssistantContext();
        $context->systemPrompt = <<<'PROMPT'
You are "VibeCheck", an analyst that reads a group chat's recent messages and grades its current vibe with flair and humor.

Respond with ONLY a minified JSON object on a single line. No markdown, no code fences, no commentary before or after.

Schema (emit every field except "haiku"):
{"energy":0,"tier":"A","title":"","emoji":"","soundtrack":"","forecast":"","haiku":"","scores":{"chaos":0,"wholesome":0,"brainrot":0,"thirst":0,"drama":0},"verdict":""}

Field rules:
- "energy": integer 0-100 — the overall liveliness / intensity of the chat right now.
- "tier": exactly one of "S","A","B","C","D","F" — how legendary the current vibe is. S = peak energy / all-timer chaos, F = dead air.
- "title": a short, witty, creative name for the vibe (2-6 words), like a mood playlist title. Be original and specific to this chat. No inner quotes.
- "emoji": 1 to 4 emojis that best capture the vibe. No spaces, no text, only emojis.
- "soundtrack": a real song, artist, or musical genre that would be this chat's score right now. Keep it short (a few words).
- "forecast": a single playful, horoscope-style sentence predicting where the chat is heading next.
- "haiku": OPTIONAL. A 3-line haiku (5-7-5 syllables) about this chat, lines joined by the two characters \n. Omit or leave empty if unsure.
- "scores": each axis is an integer 0-10:
    chaos = unhinged / noisy / unhinged-energy,
    wholesome = warm / friendly / supportive,
    brainrot = memes / slang / shitposting,
    thirst = simping / flirting / down-bad energy,
    drama = conflict / gossip / beef.
- "verdict": punchy, witty summary of the vibe. Max 2 short sentences. Attitude encouraged.

Write every free-text field (title, soundtrack, forecast, haiku, verdict) in the language the chat mostly uses.

Output raw, valid JSON on a single line. Nothing else.
PROMPT;

        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = "Recent chat messages (newest last):\n\n" . $transcript;
        $context->messages[] = $message;

        return $context;
    }

    private function renderReport(string $completion, int $analyzedCount): string
    {
        $report = $this->parseReport($completion);

        if ($report === null) {
            // Model didn't return parseable JSON; show its raw text as a fallback.
            return "🔮 <b>VIBE CHECK</b> <i>(на основе {$analyzedCount} сообщений)</i>\n\n"
                . TelegramHtml::escape(trim($completion));
        }

        $headerEmoji = $report['emoji'] !== '' ? $report['emoji'] : '🔮';

        $lines = [];
        $lines[] = "{$headerEmoji} <b>VIBE CHECK</b> <i>(на основе {$analyzedCount} сообщений)</i>";

        if ($report['title'] !== '') {
            $lines[] = '🏷 ' . TelegramHtml::escape($report['title']);
        }

        // Overall energy meter + vibe tier.
        $energy = $report['energy'];
        $filled = (int) round($energy / 10);
        $energyBar = str_repeat('█', $filled) . str_repeat('░', self::ENERGY_BAR_WIDTH - $filled);
        $tier = $report['tier'];
        $tierEmoji = self::TIER_EMOJI[$tier] ?? '📋';
        $lines[] = "⚡ Энергия: <code>{$energyBar}</code> {$energy}%";
        $lines[] = "{$tierEmoji} Тир: <b>{$tier}</b>";

        // Per-axis bars.
        $lines[] = '<pre>';
        foreach (self::AXES as $axis) {
            $label = str_pad(self::AXIS_LABELS[$axis], 10);
            $score = max(0, min(10, $report['scores'][$axis]));
            $bar = str_repeat('█', $score) . str_repeat('░', self::BAR_WIDTH - $score);
            $lines[] = $label . ' ' . $bar . ' ' . $score;
        }
        $lines[] = '</pre>';

        if ($report['soundtrack'] !== '') {
            $lines[] = '🎶 Саундтрек: ' . TelegramHtml::escape($report['soundtrack']);
        }
        if ($report['forecast'] !== '') {
            $lines[] = '🔮 Прогноз: ' . TelegramHtml::escape($report['forecast']);
        }

        $lines[] = '';
        $lines[] = '💬 ' . TelegramHtml::escape($report['verdict']);

        if ($report['haiku'] !== '') {
            $lines[] = '';
            $lines[] = '🎴 <blockquote>' . TelegramHtml::escape($report['haiku']) . '</blockquote>';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{
     *     energy: int,
     *     tier: string,
     *     title: string,
     *     emoji: string,
     *     soundtrack: string,
     *     forecast: string,
     *     haiku: string,
     *     scores: array<string, int>,
     *     verdict: string,
     * }|null
     */
    private function parseReport(string $completion): ?array
    {
        $completion = trim($completion);
        // Strip markdown code fences / surrounding prose if the model added them despite instructions.
        if (preg_match('/\{.*\}/s', $completion, $matches) === 1) {
            $completion = $matches[0];
        }

        try {
            $data = json_decode($completion, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        // Scores + verdict are required; everything else degrades gracefully.
        // Accept axes either nested under "scores" (preferred schema) or flat
        // at the top level, since smaller models sometimes flatten the object.
        $scoreSource = array_key_exists('scores', $data) && is_array($data['scores']) ? $data['scores'] : $data;
        $scores = [];
        foreach (self::AXES as $axis) {
            if (!array_key_exists($axis, $scoreSource) || !is_numeric($scoreSource[$axis])) {
                return null;
            }
            $scores[$axis] = (int) $scoreSource[$axis];
        }

        $verdict = is_string($data['verdict'] ?? null) ? trim($data['verdict']) : '';
        if ($verdict === '') {
            return null;
        }

        $energy = array_key_exists('energy', $data) && is_numeric($data['energy'])
            ? (int) $data['energy']
            : (int) round(array_sum($scores) / count($scores) * 10);
        $energy = max(0, min(100, $energy));

        $tier = is_string($data['tier'] ?? null) ? strtoupper(trim($data['tier'])) : '';
        if (!array_key_exists($tier, self::TIER_EMOJI)) {
            $tier = $this->deriveTierFromEnergy($energy);
        }

        return [
            'energy'     => $energy,
            'tier'       => $tier,
            'title'      => $this->clipString($data['title'] ?? '', 80),
            'emoji'      => $this->clipEmoji($data['emoji'] ?? ''),
            'soundtrack' => $this->clipString($data['soundtrack'] ?? '', 80),
            'forecast'   => $this->clipString($data['forecast'] ?? '', 160),
            'haiku'      => $this->clipString($data['haiku'] ?? '', 200),
            'scores'     => $scores,
            'verdict'    => $this->clipString($verdict, 300),
        ];
    }

    private function deriveTierFromEnergy(int $energy): string
    {
        foreach (self::TIER_ENERGY_FLOORS as $tier => $floor) {
            if ($energy >= $floor) {
                return $tier;
            }
        }

        return 'F';
    }

    private function clipString(mixed $value, int $limit): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_strlen($value) > $limit ? mb_strimwidth($value, 0, $limit, '…') : $value;
    }

    private function clipEmoji(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        // Keep only grapheme clusters that look like emoji/punctuation, drop spaces & prose.
        $collapsed = preg_replace('/\s+/u', '', $value) ?? '';
        if ($collapsed === '') {
            return '';
        }

        return mb_substr($collapsed, 0, 12);
    }
}
