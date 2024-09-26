<?php

namespace Perk11\Viktor89;

class PrintUserPreferencesResponder implements MessageChainProcessor
{
    public function __construct(private readonly Database $database)
    {
    }

    public function processMessageChain(array $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain[count($messageChain) - 1];
        $preferences = $this->database->readPreferencesArray($lastMessage->userId);
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->messageText = "Вот ваши настройки:\n\n";
        $message->parseMode = 'HTML';
        foreach ($preferences as $preference => $value) {
            if ($value === null) {
                continue;
            }
            $message->messageText .= "<b>" . htmlspecialchars($preference) . "</b>: " . htmlspecialchars($value) . "\n";
        }
        $message->messageText .= "\n";

        return new ProcessingResult($message, true);
    }
}
