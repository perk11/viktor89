<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class ProcessingResult
{
    /**
     * Emoji reactions accepted by Telegram's setMessageReaction
     * (Bot API ReactionTypeEmoji, non-fully-qualified form). Custom-emoji
     * reactions are Premium-only and omitted here.
     */
    public const array ALLOWED_REACTIONS = [
        "❤",
        "👍",
        "👎",
        "🔥",
        "🥰",
        "👏",
        "😁",
        "🤔",
        "🤯",
        "😱",
        "🤬",
        "😢",
        "🎉",
        "🤩",
        "🤮",
        "💩",
        "🙏",
        "👌",
        "🕊",
        "🤡",
        "🥱",
        "🥴",
        "😍",
        "🐳",
        "❤‍🔥",
        "🌚",
        "🌭",
        "💯",
        "🤣",
        "⚡",
        "🍌",
        "🏆",
        "💔",
        "🤨",
        "😐",
        "🍓",
        "🍾",
        "💋",
        "🖕",
        "😈",
        "😴",
        "😭",
        "🤓",
        "👻",
        "👨‍💻",
        "👀",
        "🎃",
        "🙈",
        "😇",
        "😨",
        "🤝",
        "✍",
        "🤗",
        "🫡",
        "🎅",
        "🎄",
        "☃",
        "💅",
        "🤪",
        "🗿",
        "🆒",
        "💘",
        "🙉",
        "🦄",
        "😘",
        "💊",
        "🙊",
        "😎",
        "👾",
        "🤷‍♂",
        "🤷",
        "🤷‍♀",
        "😡",
    ];

    public $callback;
    public function __construct(
        public readonly ?InternalMessage $response,
        public readonly bool $abortProcessing,
        public readonly ?string $reaction = null,
        public readonly ?InternalMessage $messageToReactTo = null,
        ?callable $callback = null,
    )
    {
        if ($reaction !== null) {
            // Telegram accepts reactions with or without the U+FE0F variation selector
            // (e.g. both "❤" and "❤️"); the documented list uses the non-qualified form.
            $normalized = str_replace("\u{FE0F}", '', $reaction);
            if (!in_array($normalized, self::ALLOWED_REACTIONS, true)) {
                throw new \InvalidArgumentException(
                    "Unsupported reaction '$reaction': must be one of the emoji allowed by Telegram "
                    . '(see ProcessingResult::ALLOWED_REACTIONS).'
                );
            }
        }
        $this->callback = $callback;
    }
}
