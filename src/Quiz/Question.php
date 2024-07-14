<?php

namespace Perk11\Viktor89\Quiz;

class Question
{
    public int $id;
    public int $addedAt;
    public ?string $explanation;
    public function __construct(
        public readonly string $text,
        /** @var QuestionAnswer[] $answers */
        public readonly array $answers,
        public readonly int $addedByUserId,
        public readonly string $addedByUserName,
        public readonly string $namespace = 'siepatchdb',
    )
    {
    }

    public static function fromSqliteAssocAnswersArray(array $answersArray): self
    {
        $answers = [];
        foreach ($answersArray as $answerAssoc) {
            $answers[] = QuestionAnswer::fromSqliteAssocAnswersArray($answerAssoc);
        }
        $firstAnswerAssoc = $answersArray[0];
        $question = new self($firstAnswerAssoc['question_text'], $answers, $firstAnswerAssoc['added_by_user_id'], $firstAnswerAssoc['added_by_user_name'], $firstAnswerAssoc['namespace']);
        $question->id = $firstAnswerAssoc['question_id'];
        $question->addedAt = (int) $firstAnswerAssoc['added_at'];
        $question->explanation = $firstAnswerAssoc['explanation'];

        return $question;
    }

    public function getTextWithAuthor(): string
    {
        $text = $this->text;
        if ($this->addedByUserName !== null) {
            $text = "Вопрос от  " . $this->addedByUserName .": $text";
        }
        return $text;
    }
}
