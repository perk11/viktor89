<?php

namespace Perk11\Viktor89;

class PrintUserPreferencesResponder implements MessageChainProcessor
{
    public function __construct(private readonly Database $database)
    {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $preferences = $this->database->readPreferencesArray($messageChain->last()->userId);
        $message = InternalMessage::asResponseTo($messageChain->last());
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
