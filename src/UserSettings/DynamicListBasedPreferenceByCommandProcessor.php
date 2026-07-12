<?php

namespace Perk11\Viktor89\UserSettings;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\UserPreferenceRepository;

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
 *
 * The callback receives the chat id the command was issued in, so the option
 * list can be filtered per chat (e.g. hide models restricted to other chats).
 */
class DynamicListBasedPreferenceByCommandProcessor extends ListBasedPreferenceByCommandProcessor
{
    private ?int $currentChatId = null;

    /**
     * @param \Closure(int $chatId): array<int, array{value: string, label?: string}> $optionsCallback
     * @param string[] $resetValues Values (case-insensitive) that clear the preference
     */
    public function __construct(
        UserPreferenceRepository $userPreferenceRepository,
        array $triggeringCommands,
        string $preferenceName,
        string $botUserName,
        private readonly \Closure $optionsCallback,
        private readonly array $resetValues = [],
    ) {
        parent::__construct($userPreferenceRepository, $triggeringCommands, $preferenceName, $botUserName, []);
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
        // Remember the chat so getValueValidationErrors() validates against the
        // same per-chat option list. processValueAsSetting() always runs before
        // validation in the parent flow.
        $this->currentChatId = $message->chatId;

        if ($value !== '') {
            // A real value (including null from a reset value) -> proceed to validate and store.
            return true;
        }
        // No argument -> show the picker buttons.
        Request::sendMessage([
            'chat_id'      => $message->chatId,
            'text'         => 'Pick a value for ' . $this->preferenceName,
            'reply_markup' => ['inline_keyboard' => $this->buildInlineKeyboard($this->currentChatId)],
        ]);

        return false;
    }

    protected function getValueValidationErrors(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = array_column($this->getOptions($this->currentChatId ?? 0), 'value');
        if (!in_array($value, $values, true)) {
            return ["Эта настройка принимает следующие значения:\n\n" . implode("\n", $values)];
        }

        return [];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getOptions(int $chatId): array
    {
        $options = [];
        foreach (($this->optionsCallback)($chatId) as $option) {
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
    private function buildInlineKeyboard(int $chatId): array
    {
        $buttons = [];
        foreach ($this->getOptions($chatId) as $option) {
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
