<?php

namespace Perk11\Viktor89\JoinQuiz;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PollOption;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use Perk11\Viktor89\Repository\KickQueueRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class JoinQuizProcessor implements PreResponseProcessor
{
    private const SECONDS_UNTIL_KICK = 300;
    public const CORRECT_ANSWER_INDEX = 2;

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly KickQueueRepository $kickQueueRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getType() !== 'new_chat_members') {
            return false;
        }
        $this->logger->log(LogLevel::INFO, 'New member detected, sending quiz');
        $this->logger->log(LogLevel::DEBUG, print_r($message, true));
        $messagesToDelete = [];
        $chatId = $message->getChat()->getId();
        $photoParams = [
            'chat_id'          => $chatId,
            'reply_parameters' => ['message_id' => $message->getMessageId()],
            'photo'            => Request::encodeFile(__DIR__ . '/quiz_photo_1.jpg'),
        ];
        // The captcha photo is only useful to the joining member, so send it
        // ephemerally when the bot is an admin (sendPoll doesn't support
        // receiver_user_id, so the poll itself stays public). Ephemeral messages
        // self-destruct and are not queued for deletion; a public fallback is.
        $photoEphemeral = BotAdminChecker::isBotAdminInChat($chatId);
        if ($photoEphemeral) {
            $photoParams['receiver_user_id'] = $message->getNewChatMembers()[0]->getId();
        }
        $photoResponse = Request::sendPhoto($photoParams);
        if ($photoEphemeral && !$photoResponse->isOk()) {
            $this->logger->log(LogLevel::WARNING, "Ephemeral join-quiz photo failed ({$photoResponse->getDescription()}), retrying as a regular message");
            unset($photoParams['receiver_user_id']);
            $photoResponse = Request::sendPhoto($photoParams);
            $photoEphemeral = false;
        }
        if (!$photoResponse->isOk()) {
            $this->logger->log(LogLevel::ERROR, 'Failed to send photo!');
            $this->logger->log(LogLevel::DEBUG, print_r($photoResponse, true));
            return false;
        }
        if (!$photoEphemeral) {
            $messagesToDelete[] = $photoResponse->getResult()->getMessageId();
        }
        foreach ($message->getNewChatMembers() as $member) {
            $pollData = [
                'question'            => "Вопрос для " . $member->getFirstName(
                    ) . ". Какой телефон Siemens изображен на фото? Which Siemens phone model is in this photo?",
                'chat_id'             => $message->getChat()->getId(),
                'reply_to_message_id' => $message->getMessageId(),
                'options'             => [
                    new PollOption([
                                       'text' => 'A55',
                                   ]),
                    new PollOption([
                                       'text' => 'M55',
                                   ]),
                    new PollOption([
                                       'text' => 'S75',
                                   ]),
                    new PollOption([
                                       'text' => 'EL71',
                                   ]),
                ],
                'type'                => 'quiz',
                'correct_option_id'   => 2,
                'is_anonymous'        => false,
            ];
            $pollResponse = Request::sendPoll($pollData);
            if (!$pollResponse->isOk()) {
                $this->logger->log(LogLevel::ERROR, 'Failed to send a join poll');
                $this->logger->log(LogLevel::DEBUG, print_r($pollResponse, true));

                return false;
            }
            $messagesToDelete[] = $pollResponse->getResult()->getMessageId();
            $this->logger->log(LogLevel::DEBUG, print_r($pollResponse, true));

            sleep(1);
            $newChatMember = $message->getNewChatMembers()[0];
            $questionMessage = new InternalMessage();
            $questionMessage->chatId = $message->getChat()->getId();
            $questionMessage->replyToMessageId = $message->getMessageId();
            $questionMessage->messageText =  'Уважаемый(-ая) ' . $newChatMember->getFirstName() . ', добро пожаловать в наш чат! Чтобы стать полноценным членом нашего сообщества, пожалуйста, пройдите опрос и представтесь. В противном случае, вас удалят из чата.';
            // The welcome instructions are addressed to the new member only -> ephemeral.
            $questionMessage->receiverUserId = $member->getId();
            $telegramServerResponse = $questionMessage->send();
            if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                $this->messageRepository->logInternalMessage($questionMessage);
                // Ephemeral messages self-destruct, so only a public (fallback) send
                // needs to be queued for explicit deletion later.
                if ($questionMessage->receiverUserId === null) {
                    $messagesToDelete[] = $questionMessage->id;
                }
            } else {
                $this->logger->log(LogLevel::ERROR, 'Failed to send response: ' . print_r($telegramServerResponse->getRawData(), true));
            }
            $this->kickQueueRepository->insertKickQueueItem(
                new KickQueueItem(
                    $message->getChat()->getId(),
                    $member->getId(),
                    $pollResponse->getResult()->getPoll()->getId(),
                    $message->getMessageId(),
                    $messagesToDelete,
                    time() + self::SECONDS_UNTIL_KICK,
                )
            );
        }
        sleep(1);

        return false;
    }
}
