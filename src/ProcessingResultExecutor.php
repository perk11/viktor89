<?php

namespace Perk11\Viktor89;

use LogicException;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class ProcessingResultExecutor
{
    public function __construct(private Database $database)
    {
        
    }
    public function execute(ProcessingResult $result): void
    {
        if ($result->response !== null) {
            echo "Sending message in chat {$result->response->chatId}: {$result->response->messageText}\n";

            $telegramServerResponse = $result->response->send();
            if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                $this->database->logMessage($telegramServerResponse->getResult());
            } else {
                echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
            }
        }

        if ($result->reaction !== null) {
            if ($result->messageToReactTo === null) {
                throw new LogicException("Reaction property is set, but not messageToReactTo");
            }
            echo "Reacting to message from {$result->messageToReactTo->userName} in chat {$result->messageToReactTo->chatId} \n";
            Request::execute('setMessageReaction', [
                'chat_id'    => $result->messageToReactTo->chatId,
                'message_id' => $result->messageToReactTo->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => $result->reaction,
                    ],
                ],
            ]);
        }
        if ($result->callback !== null) {
            call_user_func($result->callback);
        }
    }
}
