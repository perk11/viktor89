<?php

namespace Perk11\Viktor89;


use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\Repository\MessageRepository;

class HistoryReader
{
    public function __construct(private readonly MessageRepository $messageRepository)
    {
    }


    /** @return InternalMessage[] */
    public function getPreviousMessages(Message $message, int $chainMessageToInclude, int $totalMessageToInclude, int $maxMessageFromHistoryToInclude): array
    {
        $messages = [];
        if ($message->getReplyToMessage() !== null) {
            $responseMessage = $this->messageRepository->findMessageByIdInChat($message->getReplyToMessage()->getMessageId(), $message->getChat()->getId());
            if ($responseMessage !== null) {
                $messages[] = $responseMessage;
                while (count($messages) < min($chainMessageToInclude, $totalMessageToInclude) && $responseMessage?->replyToMessageId !== null) {
                    $responseMessage = $this->messageRepository->findMessageByIdInChat(
                        $responseMessage->replyToMessageId,
                        $message->getChat()->getId()
                    );
                    if ($responseMessage === null) {
                        //This can happen if previous message is not recorded in history
                        break;
                    }
                    $messages[] = $responseMessage;
                }
            }
        }
        $messagesFromHistoryNumber = min($totalMessageToInclude - count($messages), $maxMessageFromHistoryToInclude);
        if ($messagesFromHistoryNumber > 0) {
            $excludedIds = [];
            foreach ($messages as $replyMessage) {
                $excludedIds[] = $replyMessage->id;
            }
            $messagesFromHistory = $this->messageRepository->findNPreviousMessagesInChat(
                $message->getChat()->getId(),
                $message->getMessageId(),
                $messagesFromHistoryNumber,
                $excludedIds,
            );
            $messages = array_merge($messages, $messagesFromHistory);
        }

        return array_reverse($messages);
    }
}
