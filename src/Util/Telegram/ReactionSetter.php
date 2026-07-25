<?php

namespace Perk11\Viktor89\Util\Telegram;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;

/**
 * Sets an emoji message reaction via Telegram's setMessageReaction, rejecting
 * emojis Telegram does not support before the request is made.
 */
class ReactionSetter
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

    /**
     * Telegram accepts reactions with or without the U+FE0F variation selector
     * (e.g. both "❤" and "❤️"); the documented list uses the non-qualified form.
     */
    public static function isReactionAllowed(string $emoji): bool
    {
        return in_array(str_replace("\u{FE0F}", '', $emoji), self::ALLOWED_REACTIONS, true);
    }

    public static function setReaction(
        int $chatId,
        int $messageId,
        string $emoji,
        bool $isBig = false,
    ): string {
        if (!self::isReactionAllowed($emoji)) {
            throw new \InvalidArgumentException(
                "Unsupported reaction '$emoji': must be one of the emoji allowed by Telegram "
                . '(see ReactionSetter::ALLOWED_REACTIONS).'
            );
        }

        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => $emoji,
                ],
            ],
        ];
        if ($isBig) {
            $params['is_big'] = true;
        }

        return Request::execute('setMessageReaction', $params);
    }

    public static function setMessageReaction(InternalMessage $message, string $emoji, bool $isBig = false): string
    {
        return self::setReaction($message->chatId, $message->id, $emoji, $isBig);
    }
}
