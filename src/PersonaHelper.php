<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class PersonaHelper
{
    public const string DEFAULT_PERSONA_NAME = 'Default';
    public const string PERSONA_PREFERENCE = 'persona';
    public const int MAX_NAME_LENGTH = 64;

    private const array RESERVED_NAMES = ['default'];

    public function __construct(
        private readonly string $botUserName,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function normalizeArgument(string $argument): string
    {
        $argument = trim($argument);
        $mention = '@' . $this->botUserName;
        if (str_starts_with($argument, $mention)) {
            $argument = $mention
                    |> mb_strlen(...)
                    |> (static fn($x) => mb_substr($argument, $x))
                    |> trim(...);
        }

        return $argument;
    }

    public function extractName(string $argument): string
    {
        $firstLineEnd = strpos($argument, "\n");

        return trim($firstLineEnd === false ? $argument : substr($argument, 0, $firstLineEnd));
    }

    public function isReservedName(string $name): bool
    {
        return $name
                |> trim(...)
                |> mb_strtolower(...)
                |> (static fn($x) => in_array($x, self::RESERVED_NAMES, true));
    }

    public function reactOrRespond(InternalMessage $message, string $fallbackText): ProcessingResult
    {
        try {
            $response = Request::execute('setMessageReaction', [
                'chat_id'    => $message->chatId,
                'message_id' => $message->id,
                'reaction'   => [[
                    'type'  => 'emoji',
                    'emoji' => '👌',
                ]],
                'is_big' => true,
            ]);
            $this->logger->log(LogLevel::INFO, "Reacting to message ($fallbackText) result: $response");

            return new ProcessingResult(null, true);
        } catch (\Throwable $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to react to message: ' . $e->getMessage());

            return new ProcessingResult(InternalMessage::asResponseTo($message, $fallbackText), true);
        }
    }
}
