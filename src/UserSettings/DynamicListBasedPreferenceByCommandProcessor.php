<?php

namespace Perk11\Viktor89\UserSettings;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;

/**
 * Like ListBasedPreferenceByCommandProcessor, but the list of accepted values is
 * built dynamically via a callback (e.g. read from the database at call time).
 *
 * Each option has a display "label" (shown on the button) and a "value" (what is
 * sent via switch_inline_query_current_chat and stored as the preference). This
 * lets the button show extra context (e.g. an author) without that context
 * leaking into the query or the stored value.
 *
 * Values listed in $resetValues (compared case-insensitively), as well as
 * "reset", are stored as null — the same way resetting a preference works.
 */
class DynamicListBasedPreferenceByCommandProcessor extends ListBasedPreferenceByCommandProcessor
{
    /**
     * @param \Closure(): array<int, array{value: string, label?: string}> $optionsCallback
     * @param string[] $resetValues Values (case-insensitive) that clear the preference
     */
    public function __construct(
        Database $database,
        array $triggeringCommands,
        string $preferenceName,
        string $botUserName,
        private readonly \Closure $optionsCallback,
        private readonly array $resetValues = [],
    ) {
        parent::__construct($database, $triggeringCommands, $preferenceName, $botUserName, []);
    }

    public function transformValue(string $value): mixed
    {
        if ($value === 'reset') {
            return null;
        }
        $resetValuesLower = array_map('mb_strtolower', $this->resetValues);
        if (in_array(mb_strtolower($value), $resetValuesLower, true)) {
            return null;
        }

        return $value;
    }

    protected function processValueAsSetting(InternalMessage $message, ?string $value): bool
    {
        if ($value !== '') {
            // A real value (including null from a reset value) -> proceed to validate and store.
            return true;
        }
        // No argument -> show the picker buttons.
        Request::sendMessage([
            'chat_id'      => $message->chatId,
            'text'         => 'Pick a value for ' . $this->preferenceName,
            'reply_markup' => ['inline_keyboard' => $this->buildInlineKeyboard()],
        ]);

        return false;
    }

    protected function getValueValidationErrors(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = array_column($this->getOptions(), 'value');
        if (!in_array($value, $values, true)) {
            return ["Эта настройка принимает следующие значения:\n\n" . implode("\n", $values)];
        }

        return [];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getOptions(): array
    {
        $options = [];
        foreach (($this->optionsCallback)() as $option) {
            $options[] = [
                'value' => $option['value'],
                'label' => $option['label'] ?? $option['value'],
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array<int, array{text: string, switch_inline_query_current_chat: string}>>
     */
    private function buildInlineKeyboard(): array
    {
        $buttons = [];
        foreach ($this->getOptions() as $option) {
            $buttons[] = [
                [
                    'text'                             => $option['label'],
                    'switch_inline_query_current_chat' => $this->triggeringCommands[0] . ' ' . $option['value'],
                ],
            ];
        }

        return $buttons;
    }
}
