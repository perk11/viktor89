<?php

namespace Perk11\Viktor89;


use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;

class Engine
{
    private array $tutors = [
        'https://cloud.nw-sys.ru/index.php/s/z97QnXmfcM8QKDn/download',
        'https://cloud.nw-sys.ru/index.php/s/xqpNxq6Akk6SbDX/download',
        'https://cloud.nw-sys.ru/index.php/s/eCkqzWGqGAFRjMQ/download',
        'https://cloud.nw-sys.ru/index.php/s/wXeDasYwe44FaBx/download',
        'https://cloud.nw-sys.ru/index.php/s/7cNH875Dq2HpFWH/download',
        'https://cloud.nw-sys.ru/index.php/s/QatfCjzHn7ae5t2/download'
    ];

    private array $messageTypesSupportedByCommonCode = [
        'command',
        'text',
        'new_chat_members',
        'poll',
        'voice',
    ];

    public function __construct(
        private readonly ?PhotoImg2ImgProcessor $photoImg2ImgProcessor,
        private readonly Database $database,
        private readonly HistoryReader $historyReader,
        /** @var PreResponseProcessor[] */
        private readonly array $preResponseProcessors,
        private readonly string $telegramBotUserName,
        private readonly int $telegramBotId,
        private readonly TelegramInternalMessageResponderInterface|TelegramChainBasedResponderInterface $fallBackResponder,
    ) {
    }

    public function handleMessage(Message $message): void
    {
        if ($this->photoImg2ImgProcessor !== null && $message->getType() === 'photo') {
            $this->photoImg2ImgProcessor->processMessage($message);

            return;
        }

        if (!in_array($message->getType(), $this->messageTypesSupportedByCommonCode, true)) {
            echo "Message of type {$message->getType()} received\n";
            if ($message->getType() === 'sticker') {
                echo $message->getSticker()->getFileId() . "\n";
            }

            return;
        }

        if ($message->getType() === 'new_chat_members') {
            echo "New member detected, sending tutorial\n";
            Request::sendVideo([
                                   'chat_id'             => $message->getChat()->getId(),
                                   'reply_to_message_id' => $message->getMessageId(),
                                   'video'               => $this->tutors[array_rand($this->tutors)],
                               ]);

            return;
        }

        if ($message->getFrom() === null) {
            echo "Message without a sender received\n";

            return;
        }
        $this->database->logMessage($message);
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
                    $this->database->logMessage($response->getResult());
                } else {
                    echo "Failed to send message: ";
                    print_r($response->getRawData());
                    echo "\n";
                }
                echo get_class($preResponseProcessor) . " processor handled the message and returned response\n";

                return;
            }
        }

        $incomingMessageText = $message->getText();

        if ($message->getType() !== 'command') {
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
        if ($this->fallBackResponder instanceof TelegramChainBasedResponderInterface) {
            $chain = $this->historyReader->getPreviousMessages($message, 9, 9, 0);
            $chain = array_values(array_merge($chain, [InternalMessage::fromTelegramMessage($message)]));
            $responseMessage = $this->fallBackResponder->getResponseByMessageChain($chain);
        } else {
            $responseMessage = $this->fallBackResponder->getResponseByMessage($message);
        }

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
            $this->database->logInternalMessage($responseMessage);
        } else {
            echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
        }
    }
}
