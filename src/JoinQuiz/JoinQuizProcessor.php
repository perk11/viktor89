<?php

namespace Perk11\Viktor89\JoinQuiz;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PollOption;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
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
        Request::sendPhoto([
                               'chat_id'          => $message->getChat()->getId(),
                               'reply_parameters' => [
                                   'message_id' => $message->getMessageId(),
                               ],
                               'photo'            => Request::encodeFile(__DIR__ . '/quiz_photo_1.jpg'),
                           ]);
        foreach ($message->getNewChatMembers() as $member) {
            $pollData = [
                'question'            => "Вопрос для " . $member->getFirstName(
                    ) . ". Какой телефон Siemens изображена на фото? Which Siemens phone model is in this photo?",
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
                'open_period' => self::SECONDS_UNTIL_KICK + 1,
                'is_anonymous'        => false,
            ];
            $pollResponse = Request::sendPoll($pollData);
            if (!$pollResponse->isOk()) {
                echo "Failed to send a join poll\n";

                return false;
            }
            print_r($pollResponse);
            $this->database->insertKickQueueItem(
                new KickQueueItem(
                    $message->getChat()->getId(),
                    $member->getId(),
                    $pollResponse->getResult()->getPoll()->getId(),
                    $message->getMessageId(),
                    time() + self::SECONDS_UNTIL_KICK,
                )
            );
        }
        sleep(1);

        return
            'Уважаемый ' . $message->getNewChatMembers()[0]->getFirstName(
            ) . ', добро пожаловать в наш чат! Чтобы стать полноценным членом нашего сообщества, пожалуйста, пройдите опрос и представтесь. В противном случае, вас удалят из чата.';
    }
}
