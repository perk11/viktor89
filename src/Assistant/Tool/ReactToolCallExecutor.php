<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\MessageChain;

class ReactToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{

    public const array ALLOWED_REACTIONS = [
        "❤",
        "👍",
        "👎",
        "🔥",
        "🥰",
        "👏",
        "😁",
// Not allowed to avoid confusion with errors
//        "🤔",
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

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        if (!array_key_exists('reaction', $arguments)) {
            throw new \InvalidArgumentException('Reaction argument is required');
        }
        if (!in_array($arguments['reaction'], self::ALLOWED_REACTIONS, true)) {
            echo "Invalid reaction: {$arguments['reaction']}\n";

            return ['error' => ('Reaction must be one of: ' . implode(', ', self::ALLOWED_REACTIONS))];
        }
        $lastMessage = $messageChain->last();
        Request::execute('setMessageReaction', [
            'chat_id'    => $lastMessage->chatId,
            'message_id' => $lastMessage->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => $arguments['reaction'],
                ],
            ],
        ]);

        return ['status' => 'reaction_successfully_applied'];
    }
}
