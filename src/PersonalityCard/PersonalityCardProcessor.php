<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

use Exception;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\GetTriggeringCommandsInterface;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;

/**
 * `/personalitycard` (alias `/pcard`) — reply to a chat member (or run it bare
 * for yourself) to "drop" a collectible RPG stat card for that person. Reads the
 * target's recent messages, asks an LLM to grade Charisma / Chaos / Brainrot /
 * Wholesome / Menace and dream up an archetype + flavour, generates a fantasy
 * portrait for that archetype, then overlays a rarity frame + stat bars via GD.
 */
class PersonalityCardProcessor implements MessageChainProcessor, GetTriggeringCommandsInterface
{
    private const DEFAULT_MESSAGE_COUNT = 60;
    private const MAX_MESSAGE_COUNT = 200;
    private const MIN_MESSAGE_COUNT = 5;
    private const PER_MESSAGE_TEXT_LIMIT = 200;

    /** @var string[] */
    private const STATS = ['charisma', 'chaos', 'brainrot', 'wholesome', 'menace'];

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly AssistantInterface $assistant,
        private readonly ImageByPromptGenerator $imageGenerator,
        private readonly PhotoResponder $photoResponder,
        private readonly PersonalityCardRenderer $renderer,
        /** Defaults to a live Telegram reaction; injectable so the full flow is unit-testable. */
        private readonly ?\Closure $react = null,
    ) {
    }

    public function getTriggeringCommands(): array
    {
        return ['/personalitycard', '/pcard'];
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $command = $messageChain->last();
        $target = $this->resolveTarget($messageChain);
        $messageCount = $this->parseMessageCount($command->messageText);
        $fail = static fn (string $text): ProcessingResult => new ProcessingResult(
            InternalMessage::asResponseTo($command, $text),
            true,
        );

        $progressUpdateCallback(static::class, 'Собираю досье на ' . $this->displayName($target) . '…');

        $recentMessages = $this->messageRepository->findLastMessagesByUserInChat(
            $command->chatId,
            $target->userId,
            $messageCount,
        );
        // findLastMessagesByUserInChat returns newest-first; chronological reads better for the model.
        $recentMessages = array_reverse($recentMessages);
        $transcript = $this->buildTranscript($recentMessages);

        if ($transcript === '') {
            return $fail('🤷 Этому человеку пока нечего показать — слишком мало сообщений для карточки.');
        }

        $progressUpdateCallback(static::class, 'Анализирую характер…');
        try {
            $completion = $this->assistant
                ->getCompletionBasedOnContext($this->buildContext($transcript, $target))
                ->content;
        } catch (Exception $e) {
            echo "PersonalityCard: assistant completion failed: " . $e->getMessage() . "\n";

            return $fail('🤔 Не получилось прочитать характер, попробуйте ещё раз.');
        }

        $card = $this->buildCard($completion, $target);
        if ($card === null) {
            return $fail('🤔 Не получилось собрать карточку, попробуйте ещё раз.');
        }

        $this->react($command, '👀');
        $progressUpdateCallback(
            static::class,
            'Рисую портрет: ' . $card->archetype . '…',
            new ChatAction($command->chatId, ChatActionEnum::upload_photo),
        );

        try {
            $portrait = $this->imageGenerator
                ->generateImageByPrompt($this->buildPortraitPrompt($card), $command->userId)
                ->getFirstImageAsPng();
        } catch (Exception $e) {
            echo "PersonalityCard: image generation failed: " . $e->getMessage() . "\n";
            $this->react($command, '🤔');

            return $fail('🤔 Портрет не нарисовался, попробуйте ещё раз.');
        }

        $progressUpdateCallback(static::class, 'Собираю карточку…');
        try {
            $cardImage = $this->renderer->render($card, $portrait);
        } catch (Exception $e) {
            echo "PersonalityCard: render failed: " . $e->getMessage() . "\n";
            $this->react($command, '🤔');

            return $fail('🤔 Карточка не собралась, попробуйте ещё раз.');
        }

        // PhotoResponder swaps the 👀 reaction to 😎 once the photo lands.
        $this->photoResponder->sendPhoto($command, $cardImage, false, $this->buildCaption($card));

        return new ProcessingResult(null, true);
    }

    /**
     * The card's subject: the user the command replies to, or — when used bare
     * or as a self-reply — the person who issued the command.
     */
    private function resolveTarget(MessageChain $messageChain): InternalMessage
    {
        $command = $messageChain->last();
        $replyToId = $command->replyToMessageId;
        if ($replyToId !== null) {
            $replied = $messageChain->previous();
            // Only trust previous() as the target when it really is the replied message,
            // otherwise (unlogged reply target) previous() would be unrelated history.
            if ($replied !== null && $replied->id === $replyToId && $replied->userId !== $command->userId) {
                return $replied;
            }
        }

        return $command;
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
            if (mb_strlen($text) > self::PER_MESSAGE_TEXT_LIMIT) {
                $text = mb_strimwidth($text, 0, self::PER_MESSAGE_TEXT_LIMIT, '…');
            }
            $lines[] = $text;
        }

        return implode("\n", $lines);
    }

    private function buildContext(string $transcript, InternalMessage $target): AssistantContext
    {
        $name = $this->displayName($target);

        $context = new AssistantContext();
        $context->systemPrompt = <<<PROMPT
You are "PersonalityCard", an analyst that turns a chat member's recent messages into a collectible RPG stat card.

You will be given the person's display name and a transcript of ONLY their own recent messages. Judge their personality from how they write.

Respond with ONLY a minified JSON object on a single line. No markdown, no code fences, no commentary before or after.

Schema (every field required):
{"charisma":0,"chaos":0,"brainrot":0,"wholesome":0,"menace":0,"archetype":"","quote":"","portrait":""}

Field rules:
- "charisma": integer 0-10 — charm, wit, social magnetism.
- "chaos": integer 0-10 — unhinged / disruptive / unpredictable energy.
- "brainrot": integer 0-10 — memes, slang, shitposting, terminally online vibes.
- "wholesome": integer 0-10 — warmth, kindness, supportive energy.
- "menace": integer 0-10 — trolling, provocation, lovable-trickster menace.
- "archetype": a short, punchy RPG class or title (1-3 words) that fits this person. Be original and specific to them. No inner quotes.
- "quote": a single flavour sentence in the style of a trading-card tagline that captures their essence. Max ~15 words. No inner quotes.
- "portrait": a LITERAL English description of a FICTIONAL fantasy character avatar that embodies this person's archetype and vibe (NOT a real likeness — you do not know their appearance). Describe face, expression, outfit, props, mood. This text is fed straight to an image generator that only understands concrete visual nouns.

Write "archetype" and "quote" in the language this person mostly writes in. "portrait" MUST be in English.

Output raw, valid JSON on a single line. Nothing else.
PROMPT;

        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = "Person name: {$name}\n\nTheir recent messages (newest last):\n\n" . $transcript;
        $context->messages[] = $message;

        return $context;
    }

    private function buildCard(string $completion, InternalMessage $target): ?PersonalityCard
    {
        $completion = trim($completion);
        // Tolerate models that wrap JSON in prose or code fences.
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

        $stats = [];
        foreach (self::STATS as $stat) {
            if (!array_key_exists($stat, $data) || !is_numeric($data[$stat])) {
                return null;
            }
            $stats[$stat] = max(0, min(10, (int) $data[$stat]));
        }

        $archetype = $this->clipString($data['archetype'] ?? '', 40);
        $power = array_sum($stats);
        $rarity = PersonalityCardRarity::fromPower($power);

        return new PersonalityCard(
            name: $this->displayName($target),
            archetype: $archetype !== '' ? $archetype : 'Unknown Hero',
            stats: $stats,
            quote: $this->clipString($data['quote'] ?? '', 160),
            portraitPrompt: $this->clipString($data['portrait'] ?? '', 400),
            rarity: $rarity,
            power: $power,
            stars: PersonalityCardRarity::stars($rarity),
            cardNumber: $this->cardNumber($target),
        );
    }

    private function buildPortraitPrompt(PersonalityCard $card): string
    {
        $description = $card->portraitPrompt !== ''
            ? $card->portraitPrompt
            : 'a ' . $card->archetype . ' character radiating a chaotic online personality';

        return 'fantasy RPG character portrait, head and shoulders, centered composition, '
            . $description
            . ', highly detailed face, expressive, dramatic cinematic lighting, '
            . 'digital painting, trading card game illustration, vibrant colours, '
            . 'plain dark background, sharp focus, no text, no signature, no border';
    }

    private function buildCaption(PersonalityCard $card): string
    {
        return sprintf(
            "🎴 %s — %s\n%s  ·  ⚡ Power %d  ·  %s",
            $card->name,
            $card->archetype,
            str_repeat('★', max(1, $card->stars)) . ' ' . PersonalityCardRarity::label($card->rarity),
            $card->power,
            $card->cardNumber,
        );
    }

    private function displayName(InternalMessage $target): string
    {
        $name = trim($target->userName);
        if ($name !== '') {
            return $name;
        }

        return 'User' . $target->userId;
    }

    private function cardNumber(InternalMessage $target): string
    {
        // Stable per-user collectible number so a regular always "drops" the same card id.
        $hash = crc32($target->userId . '|' . $target->userName);

        return '#' . str_pad((string) (abs($hash) % 10000), 4, '0', STR_PAD_LEFT);
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

    private function react(InternalMessage $message, string $emoji): void
    {
        if ($this->react !== null) {
            ($this->react)($message, $emoji);
            return;
        }
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [['type' => 'emoji', 'emoji' => $emoji]],
        ]);
    }
}
