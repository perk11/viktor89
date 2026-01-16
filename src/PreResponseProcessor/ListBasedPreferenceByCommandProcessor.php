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
        $rowIndex = 0;
        foreach ($this->acceptedValuesList as $acceptedValue) {
            if ($rowIndex === 0) {
                if (isset($row)) {
                    $buttons[] = $row;
                }
                $row = [];
            }
            $row[] =
                [
                    'text' => $this->triggeringCommands[0] . ' ' . $acceptedValue,
                ];
            $rowIndex = ($rowIndex +1 )% 2;
        }
        if (isset($row)) {
            $buttons[] = $row;
        }
        Request::sendMessage([
                                 'chat_id'      => $message->chatId,
                                 'reply_parameters' => ['message_id' => $message->id],
                                 'text'         => 'Вот клавиатура для выбора ' . $this->preferenceName,
                                 'reply_markup' => [
                                     'keyboard' => $buttons,
                                     'one_time_keyboard' => true,
                                     'resize_keyboard' => true,
                                     'selective' => true,
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
