<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Quiz\Question;
use Perk11\Viktor89\Quiz\QuestionAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Question::class)]
class QuestionTest extends TestCase
{
    public function testConstructorWithAllFields(): void
    {
        $answers = [new QuestionAnswer('Answer A', true), new QuestionAnswer('Answer B', false)];
        $question = new Question('What is 2+2?', $answers, 123, 'User123', 'default');

        $this->assertSame('What is 2+2?', $question->text);
        $this->assertCount(2, $question->answers);
        $this->assertSame(123, $question->addedByUserId);
        $this->assertSame('User123', $question->addedByUserName);
        $this->assertSame('default', $question->namespace);
    }

    public function testConstructorWithCustomNamespace(): void
    {
        $answers = [new QuestionAnswer('Yes', true)];
        $question = new Question('Custom namespace?', $answers, 456, 'CustomUser', 'custom_ns');

        $this->assertSame('custom_ns', $question->namespace);
    }

    public function testConstructorDefaultsToSiepatchdbNamespace(): void
    {
        $answers = [new QuestionAnswer('A', false)];
        $question = new Question('Default namespace?', $answers, 789, 'DefaultUser');

        $this->assertSame('siepatchdb', $question->namespace);
    }

    public function testFromSqliteAssocAnswersArray(): void
    {
        $array = [
            [
                'question_id' => 1,
                'question_text' => 'What is PHP?',
                'answer_text' => 'A programming language',
                'correct' => true,
                'added_by_user_id' => 100,
                'added_by_user_name' => 'Admin',
                'namespace' => 'php_quiz',
                'added_at' => '1609459200',
                'explanation' => 'PHP stands for PHP: Hypertext Preprocessor',
            ],
            [
                'question_id' => 1,
                'question_text' => 'What is PHP?',
                'answer_text' => 'A food item',
                'correct' => false,
                'added_by_user_id' => 100,
                'added_by_user_name' => 'Admin',
                'namespace' => 'php_quiz',
                'added_at' => '1609459200',
                'explanation' => 'PHP stands for PHP: Hypertext Preprocessor',
            ],
        ];

        $question = Question::fromSqliteAssocAnswersArray($array);

        $this->assertSame(1, $question->id);
        $this->assertSame('What is PHP?', $question->text);
        $this->assertSame(1609459200, $question->addedAt);
        $this->assertSame('PHP stands for PHP: Hypertext Preprocessor', $question->explanation);
        $this->assertCount(2, $question->answers);
        $this->assertTrue($question->answers[0]->correct);
        $this->assertFalse($question->answers[1]->correct);
    }

    public function testGetTextWithAuthor(): void
    {
        $answers = [new QuestionAnswer('A', false)];
        $question = new Question('What is it?', $answers, 123, 'JohnDoe');

        $text = $question->getTextWithAuthor();
        $this->assertSame('Вопрос от JohnDoe: What is it?', $text);
    }

    public function testGetTextWithEmptyAuthor(): void
    {
        $answers = [new QuestionAnswer('A', false)];
        $question = new Question('What is it?', $answers, 123, '');

        // Empty author name still triggers the author prefix
        $text = $question->getTextWithAuthor();
        $this->assertNotSame('What is it?', $text);
    }

    public function testIdAndExplanationSettable(): void
    {
        $answers = [new QuestionAnswer('A', false)];
        $question = new Question('Test', $answers, 1, 'TestUser');

        $question->id = 42;
        $question->explanation = 'Because.';

        $this->assertSame(42, $question->id);
        $this->assertSame('Because.', $question->explanation);
    }
}
