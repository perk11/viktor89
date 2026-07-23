<?php

namespace Perk11\Viktor89\UserSettings;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\UserPreferenceRepository;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ListBasedPreferenceByCommandProcessor extends UserPreferenceSetByCommandProcessor
{
    public function __construct(
        UserPreferenceRepository $userPreferenceRepository,
        array $triggeringCommands,
        string $preferenceName,
        string $botUserName,
        private readonly array $acceptedValuesList,
        LoggerInterface $logger,
    ) {
        parent::__construct($userPreferenceRepository, $triggeringCommands, $preferenceName, $botUserName, $logger);
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
        $this->sendPickerMessage($message, $buttons);

        return false;
    }

    /**
     * Sends the value-picker. In group/supergroup chats the picker is delivered as
     * an ephemeral message (Telegram Bot API "Ephemeral Messages and Commands") so
     * only the user who invoked the command sees it; in private chats it is a
     * normal message. If an ephemeral send is rejected — e.g. the bot is not a
     * chat administrator and no callback_query_id / ephemeral_message_id was
     * supplied — it falls back to a regular, publicly visible message.
     */
    protected function sendPickerMessage(InternalMessage $message, array $inlineKeyboard): void
    {
        $params = $this->buildPickerMessageParams($message, $inlineKeyboard);

        if (isset($params['receiver_user_id'])) {
            $response = Request::sendMessage($params);
            if ($response->isOk()) {
                InternalMessage::setEphemeralReaction($message->chatId, $message->id);
                return;
            }
            $this->logger->log(LogLevel::ERROR, "Failed to send ephemeral picker for '{$this->preferenceName}' ({$response->getDescription()}), falling back to a regular message");
            unset($params['receiver_user_id']);
        }

        Request::sendMessage($params);
    }

    protected function buildPickerMessageParams(InternalMessage $message, array $inlineKeyboard): array
    {
        $params = [
            'chat_id'      => $message->chatId,
            'text'         => 'Pick a value for ' . $this->preferenceName,
            'reply_markup' => ['inline_keyboard' => $inlineKeyboard],
        ];
        // receiver_user_id (ephemeral delivery) is only valid for group/supergroup
        // chats and only accepted when the bot is a chat administrator.
        if ($message->chatId < 0 && BotAdminChecker::isBotAdminInChat($message->chatId)) {
            $params['receiver_user_id'] = $message->userId;
        }

        return $params;
    }

    protected function getValueValidationErrors(?string $value, int $chatId): array
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
