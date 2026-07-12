<?php

namespace Perk11\Viktor89;

use LogicException;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Repository\MessageRepository;

class ProcessingResultExecutor
{
    public function __construct(
        private MessageRepository $messageRepository,
        private bool $repliesInPMs = true,
        /**
         * Invoked immediately before a response message is sent or edited.
         * In a worker this notifies the main process to stop sending typing
         * notifications and drafts for the chat, so that none can appear after
         * the actual message.
         */
        private readonly ?\Closure $beforeMessageSentNotifier = null,
        private readonly ?MessageMetadataRepository $messageMetadataRepository = null,
    ) {
        
    }
    public function execute(ProcessingResult $result): void
    {
        if (!$this->repliesInPMs && $result->response !== null && $result->response->chatId > 0 && $result->response->replyToMessageId !== null) {
            $result->response->replyToMessageId = null;
        }
        if ($result->response !== null) {
            if ($this->beforeMessageSentNotifier !== null) {
                ($this->beforeMessageSentNotifier)($result->response->chatId);
            }
            if ($result->response->id === null) {
                echo "Sending message in chat {$result->response->chatId}: {$result->response->messageText}\n";

                $telegramServerResponse = $result->response->send();
                if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                    InternalMessage::extractPropertiesFromTelegramMessage($result->response, $telegramServerResponse->getResult());
                    $this->messageRepository->logInternalMessage($result->response);
                    $this->persistMetadata($result->response);
                } else {
                    echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
                }
            } else {
                echo "Editing message in chat {$result->response->chatId}\n";
                $telegramServerResponse = $result->response->edit($result->response->messageText);
                if ($telegramServerResponse->isOk()) {
                    $this->messageRepository->logInternalMessage($result->response);
                    $this->persistMetadata($result->response);
                } else {
                    echo "Failed to edit response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
                }
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

    private function persistMetadata(InternalMessage $response): void
    {
        if ($this->messageMetadataRepository === null || $response->id === null) {
            return;
        }
        if ($response->model === null && $response->systemPrompt === null && $response->personaId === null && $response->caption === null) {
            return;
        }
        $this->messageMetadataRepository->upsert(new MessageMetadata(
            $response->chatId,
            $response->id,
            $response->model,
            $response->systemPrompt,
            $response->personaId,
            $response->caption,
        ));
    }
}
