<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class CommandBasedResponderTrigger implements PreResponseProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly bool $responsesAlsoTrigger,
        private readonly Database $database,
        private readonly TelegramChainBasedResponderInterface $responder,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        $chain = array_values(array_merge($this->getPreviousMessages($message), [InternalMessage::fromTelegramMessage($message)]));
        if (!$this->responsesAlsoTrigger && count($chain) > 1) {
            return false;
        }
        $firstMessageText = $chain[0]->messageText;
        $triggerFound = false;
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($firstMessageText, $triggeringCommand)) {
                $triggerFound = true;
                $chain[0]->messageText = trim(str_replace($triggeringCommand, '', $firstMessageText));
                break;
            }
        }
        if (!$triggerFound) {
            return false;
        }
        //todo: rework PreResponseProcessor interface to accept message instead

        Request::sendChatAction([
                                    'chat_id' => $message->getChat()->getId(),
                                    'action'  => ChatAction::TYPING,
                                ]);

        try {
            $responseMessage = $this->responder->getResponseByMessageChain($chain);
        } catch (\Exception $e) {
            echo "Got error when getting response to message chain from " . get_class($this->responder) .": \n";
            echo $e->getMessage();
            echo $e->getTraceAsString();
            Request::execute('setMessageReaction', [
                'chat_id'    => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
            return null;
        }
        if ($responseMessage === null) {
             return null;
        }
        $response = $responseMessage->send();
        if ($response->isOk()) {
            $this->database->logMessage($response->getResult());
        } else {
            echo "Failed to send message in CommandBaseResponderTrigger: ";
            print_r($response->getRawData());
            echo "\n";
            Request::execute('setMessageReaction', [
                'chat_id'    => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }

        return null;
    }

    /** @return InternalMessage[] */
    private function getPreviousMessages(Message $message): array
    {
        $messages = [];
        if ($message->getReplyToMessage() !== null) {
            $responseMessage = $this->database->findMessageByIdInChat(
                $message->getReplyToMessage()->getMessageId(),
                $message->getChat()->getId()
            );
            if ($responseMessage !== null) {
                $messages[] = $responseMessage;
                while ($responseMessage?->replyToMessageId !== null) {
                    $responseMessage = $this->database->findMessageByIdInChat(
                        $responseMessage->replyToMessageId,
                        $message->getChat()->getId()
                    );
                    if ($responseMessage === null) {
                        $previousMessage = $messages[count($messages) - 1];
                        echo sprintf(
                            "Reference to message %s in chat %s not found in database in current response chain, skipping\n",
                            $previousMessage->replyToMessageId,
                            $previousMessage->chatId,
                        );
                        continue;
                    }
                    $messages[] = $responseMessage;
                }
            }
        }


        return array_reverse($messages);
    }
}
