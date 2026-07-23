<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Repository\RateLimitRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RateLimitProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly RateLimitRepository $rateLimitRepository,
        private int $botUserId,
        private readonly array $rateLimitByChatId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getFrom() === null) {
            return false;
        }
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();
        if (!array_key_exists($chatId, $this->rateLimitByChatId)) {
            return false;
        }
        if ($message->getText() === '/ratelimits') { //allow to check the limits
            return false;
        }
        if (
            $message->getType() !== 'command' && //TODO: ignore unknown commands
            !str_contains($message->getText(), '@' . $_ENV['TELEGRAM_BOT_USERNAME'])
        ) {
                $replyToMessage = $message->getReplyToMessage();
                if ($replyToMessage === null) {
                    return false;
                }
                if ($replyToMessage->getFrom()->getId() !== $this->botUserId) {
                    return false;
                }
            }
        $limit = $this->rateLimitByChatId[$message->getChat()->getId()];
        $priorMessages = $this->rateLimitRepository->countMessagesInChatFromBotToUserInLast24Hours(
            $chatId,
            $userId,
        );

        if ($priorMessages < $limit) {
            return false;
        }
        $this->logger->log(LogLevel::INFO, "$priorMessages already sent by user {$userId} in chat $chatId, sending reaction instead of message");
        Request::execute('setMessageReaction', [
            'chat_id'    => $chatId,
            'message_id' => $message->getMessageId(),
            'reaction'   => [[
                'type'  => 'emoji',
                'emoji' => '🙊',
            ]],
        ]);

        return null;
    }
}
