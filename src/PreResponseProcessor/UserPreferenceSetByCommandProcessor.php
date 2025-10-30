<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Exception;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\GetTriggeringCommandsInterface;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class UserPreferenceSetByCommandProcessor implements MessageChainProcessor, UserPreferenceReaderInterface, GetTriggeringCommandsInterface
{
    public function __construct(
        private readonly Database $database,
        protected readonly array $triggeringCommands,
        protected readonly string $preferenceName,
        protected readonly string $botUserName,
    )
    {
    }

    protected function getValueValidationErrors(?string $value): array
    {
        return [];
    }

    protected function processValueAsSetting(InternalMessage $message, ?string $value): bool
    {
        return true;
    }

    protected function transformValue(string $value): mixed
    {
        if ($value === 'reset' || $value === '') {
            return null;
        }

        return $value;
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        $messageText = $lastMessage->messageText;
        $triggerFound = false;
        if (str_starts_with($messageText, '@' . $this->botUserName)) {
            $messageText = ltrim(str_replace('@' . $this->botUserName, '', $messageText));
        }
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($messageText, $triggeringCommand)) {
                $preferenceValue = trim(str_replace($triggeringCommand, '', $messageText));
                $triggerFound = true;
                break;
            }
        }
        if (!$triggerFound) {
            return new ProcessingResult(null, false);
        }
        $preferenceValue = $this->transformValue($preferenceValue);
        if (!$this->processValueAsSetting($lastMessage, $preferenceValue)) {
            return new ProcessingResult(null, true);
        }
        $validationErrors = $this->getValueValidationErrors($preferenceValue);
        if (count($validationErrors) > 0) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    "Ошибка: " . implode(
                        "\n",
                        $validationErrors,
                    )
                ),
                true,
            );
        }
        $this->database->writeUserPreference($lastMessage->userId, $this->preferenceName, $preferenceValue);

        try {
            $response = Request::execute('setMessageReaction', [
                'chat_id'    => $lastMessage->chatId,
                'message_id' => $lastMessage->id,
                'reaction'   => [[
                    'type'  => 'emoji',
                    'emoji' => '👌',
                ]],
                'is_big' => true,
            ]);
            echo "Reacting to message result: $response\n";
        } catch (Exception $e) {
            echo("Failed to react to message: " . $e->getMessage() . "\n");

            $messageText =  $preferenceValue === null ? "Настройка $this->preferenceName сброшена в состояние по умолчанию" : "Настройка $this->preferenceName установлена в $preferenceValue";
            return new ProcessingResult(InternalMessage::asResponseTo($lastMessage, $messageText), true);
        }

        return new ProcessingResult(null, true);
    }

    public function getCurrentPreferenceValue(int $userId): ?string
    {
        return $this->database->readUserPreference($userId, $this->preferenceName);
    }

    public function getTriggeringCommands(): array
    {
        return $this->triggeringCommands;
    }
}
