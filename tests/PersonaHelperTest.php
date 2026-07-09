<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\PersonaHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonaHelper::class)]
class PersonaHelperTest extends TestCase
{
    private PersonaHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new PersonaHelper('testbot');
    }

    public function testNormalizeArgumentStripsBotMention(): void
    {
        $this->assertSame('', $this->helper->normalizeArgument('@testbot'));
        $this->assertSame('pirate', $this->helper->normalizeArgument('@testbot pirate'));
        $this->assertSame('pirate', $this->helper->normalizeArgument('pirate'));
        $this->assertSame('', $this->helper->normalizeArgument('   '));
    }

    public function testExtractNameUsesFirstLine(): void
    {
        $this->assertSame('pirate', $this->helper->extractName("pirate\nsecond line"));
        $this->assertSame('pirate', $this->helper->extractName('pirate'));
    }

    public function testIsReservedNameIsCaseInsensitive(): void
    {
        $this->assertTrue($this->helper->isReservedName('Default'));
        $this->assertTrue($this->helper->isReservedName('DEFAULT'));
        $this->assertTrue($this->helper->isReservedName(' default '));
        $this->assertFalse($this->helper->isReservedName('pirate'));
    }
}
