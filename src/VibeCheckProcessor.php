<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\TelegramHtml;

class VibeCheckProcessor implements MessageChainProcessor, GetTriggeringCommandsInterface
{
    private const DEFAULT_MESSAGE_COUNT = 50;
    private const MAX_MESSAGE_COUNT = 200;
    private const MIN_MESSAGE_COUNT = 5;
    private const PER_MESSAGE_TEXT_LIMIT = 200;
    private const BAR_WIDTH = 10;

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

        $progressUpdateCallback(static::class, 'Считаю вайб чата…');

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

    public function getTriggeringCommands(): array
    {
        return ['/vibecheck'];
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
You analyze the current vibe of a group chat based on its recent messages.
Respond with ONLY a minified JSON object and nothing else: no markdown, no code fences, no explanation.

Schema (every field required):
{"chaos":0,"wholesome":0,"brainrot":0,"thirst":0,"drama":0,"verdict":""}

Rules:
- Each score is an integer from 0 to 10 describing how present that vibe is in the messages.
- "verdict": one punchy, witty sentence (max 2 short sentences) summarizing the chat's energy right now.
- Write "verdict" in the same language the chat mostly uses.
- Output raw, valid JSON on a single line.
PROMPT;

        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = "Recent chat messages (newest last):\n\n" . $transcript;
        $context->messages[] = $message;

        return $context;
    }

    private function renderReport(string $completion, int $analyzedCount): string
    {
        $parsed = $this->parseScores($completion);

        $report = "🌡 <b>Vibe check</b> <i>(на основе {$analyzedCount} сообщений)</i>\n\n";

        if ($parsed !== null) {
            $report .= "<pre>";
            foreach (self::AXES as $axis) {
                $label = str_pad(self::AXIS_LABELS[$axis], 10);
                $score = max(0, min(10, $parsed['scores'][$axis]));
                $bar = str_repeat('█', $score) . str_repeat('░', self::BAR_WIDTH - $score);
                $report .= $label . ' ' . $bar . ' ' . $score . "\n";
            }
            $report .= "</pre>\n\n";
            $report .= TelegramHtml::escape($parsed['verdict']);
        } else {
            // Model didn't return parseable JSON; show its raw text as a fallback.
            $report .= TelegramHtml::escape(trim($completion));
        }

        return $report;
    }

    /**
     * @return array{scores: array<string, int>, verdict: string}|null
     */
    private function parseScores(string $completion): ?array
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

        $scores = [];
        foreach (self::AXES as $axis) {
            if (!array_key_exists($axis, $data) || !is_numeric($data[$axis])) {
                return null;
            }
            $scores[$axis] = (int) $data[$axis];
        }

        $verdict = is_string($data['verdict'] ?? null) ? trim($data['verdict']) : '';
        if ($verdict === '') {
            return null;
        }

        return ['scores' => $scores, 'verdict' => $verdict];
    }
}
