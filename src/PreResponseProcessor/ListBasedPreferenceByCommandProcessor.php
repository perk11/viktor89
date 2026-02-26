<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;

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

    protected function processValueAsSetting(InternalMessage $message, ?string $value): bool
    {
        if ($value !== null) {
            return true;
        }

        $buttons = [];
        foreach ($this->acceptedValuesList as $acceptedValue) {
            $buttons[] = [
                [
                    'text'                             => $acceptedValue,
                    'switch_inline_query_current_chat' => $this->triggeringCommands[0] . ' ' . $acceptedValue,
                ],
            ];
        }
        Request::sendMessage([
                                 'chat_id'      => $message->chatId,
                                 'text'         => 'Pick a value for ' . $this->preferenceName,
                                 'reply_markup' => [
                                     'inline_keyboard' => $buttons,
                                 ],
                             ]);

        return false;
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
