<?php

namespace Perk11\Viktor89\Quiz;

use Perk11\Viktor89\Database;

class QuestionRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(Question $question): void
    {
        $correctAnswerExists = false;
        foreach ($question->answers as $answer) {
            if ($answer->correct) {
                $correctAnswerExists = true;
                break;
            }
        }
        if (!$correctAnswerExists) {
            throw new \LogicException('Trying to save a question without a correct answer');
        }
        $insertQuestionStatement = $this->database->sqlite3Database->prepare(
            'INSERT INTO quiz_question (
                           namespace, 
                           added_at,
                           added_by_user_id,
                           added_by_user_name,
                           text,
                           explanation
                           ) VALUES (
                                     :namespace,
                                     CURRENT_TIMESTAMP,
                                     :userId,
                                     :userName,
                                     :text,
                                     :explanation
                                     )'
        );
        $insertQuestionStatement->bindValue(':namespace', $question->namespace);
        $insertQuestionStatement->bindValue(':userId', $question->addedByUserId, SQLITE3_INTEGER);
        $insertQuestionStatement->bindValue(':userName', $question->addedByUserName);
        $insertQuestionStatement->bindValue(':text',  $question->text);
        $insertQuestionStatement->bindValue(':explanation',  $question->explanation);
        $answerInserted = $insertQuestionStatement->execute();
        if (!$answerInserted) {
            throw new \Exception('Failed to save question');
        }
        $question->id = $this->database->sqlite3Database->lastInsertRowID();

        $insertAnswerStatement = $this->database->sqlite3Database->prepare(
            'INSERT INTO quiz_question_answer (question_id, text, correct) VALUES (:questionId, :text, :correct)'
        );
        foreach ($question->answers as $answer) {
            $insertAnswerStatement->bindValue(':questionId', $question->id);
            $insertAnswerStatement->bindValue('text', $answer->text);
            $insertAnswerStatement->bindValue('correct', $answer->correct, SQLITE3_INTEGER);
            $insertAnswerStatement->execute();
        }
    }

    public function findById(int $id): ?Question
    {
        $sql = self::buildSelectSQL();
        $sql .= ' WHERE id=:id';
        $statement = $this->database->sqlite3Database->prepare($sql);
        $statement->bindValue(':id', $id, SQLITE3_INTEGER);

        return $this->readByStatement($statement);
    }

    private function readByStatement(\SQLite3Stmt $statement): ?Question
    {
        $result = $statement->execute();
        $answers = [];
        while ($answer =  $result->fetchArray(SQLITE3_ASSOC)) {
            $answers[] = $answer;
        }
        if (count($answers) === 0) {
            return null;
        }
        return Question::fromSqliteAssocAnswersArray($answers);
    }

    private static function buildSelectSQL(): string
    {
        return '
        SELECT quiz_question.id as question_id,
        quiz_question.namespace as namespace,
        quiz_question.added_at as added_at,
        quiz_question.added_by_user_id as added_by_user_id,
        quiz_question.added_by_user_name as added_by_user_name,
        quiz_question.text as question_text,
        quiz_question.explanation as explanation,
        
        quiz_question_answer.id as answer_Id
        quiz_question_answer.text as answer_text
        quiz_question_answer.correct as correct
        
        FROM quiz_question
        JOIN quiz_question_answer ON quiz_question.id = quiz_question_answer.question_id
        ';
    }
}
