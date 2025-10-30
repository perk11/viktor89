<?php

namespace Perk11\Viktor89\Quiz;

use Exception;
use Longman\TelegramBot\Entities\PollOption;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class RandomQuizResponder implements MessageChainProcessor
{

    public function __construct(private readonly QuestionRepository $questionRepository)
    {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        $question = $this->questionRepository->findRandom();
        if ($question === null) {
            echo "Failed to find a random question\n";

            return new ProcessingResult(null, true);
        }
        $options = [];
        $answerIndex = 0;
        $answers = $question->answers;
        shuffle($answers);
        foreach ($answers as $answer) {
            $options[] = new PollOption([
                                            'text' => $answer->text,
                                        ]);
            if ($answer->correct) {
                $correctAnswerIndex = $answerIndex;
            }
            $answerIndex++;
        }
        if (!isset($correctAnswerIndex)) {
            throw new Exception("Question " . $question->id . " does not have a correct answer");
        }
        $pollData = [
            'question'            => $question->getTextWithAuthor() . "\n\nЧтобы добавить свой вопрос, присылайте quiz-опрос мне в лс!",
            'chat_id'             => $lastMessage->chatId,
            'reply_to_message_id' => $lastMessage->id,
            'options'             => $options,
            'type'                => 'quiz',
            'correct_option_id'   => $correctAnswerIndex,
            'is_anonymous'        => false,
        ];
        if ($question->explanation !== null) {
            $pollData['explanation'] = $question->explanation;
        }
        Request::sendPoll($pollData);
        return new ProcessingResult(null, true);
    }
}
