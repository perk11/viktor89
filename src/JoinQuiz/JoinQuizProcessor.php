<?php

namespace Perk11\Viktor89\JoinQuiz;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PollOption;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;

class JoinQuizProcessor implements PreResponseProcessor
{
    private const SECONDS_UNTIL_KICK = 300;
    public const CORRECT_ANSWER_INDEX = 2;

    public function __construct(private readonly Database $database)
    {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getType() !== 'new_chat_members') {
            return false;
        }
        echo "New member detected, sending quiz\n";
        print_r($message);
        $messagesToDelete = [];
        $photoResponse = Request::sendPhoto([
                               'chat_id'          => $message->getChat()->getId(),
                               'reply_parameters' => [
                                   'message_id' => $message->getMessageId(),
                               ],
                               'photo'            => Request::encodeFile(__DIR__ . '/quiz_photo_1.jpg'),
                           ]);
        if (!$photoResponse->isOk()) {
            echo "Failed to send photo!";
            print_r($photoResponse);
            return false;
        }
        $messagesToDelete[] = $photoResponse->getResult()->getMessageId();
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
                echo "Failed to send a join poll\n";
                print_r($pollResponse);

                return false;
            }
            $messagesToDelete[] = $pollResponse->getResult()->getMessageId();
            print_r($pollResponse);

            sleep(1);
            $newChatMember = $message->getNewChatMembers()[0];
            $questionMessage = new InternalMessage();
            $questionMessage->chatId = $message->getChat()->getId();
            $questionMessage->replyToMessageId = $message->getMessageId();
            $questionMessage->messageText =  'Уважаемый(-ая) ' . $newChatMember->getFirstName() . ', добро пожаловать в наш чат! Чтобы стать полноценным членом нашего сообщества, пожалуйста, пройдите опрос и представтесь. В противном случае, вас удалят из чата.';
            $telegramServerResponse = $questionMessage->send();
            if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                $this->database->logMessage($telegramServerResponse->getResult());
                $messagesToDelete[] = $telegramServerResponse->getResult()->getMessageId();
            } else {
                echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
            }
            $this->database->insertKickQueueItem(
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
