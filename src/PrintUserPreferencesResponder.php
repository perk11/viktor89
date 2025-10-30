<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;

class PrintUserPreferencesResponder implements MessageChainProcessor
{
    public function __construct(private readonly Database $database)
    {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $preferences = $this->database->readPreferencesArray($messageChain->last()->userId);
        $message = InternalMessage::asResponseTo($messageChain->last());
        $message->messageText = "Вот ваши настройки:\n\n";
        $message->parseMode = 'HTML';
        foreach ($preferences as $preference => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $message->messageText .= "<b>" . htmlspecialchars($preference) . "</b>: " . htmlspecialchars($value) . "\n";
        }
        $message->messageText .= "\n";

        return new ProcessingResult($message, true);
    }
}
