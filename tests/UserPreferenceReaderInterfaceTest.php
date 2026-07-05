<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserPreferenceReaderInterface::class)]
class UserPreferenceReaderInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(UserPreferenceReaderInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGetCurrentPreferenceValueMethod(): void
    {
        $reflection = new \ReflectionClass(UserPreferenceReaderInterface::class);
        $method = $reflection->getMethod('getCurrentPreferenceValue');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodTakesIntUserId(): void
    {
        $reflection = new \ReflectionClass(UserPreferenceReaderInterface::class);
        $method = $reflection->getMethod('getCurrentPreferenceValue');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('userId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testMethodReturnsNullableString(): void
    {
        $reflection = new \ReflectionClass(UserPreferenceReaderInterface::class);
        $method = $reflection->getMethod('getCurrentPreferenceValue');
        $returnType = $method->getReturnType();

        $this->assertTrue($returnType->allowsNull());
    }

    public function testConcreteImplementations(): void
    {
        $reflection = new \ReflectionClass(UserPreferenceReaderInterface::class);

        $implementations = [
            FixedValuePreferenceProvider::class,
            \Perk11\Viktor89\ExternallySetValuePreferenceProvider::class,
            \Perk11\Viktor89\Assistant\PrependingSystemPromptProcessor::class,
        ];

        foreach ($implementations as $className) {
            $classReflection = new \ReflectionClass($className);
            $this->assertTrue(
                $classReflection->implementsInterface(UserPreferenceReaderInterface::class),
                "$className should implement UserPreferenceReaderInterface"
            );
        }
    }
}
