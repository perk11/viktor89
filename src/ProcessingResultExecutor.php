<?php

namespace Perk11\Viktor89;

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
            $telegramServerResponse = $result->response->send();
            if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                $result->response->id = $telegramServerResponse->getResult()->getMessageId();
                $result->response->userId = $telegramServerResponse->getResult()->getFrom()->getId();
                $result->response->userName = $telegramServerResponse->getResult()->getFrom()->getUsername();
                $result->response->date = time();
                $this->database->logInternalMessage($result->response);
            } else {
                echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
            }
        }

        if ($result->reaction !== null) {
            if ($result->messageToReactTo === null) {
                throw new \LogicException("Reaction property is set, but not messageToReactTo");
            }
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
