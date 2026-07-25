<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Util\Telegram\ReactionSetter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ReactToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    // Excluded to avoid confusion with errors.
    private const array EXCLUDED_REACTIONS = ["🤔"];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** Telegram-allowed reactions minus those excluded for the react tool. */
    public static function allowedReactions(): array
    {
        return array_values(
            array_diff(ReactionSetter::ALLOWED_REACTIONS, self::EXCLUDED_REACTIONS),
        );
    }

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        if (!array_key_exists('reaction', $arguments)) {
            throw new \InvalidArgumentException('Reaction argument is required');
        }
        $allowed = self::allowedReactions();
        if (!in_array($arguments['reaction'], $allowed, true)) {
            $this->logger->log(LogLevel::WARNING, "Invalid reaction: {$arguments['reaction']}");

            return ['error' => ('Reaction must be one of: ' . implode(', ', $allowed))];
        }
        $lastMessage = $messageChain->last();
        ReactionSetter::setMessageReaction($lastMessage, $arguments['reaction']);

        return ['status' => 'reaction_successfully_applied'];
    }
}
