<?php

namespace Perk11\Viktor89\PreResponseProcessor;


use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\Quiz\Question;
use Perk11\Viktor89\Quiz\QuestionAnswer;
use Perk11\Viktor89\Quiz\QuestionRepository;

class SaveQuizPollProcessor implements PreResponseProcessor
{
    public function __construct(private readonly QuestionRepository $questionRepository)
    {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getType() !== 'poll') {
            return false;
        }
        echo "New poll received\n";
        $poll = $message->getPoll();
        if ($poll->getCorrectOptionId() === null) {
            echo "Poll does not have a correct answer, not doing anything\n";
            if ($message->getChat()->isPrivateChat()) {
                return 'Для того чтобы прислать свой вопрос, пожалуйста пришлите мне опрос виде quiz. Опрос который вы прислали не содержит правильного ответа.';
            }
            return false;
        }
        $answers = [];
        foreach ($poll->getOptions() as $option) {
            $answers[] = new QuestionAnswer($option->getText(), count($answers) === $poll->getCorrectOptionId());
        }
        $userName = $message->getFrom()->getFirstName();
        if ($message->getFrom()->getLastName() !== null) {
            $userName .= ' ' . $message->getFrom()->getLastName();
        }
        $question = new Question($poll->getQuestion(), $answers, $message->getFrom()->getId(), $userName);
        $question->explanation = $poll->getExplanation();
        $this->questionRepository->save($question);

        return 'Ваш вопрос был добавлен в базу!';
    }

}
