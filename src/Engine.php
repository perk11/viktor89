<?php

namespace Perk11\Viktor89;


use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use Perk11\Viktor89\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handleMessage(Message $message): void
    {
        $this->messageRepository->logMessage($message);

        if (!in_array($message->getType(), $this->messageTypesSupportedByCommonCode, true)) {
            $this->logger->log(LogLevel::INFO, "Message of type {$message->getType()} received");
            return;
        }

        if ($message->getFrom() === null) {
            $this->logger->log(LogLevel::INFO, 'Message without a sender received');

            return;
        }
        foreach ($this->preResponseProcessors as $preResponseProcessor) {
            $replacedMessage = $preResponseProcessor->process($message);
            if ($replacedMessage !== false) {
                if ($replacedMessage === null) {
                    $this->logger->log(LogLevel::INFO, get_class($preResponseProcessor) . ' processor handled the message and returned null');

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
                    $this->logger->log(LogLevel::ERROR, 'Failed to send message: ' . print_r($response->getRawData(), true));
                }
                $this->logger->log(LogLevel::INFO, get_class($preResponseProcessor) . ' processor handled the message and returned response');

                return;
            }
        }


        $lastMessage = InternalMessage::fromTelegramMessage($message);
        if ($message->getReplyToMessage() !== null) {
            $priorMessages = $this->historyReader->getPreviousMessages($message, 999, 999, 0, $this->telegramBotId);
            if (count($priorMessages) > 0) {
                // Enrich the exact message the user replied to with the fresh
                // Telegram data from the update (it may carry a photo file id,
                // alt text, etc. not persisted in the DB). Sibling bot messages
                // pulled into the chain can sit after the replied-to message, so
                // locate it by id rather than assuming it is the last element.
                $replyTargetId = $message->getReplyToMessage()->getMessageId();
                foreach ($priorMessages as $priorMessage) {
                    if ($priorMessage->id === $replyTargetId) {
                        InternalMessage::extractPropertiesFromTelegramMessage(
                            $priorMessage,
                            $message->getReplyToMessage()
                        );
                        break;
                    }
                }
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

        // Any command that reached this point was not recognised by a processor,
        // so it is ignored rather than forwarded to the model. (Real commands —
        // including /assistant and its reply chains — are handled above by the
        // chain processors.)
        if ($message->getType() === 'command') {
            return;
        }

        $isPrivateChat = $message->getChat()->getType() === 'private';
        if ($isPrivateChat) {
            // In private chats the assistant answers every non-command message.
            $recentMessages = $this->messageRepository->findNPreviousMessagesInChat(
                $message->getChat()->getId(),
                $message->getMessageId(),
                100,
                [],
            );
            $chain = new MessageChain(array_merge(array_reverse($recentMessages), [$lastMessage]));
        } elseif (
            !str_contains($message->getText() ?? '', '@' . $this->telegramBotUserName)
            && ($message->getReplyToMessage() === null
                || $message->getReplyToMessage()?->getFrom()?->getId() !== $this->telegramBotId)
        ) {
            // Group chat: respond only when the bot is mentioned or replied to.
            return;
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
            $this->logger->log(LogLevel::INFO, 'Null response returned by fallback responder');

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
            $this->logger->log(LogLevel::ERROR, 'Failed to send response: ' . print_r($telegramServerResponse->getRawData(), true));
        }
    }
}
