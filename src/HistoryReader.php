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
    public function getPreviousMessages(Message $message, int $chainMessageToInclude, int $totalMessageToInclude, int $maxMessageFromHistoryToInclude, int $botUserId): array
    {
        $messages = [];
        $chatId = $message->getChat()->getId();
        if ($message->getReplyToMessage() !== null) {
            $responseMessage = $this->messageRepository->findMessageByIdInChat($message->getReplyToMessage()->getMessageId(), $chatId);
            if ($responseMessage !== null) {
                $messages[] = $responseMessage;
                while (count($messages) < min($chainMessageToInclude, $totalMessageToInclude) && $responseMessage?->replyToMessageId !== null) {
                    $responseMessage = $this->messageRepository->findMessageByIdInChat(
                        $responseMessage->replyToMessageId,
                        $chatId
                    );
                    if ($responseMessage === null) {
                        //This can happen if previous message is not recorded in history
                        break;
                    }
                    $messages[] = $responseMessage;
                }
            }

            // A single assistant turn can produce several bot messages that all
            // reply to the same trigger (e.g. an image-generation turn logs the
            // generated photo and the text reply separately). Only one of them is
            // reachable by following parents, so pull the others in as siblings
            // — otherwise the generated image is absent from the chain on the
            // next turn and the model cannot reference it.
            $siblingMessages = $this->messageRepository->findSiblingBotMessagesForChain(
                $chatId,
                $botUserId,
                $messages,
                $message->getMessageId(),
            );
            if (count($siblingMessages) > 0) {
                $messages = array_merge($messages, $siblingMessages);
                usort($messages, static fn (InternalMessage $a, InternalMessage $b) => $b->id <=> $a->id);
            }
        }
        $messagesFromHistoryNumber = min($totalMessageToInclude - count($messages), $maxMessageFromHistoryToInclude);
        if ($messagesFromHistoryNumber > 0) {
            $excludedIds = [];
            foreach ($messages as $replyMessage) {
                $excludedIds[] = $replyMessage->id;
            }
            $messagesFromHistory = $this->messageRepository->findNPreviousMessagesInChat(
                $chatId,
                $message->getMessageId(),
                $messagesFromHistoryNumber,
                $excludedIds,
            );
            $messages = array_merge($messages, $messagesFromHistory);
        }

        return array_reverse($messages);
    }
}
