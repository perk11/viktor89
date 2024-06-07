<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;

class PersonalityProcessor implements PreResponseProcessor
{
    private const KEY = 'personality';

    public function __construct(private readonly Database $database)
    {
    }

    public function process(Message $message): false|string|null
    {
        $messageText = $message->getText();
        if (!str_starts_with($messageText, '/personality')) {
            return false;
        }
        $personality = trim(str_replace('/personality', '', $messageText));
        if ($personality === 'reset' || $personality === '') {
            $personality = null;
        }
        $this->database->writeUserPreference($message->getFrom()->getId(), self::KEY, $personality);

        try {
            $response = Request::execute('setMessageReaction', [
                'chat_id'    => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
                'reaction'   => [[
                    'type'  => 'emoji',
                    'emoji' => '👌',
                ]],
                'is_big' => true
            ]);
            echo "Reacting to message result: $response\n";
        } catch (\Exception $e) {
            echo("Failed to react to message: " . $e->getMessage() . "\n");

            return $personality === null ? 'Теперь я буду отвечать тебе как случайный пользователь' : "Теперь я буду тебе отвечать как $personality";
        }

        return null;
    }

    public function getCurrentPersonality(int $userId): ?string
    {
        return $this->database->readUserPreference($userId, self::KEY);
    }
}
