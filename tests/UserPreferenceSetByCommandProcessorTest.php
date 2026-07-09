<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class)]
class UserPreferenceSetByCommandProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testImplementsUserPreferenceReaderInterface(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\UserPreferenceReaderInterface::class)
        );
    }

    public function testImplementsGetTriggeringCommandsInterface(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\GetTriggeringCommandsInterface::class)
        );
    }

    public function testConstructorTakesFourParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame(\Perk11\Viktor89\Repository\UserPreferenceRepository::class, $params[0]->getType()->getName());
        $this->assertSame('triggeringCommands', $params[1]->getName());
        $this->assertSame('preferenceName', $params[2]->getName());
        $this->assertSame('botUserName', $params[3]->getName());
    }

    public function testHasGetValueValidationErrorsMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $method = $reflection->getMethod('getValueValidationErrors');
        $this->assertSame('array', $method->getReturnType()->getName());
        $this->assertTrue($method->isProtected());
    }

    public function testHasProcessValueAsSettingMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $method = $reflection->getMethod('processValueAsSetting');
        $this->assertTrue($method->isProtected());
        $this->assertSame('bool', $method->getReturnType()->getName());
    }

    public function testHasTransformValueMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor::class);
        $method = $reflection->getMethod('transformValue');
        $this->assertTrue($method->isProtected());
    }
}
