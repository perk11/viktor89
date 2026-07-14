<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

use Exception;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
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
 * Reply to a chat member (or run it bare for yourself) to "drop" a
 * collectible RPG stat card for that person. Reads the target's recent messages,
 * asks an LLM to grade Остроумие / Хаос / Мудрость / Дерзость and dream up an
 * archetype + a signature ability + a special (ultimate) ability + a weakness,
 * generates a fantasy portrait themed to the card's element, then overlays a
 * rarity frame + stat bars via GD. The card is always rendered in Russian and
 * without emoji (DejaVu fonts have no emoji glyphs).
 *
 * The special-ability line is a verbatim quote lifted from the target's own
 * messages, never a rephrasing — see pickSpecialAbilityQuote().
 */
class PersonalityCardProcessor implements MessageChainProcessor
{
    private const int MESSAGE_COUNT = 500;
    private const int PER_MESSAGE_TEXT_LIMIT = 200;

    /** @var string[] */
    private const STATS = ['wit', 'chaos', 'wisdom', 'menace'];

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly AssistantInterface $assistant,
        private readonly ImageByPromptGenerator $imageGenerator,
        private readonly PhotoResponder $photoResponder,
        private readonly PersonalityCardRenderer $renderer,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $command = $messageChain->last();
        $target = $this->resolveTarget($messageChain);
        $fail = static fn (): ProcessingResult => new ProcessingResult(null, true, '🤔', $command);
        Request::execute('setMessageReaction', [
            'chat_id'    => $command->chatId,
            'message_id' => $command->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        $progressUpdateCallback(static::class, 'Собираю досье на ' . $this->displayName($target) . '…');

        $recentMessages = $this->messageRepository->findLastMessagesByUserInChat(
            $command->chatId,
            $target->userId,
            self::MESSAGE_COUNT,
        );
        // findLastMessagesByUserInChat returns newest-first; chronological reads better for the model.
        $recentMessages = array_reverse($recentMessages);
        $transcript = $this->buildTranscript($recentMessages);

        if ($transcript === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo($command, '🤷 Этому человеку пока нечего показать — слишком мало сообщений для карточки.'),
                true,
            );
        }

        $progressUpdateCallback(static::class, 'Анализирую характер…');
        try {
            $completion = $this->assistant
                ->getCompletionBasedOnContext($this->buildContext($transcript, $target))
                ->content;
        } catch (Exception $e) {
            echo "PersonalityCard: assistant completion failed: " . $e->getMessage() . "\n";

            return $fail();
        }

        $card = $this->buildCard($completion, $target, $transcript);
        if ($card === null) {
            return $fail();
        }

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

            return $fail();
        }

        $progressUpdateCallback(static::class, 'Собираю карточку…');
        try {
            $cardImage = $this->renderer->render($card, $portrait);
        } catch (Exception $e) {
            echo "PersonalityCard: render failed: " . $e->getMessage() . "\n";

            return $fail();
        }

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
{"wit":0,"chaos":0,"wisdom":0,"menace":0,"archetype":"","ability":"","abilityEffect":"","specialAbility":"","specialAbilityQuote":"","weakness":"","portrait":""}

Field rules:
- "wit": integer 0-10 — cleverness, comebacks, wordplay, comedic timing.
- "chaos": integer 0-10 — unpredictability, disruption, derailing conversations, anarchy.
- "wisdom": integer 0-10 — depth, insight, thoughtfulness, giving real perspective.
- "menace": integer 0-10 — provocation, trolling, boldness, pushing buttons.
- "archetype": a short, punchy RPG class or title (1-3 words) that fits this person. Be original and specific to them. No inner quotes.
- "ability": a signature-move name (1-3 words) that sounds like a gacha-card skill and fits their vibe. No inner quotes.
- "abilityEffect": one punchy sentence describing what the signature ability does, in trading-card style. Max ~15 words. No inner quotes.
- "specialAbility": a name (1-3 words) for their more powerful ULTIMATE / special ability, distinct from the regular ability. No inner quotes.
- "specialAbilityQuote": a VERBATIM word-for-word excerpt copied EXACTLY from the person's own messages in the transcript — their most iconic, memorable or characteristic line. It MUST appear character-for-character somewhere in the transcript. Do NOT rephrase, paraphrase, summarise, translate or invent anything. Copy the exact original text. No quotation marks around it in the JSON value. Prefer a line under ~120 characters.
- "weakness": a funny, specific fatal-flaw / kryptonite that trips this person up — what undoes them. Write 1-2 sentences (~15-25 words), make it vivid and personal. No inner quotes.
- "portrait": a LITERAL English description of a FICTIONAL fantasy character avatar that embodies this person's archetype and vibe (NOT a real likeness — you do not know their appearance). Describe face, expression, outfit, props, mood. This text is fed straight to an image generator that only understands concrete visual nouns.

Rate honestly and with variance: most people should land in the middle of each axis, not everything at 8-10. A single person's stats should differ from each other — nobody maxes everything, nobody is all zeroes.

The card is ALWAYS in Russian. Write "archetype", "ability", "abilityEffect", "specialAbility" and "weakness" in Russian, regardless of the language this person writes in. "specialAbilityQuote" is copied verbatim from their messages, so it stays in whatever language/wording they actually used. "portrait" MUST be in English.

Output raw, valid JSON on a single line. Nothing else.
PROMPT;

        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = "Person name: {$name}\n\nTheir recent messages (newest last):\n\n" . $transcript;
        $context->messages[] = $message;

