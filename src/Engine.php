<?php

namespace Perk11\Viktor89;


use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use Perk11\Viktor89\Repository\MessageRepository;

class Engine
{
    private array $messageTypesSupportedByCommonCode = [
        'command',
        'text',
        'new_chat_members',
        'poll',
        'photo',
        'voice',
        'video',
        'audio',
        'video_note',
        'sticker',
    ];

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly HistoryReader $historyReader,
        /** @var PreResponseProcessor[] $preResponseProcessors */
        private readonly array $preResponseProcessors,
        private readonly MessageChainProcessorRunner $messageChainProcessorRunner,
        private readonly string $telegramBotUserName,
        private readonly int $telegramBotId,
        private readonly TelegramInternalMessageResponderInterface|MessageChainProcessor $fallBackResponder,
        private readonly ProgressUpdateCallback $progressUpdateCallback,
        private readonly ProcessingResultExecutor $processingResultExecutor,
    ) {
    }

    public function handleMessage(Message $message): void
    {
        $this->messageRepository->logMessage($message);

        if (!in_array($message->getType(), $this->messageTypesSupportedByCommonCode, true)) {
            echo "Message of type {$message->getType()} received\n";
            return;
        }

        if ($message->getFrom() === null) {
            echo "Message without a sender received\n";

            return;
        }
        foreach ($this->preResponseProcessors as $preResponseProcessor) {
            $replacedMessage = $preResponseProcessor->process($message);
            if ($replacedMessage !== false) {
                if ($replacedMessage === null) {
                    echo get_class($preResponseProcessor) . " processor handled the message and returned null\n";

                    return;
                }
                $internalMessage = new InternalMessage();
                $internalMessage->chatId = $message->getChat()->getId();
                $internalMessage->replyToMessageId = $message->getMessageId();
                $internalMessage->userName = $this->telegramBotUserName;
                $internalMessage->messageText = $replacedMessage;

                $response = $internalMessage->send();
                if ($response->isOk()) {
                    $this->messageRepository->logMessage($response->getResult());
                } else {
                    echo "Failed to send message: ";
                    print_r($response->getRawData());
                    echo "\n";
                }
                echo get_class($preResponseProcessor) . " processor handled the message and returned response\n";

                return;
            }
        }


        $lastMessage = InternalMessage::fromTelegramMessage($message);
        if ($message->getReplyToMessage() !== null) {
            $priorMessages = $this->historyReader->getPreviousMessages($message, 999, 999, 0);
            if (count($priorMessages) > 0) {
                InternalMessage::extractPropertiesFromTelegramMessage(
                    array_last($priorMessages),
                    $message->getReplyToMessage()
                );
            } else {
                $priorMessages = [InternalMessage::fromTelegramMessage($message->getReplyToMessage())];
            }
            $chain = new MessageChain(array_merge($priorMessages, [$lastMessage]));
        } else {
            $chain = new MessageChain([$lastMessage]);
        }
        if ($this->messageChainProcessorRunner->run($chain, $this->progressUpdateCallback)) {
            return;
        }

        $isPrivateChat = $message->getChat()->getType() === 'private';
        if ($isPrivateChat) {
            // In private chats the assistant answers every non-command message.
            // Commands that no processor recognised are ignored so they are not
            // forwarded to the model.
            if ($message->getType() === 'command') {
                return;
            }
            $recentMessages = $this->messageRepository->findNPreviousMessagesInChat(
                $message->getChat()->getId(),
                $message->getMessageId(),
                100,
                [],
            );
            $chain = new MessageChain(array_merge(array_reverse($recentMessages), [$lastMessage]));
        } elseif ($message->getType() !== 'command') {
            $incomingMessageText = $message->getText();
            if (!str_contains($incomingMessageText, '@' . $this->telegramBotUserName)) {
                $replyToMessage = $message->getReplyToMessage();
                if ($replyToMessage === null) {
                    return;
                }
                if ($replyToMessage->getFrom()->getId() !== $this->telegramBotId) {
                    return;
                }
            }
        }
        if ($this->fallBackResponder instanceof MessageChainProcessor) {
            // Route through ProcessingResultExecutor so streaming assistants that
            // already sent/edited their message during generation are not sent a
            // second time, and drafts/typing are stopped via the pre-send
            // handshake — identical to how /assistant responses are dispatched.
            $this->processingResultExecutor->execute(
                $this->fallBackResponder->processMessageChain($chain, $this->progressUpdateCallback)
            );

            return;
        }

        $responseMessage = $this->fallBackResponder->getResponseByMessage($message, $this->progressUpdateCallback);
        if ($responseMessage === null) {
            echo "Null response returned by fallback responder\n";

            return;
        }
        $telegramServerResponse = $responseMessage->send();
        if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
            $responseMessage->id = $telegramServerResponse->getResult()->getMessageId();
            $responseMessage->chatId = $message->getChat()->getId();
            $responseMessage->userId = $telegramServerResponse->getResult()->getFrom()->getId();
            $responseMessage->date = time();
            $responseMessage->type='text';
            $this->messageRepository->logInternalMessage($responseMessage);
        } else {
            echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
        }
    }
}
