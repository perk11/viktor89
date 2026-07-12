<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantFactory::class)]
class AssistantFactoryTest extends TestCase
{
    public function testModelWithoutAllowedChatIdsIsAllowedEverywhere(): void
    {
        $this->assertTrue(AssistantFactory::isModelAllowedInChat(['selectableByUser' => true], -100));
        $this->assertTrue(AssistantFactory::isModelAllowedInChat([], -1));
    }

    public function testModelIsAllowedOnlyInListedChats(): void
    {
        $config = ['allowedChatIds' => ['-1001804789551', '-1002114209100']];

        $this->assertTrue(AssistantFactory::isModelAllowedInChat($config, -1001804789551));
        $this->assertTrue(AssistantFactory::isModelAllowedInChat($config, -1002114209100));
        $this->assertFalse(AssistantFactory::isModelAllowedInChat($config, -999));
    }

    public function testAllowedChatIdsMatchAcrossStringAndInt(): void
    {
        // Config values are JSON strings; runtime chat ids are ints.
        $this->assertTrue(AssistantFactory::isModelAllowedInChat(['allowedChatIds' => ['-1001804789551']], -1001804789551));
    }
}