        return $context;
    }

    private function buildCard(string $completion, InternalMessage $target, string $transcript): ?PersonalityCard
    {
        $completion = trim($completion);
        // Tolerate models that wrap JSON in prose or code fences.
        if (preg_match('/\{.*\}/s', $completion, $matches) === 1) {
            $completion = $matches[0];
        }

        try {
            $data = json_decode($completion, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            echo "Failed to parse JSON: {$completion}\n";
            return null;
        }

        if (!is_array($data)) {
            echo "JSON is not array: {$completion}\n";
            return null;
        }

        $stats = [];
        foreach (self::STATS as $stat) {
            if (!array_key_exists($stat, $data) || !is_numeric($data[$stat])) {
                echo "$stat is missing in JSON: {$completion}\n";
                return null;
            }
            $stats[$stat] = max(0, min(10, (int) $data[$stat]));
        }

        $archetype = $this->clipString($data['archetype'] ?? '', 40);
        $power = array_sum($stats);
        $rarity = PersonalityCardRarity::fromPower($power);
        $element = PersonalityCardElement::fromStats($stats);

        return new PersonalityCard(
            name: $this->displayName($target),
            archetype: $archetype !== '' ? $archetype : 'Неизвестный Герой',
            stats: $stats,
            element: $element,
            ability: $this->clipString($data['ability'] ?? '', 40),
            abilityEffect: $this->clipString($data['abilityEffect'] ?? '', 160),
            specialAbility: $this->clipString($data['specialAbility'] ?? '', 40),
            specialAbilityQuote: $this->pickSpecialAbilityQuote($data['specialAbilityQuote'] ?? '', $transcript),
            weakness: $this->clipString($data['weakness'] ?? '', 160),
            portraitPrompt: $this->clipString($data['portrait'] ?? '', 400),
            rarity: $rarity,
            power: $power,
            stars: PersonalityCardRarity::stars($rarity),
            cardNumber: $this->cardNumber($target),
        );
    }

    /**
     * Guarantees the special-ability quote is the target's OWN verbatim words:
     * accept the model's pick only if it appears (case-insensitively, ignoring
     * whitespace differences) inside one of their transcript lines — and in that
     * case display the exact text from the transcript. If the model paraphrased or
     * invented the line, fall back to the longest substantial line they wrote.
     */
    private function pickSpecialAbilityQuote(string $quote, string $transcript): string
    {
        $lines = [];
        foreach (explode("\n", $transcript) as $line) {
            $line = $this->collapseSpaces($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $quote = $this->collapseSpaces($quote);
        if ($quote !== '') {
            foreach ($lines as $line) {
                $pos = mb_stripos($line, $quote);
                if ($pos !== false) {
                    return $this->clipString(mb_substr($line, $pos, mb_strlen($quote)), 160);
                }
            }
        }

        usort($lines, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($lines as $line) {
            if (mb_strlen($line) <= 140) {
                return $this->clipString($line, 160);
            }
        }

        return $this->clipString($lines[0] ?? '', 160);
    }

    private function collapseSpaces(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function buildPortraitPrompt(PersonalityCard $card): string
    {
        $description = $card->portraitPrompt !== ''
            ? $card->portraitPrompt
            : 'a ' . $card->archetype . ' character radiating a chaotic online personality';

        return 'fantasy RPG character portrait, head and shoulders, centered composition, '
            . $description
            . ', ' . PersonalityCardElement::portraitHint($card->element)
            . ', highly detailed face, expressive, dramatic cinematic lighting, '
            . 'digital painting, trading card game illustration, vibrant colours, '
            . 'plain dark background, sharp focus, no text, no signature, no border';
    }

    private function buildCaption(PersonalityCard $card): string
    {
        return sprintf(
            "%s — %s\n%s  ·  %s  ·  Мощь %d  ·  %s",
            $card->name,
            $card->archetype,
            str_repeat('★', max(1, $card->stars)) . ' ' . PersonalityCardRarity::label($card->rarity),
            PersonalityCardElement::label($card->element),
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
}
