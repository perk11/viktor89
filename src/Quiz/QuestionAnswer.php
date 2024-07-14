<?php

namespace Perk11\Viktor89\Quiz;

class QuestionAnswer
{
    public int $id;
    public function __construct(public readonly string $text, public readonly bool $correct)
    {
    }

    public static function fromSqliteAssocAnswersArray(array $assocArray): self
    {
        $answer = new self($assocArray['answer_text'], $assocArray['correct']);
        $answer->id = $assocArray['question_id'];

        return $answer;
    }
}
