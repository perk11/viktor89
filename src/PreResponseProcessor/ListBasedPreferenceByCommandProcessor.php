<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;

class ListBasedPreferenceByCommandProcessor extends UserPreferenceSetByCommandProcessor
{
    public function __construct(
        Database $database,
        array $triggeringCommands,
        string $preferenceName,
        string $botUserName,
        private readonly array $acceptedValuesList,
    ) {
        parent::__construct($database, $triggeringCommands, $preferenceName, $botUserName);
    }

    protected function processValueAsSetting(Message $message, ?string $value): bool
    {
        $buttons = [];
        foreach ($this->acceptedValuesList as $acceptedValue) {
            $buttons[] = [
                [
                    'text'                             => $acceptedValue,
                    'switch_inline_query_current_chat' => $this->triggeringCommands[0] . ' ' . $acceptedValue,
                ],
            ];
        }
        if ($value === null) {
            Request::sendMessage([
                                     'chat_id'      => $message->getChat()->getId(),
                                     'text'         => 'Pick a value for ' . $this->preferenceName,
                                     'reply_markup' => [
                                         'inline_keyboard' => $buttons,
                                     ],
                                 ]);

            return false;
        }

        return true;
    }



    protected function getValueValidationErrors(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!in_array($value, $this->acceptedValuesList, true)) {
            return ["Эта настройка принимает следующие значения:\n\n" . implode("\n", $this->acceptedValuesList)];
        }

        return [];
    }
}
