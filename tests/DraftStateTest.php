<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\DraftState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftState::class)]
class DraftStateTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $draft = new DraftState(-100123, 42, 'Hello', 'RichMarkdown', 7);

        $this->assertSame(-100123, $draft->chatId);
        $this->assertSame(42, $draft->draftId);
        $this->assertSame('Hello', $draft->text);
        $this->assertSame('RichMarkdown', $draft->parseMode);
        $this->assertSame(7, $draft->messageThreadId);
    }

    public function testMessageThreadIdDefaultsToNull(): void
    {
        $draft = new DraftState(1, 1, 'text', 'Default');

        $this->assertNull($draft->messageThreadId);
        $this->assertNull($draft->editMessageId, 'A draft must not target an existing message');
    }

    public function testEditTargetCarriesMessageIdAndNoDraftId(): void
    {
        $edit = new DraftState(chatId: -100, draftId: null, text: 'edited', parseMode: 'RichMarkdown', editMessageId: 55);

        $this->assertNull($edit->draftId);
        $this->assertSame(55, $edit->editMessageId);
    }

    public function testPropertiesAreReadonly(): void
    {
        $draft = new DraftState(1, 1, 'text', 'Default');

        $this->expectException(\Error::class);

        $draft->text = 'changed';
    }
}
