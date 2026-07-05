<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Quiz\QuestionAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionAnswer::class)]
class QuestionAnswerTest extends TestCase
{
    public function testConstructorWithCorrectAnswer(): void
    {
        $answer = new QuestionAnswer('42', true);

        $this->assertSame('42', $answer->text);
        $this->assertTrue($answer->correct);
    }

    public function testConstructorWithIncorrectAnswer(): void
    {
        $answer = new QuestionAnswer('Wrong', false);

        $this->assertSame('Wrong', $answer->text);
        $this->assertFalse($answer->correct);
    }

    public function testConstructorWithEmptyText(): void
    {
        $answer = new QuestionAnswer('', true);

        $this->assertSame('', $answer->text);
        $this->assertTrue($answer->correct);
    }

    public function testFromSqliteAssocAnswersArray(): void
    {
        $assoc = [
            'answer_text' => 'Paris',
            'correct' => true,
            'question_id' => 5,
        ];

        $answer = QuestionAnswer::fromSqliteAssocAnswersArray($assoc);

        $this->assertSame('Paris', $answer->text);
        $this->assertTrue($answer->correct);
        $this->assertSame(5, $answer->id);
    }

    public function testFromSqliteAssocAnswersArrayWithIncorrect(): void
    {
        $assoc = [
            'answer_text' => 'London',
            'correct' => false,
            'question_id' => 10,
        ];

        $answer = QuestionAnswer::fromSqliteAssocAnswersArray($assoc);

        $this->assertFalse($answer->correct);
        $this->assertSame(10, $answer->id);
    }

    public function testIdSettable(): void
    {
        $answer = new QuestionAnswer('Test', false);
        $answer->id = 99;

        $this->assertSame(99, $answer->id);
    }
}
