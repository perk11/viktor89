<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class ProcessingResult
{
    private $callback;
    public function __construct(
        public readonly ?InternalMessage $response,
        public readonly bool $abortProcessing,
        public readonly ?string $reaction = null,
        public readonly ?InternalMessage $messageToReactTo = null,
        callable $callback = null,
    )
    {
        $this->callback = $callback;
    }

    public function execute(Database $database): void
    {
        if ($this->response !== null) {
            $telegramServerResponse = $this->response->send();
            if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                $this->response->id = $telegramServerResponse->getResult()->getMessageId();
                $this->response->userId = $telegramServerResponse->getResult()->getFrom()->getId();
                $this->response->userName = $telegramServerResponse->getResult()->getFrom()->getUsername();
                $this->response->date = time();
                $database->logInternalMessage($this->response);
            } else {
                echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
            }
        }

        if ($this->reaction !== null) {
            if ($this->messageToReactTo === null) {
                throw new \LogicException("Reaction property is set, but not messageToReactTo");
            }
            Request::execute('setMessageReaction', [
                'chat_id'    => $this->messageToReactTo->chatId,
                'message_id' => $this->messageToReactTo->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => $this->reaction,
                    ],
                ],
            ]);
        }
        if ($this->callback !== null) {
            call_user_func($this->callback);
        }
    }
}
