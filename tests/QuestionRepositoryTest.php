<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Quiz\QuestionRepository::class)]
class QuestionRepositoryTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasSaveMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $method = $reflection->getMethod('save');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('question', $params[0]->getName());
        $this->assertSame(\Perk11\Viktor89\Quiz\Question::class, $params[0]->getType()->getName());
    }

    public function testSaveReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $method = $reflection->getMethod('save');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    public function testHasFindRandomMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $method = $reflection->getMethod('findRandom');
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType->allowsNull());
    }

    public function testHasFindByIdMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $method = $reflection->getMethod('findById');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testConstructorTakesDatabase(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Perk11\Viktor89\Database::class, $params[0]->getType()->getName());
    }

    public function testHasPrivateReadByStatementMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $method = $reflection->getMethod('readByStatement');
        $this->assertTrue($method->isPrivate());
    }

    public function testHasStaticBuildSelectSqlMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Quiz\QuestionRepository::class);
        $method = $reflection->getMethod('buildSelectSQL');
        $this->assertTrue($method->isPrivate());
        $this->assertTrue($method->isStatic());
        $this->assertSame('string', $method->getReturnType()->getName());
    }
}
