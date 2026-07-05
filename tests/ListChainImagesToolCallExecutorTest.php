<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ListChainImagesToolCallExecutor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListChainImagesToolCallExecutor::class)]
class ListChainImagesToolCallExecutorTest extends TestCase
{
    public function testEmptyChainReturnsNoImages(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $chain = new MessageChain([
            self::makeMessage('User', 'Hello!', null),
        ]);
        $result = $executor->executeToolCall([], $chain);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['images']);
    }

    public function testSingleImageInChain(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $chain = new MessageChain([
            self::makeMessage('Alice', 'Check this out', 'photo_file_1'),
        ]);
        $result = $executor->executeToolCall([], $chain);

        $this->assertSame(1, $result['count']);
        $this->assertCount(1, $result['images']);
        $this->assertSame(0, $result['images'][0]['id']);
        $this->assertSame('#0', $result['images'][0]['reference']);
        $this->assertSame('Alice', $result['images'][0]['author']);
        $this->assertSame('Check this out', $result['images'][0]['caption']);
        $this->assertNull($result['images'][0]['alt_text']);
    }

    public function testMultipleImagesWithMixedMessages(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $chain = new MessageChain([
            self::makeMessage('Alice', 'Hi there', null),
            self::makeMessage('Bob', 'Look at this cat', 'photo_file_1'),
            self::makeMessage('Alice', 'Nice!', null),
            self::makeMessage('Bob', 'And a dog too', 'photo_file_2'),
            self::makeMessage('Alice', 'Cool', null),
            self::makeMessage('Bob', 'Here is a bird', 'photo_file_3'),
        ]);
        $result = $executor->executeToolCall([], $chain);

        $this->assertSame(3, $result['count']);
        $this->assertCount(3, $result['images']);
        $this->assertSame(0, $result['images'][0]['id']);
        $this->assertSame('#0', $result['images'][0]['reference']);
        $this->assertSame('Bob', $result['images'][0]['author']);
        $this->assertSame('Look at this cat', $result['images'][0]['caption']);

        $this->assertSame(1, $result['images'][1]['id']);
        $this->assertSame('#1', $result['images'][1]['reference']);
        $this->assertSame('Bob', $result['images'][1]['author']);
        $this->assertSame('And a dog too', $result['images'][1]['caption']);

        $this->assertSame(2, $result['images'][2]['id']);
        $this->assertSame('#2', $result['images'][2]['reference']);
        $this->assertSame('Bob', $result['images'][2]['author']);
        $this->assertSame('Here is a bird', $result['images'][2]['caption']);
    }

    public function testImageWithAltText(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $message = self::makeMessage('Alice', '', 'photo_file_1');
        $message->altText = 'A beautiful sunset over the ocean';
        $chain = new MessageChain([$message]);
        $result = $executor->executeToolCall([], $chain);

        $this->assertSame(1, $result['count']);
        $this->assertNull($result['images'][0]['caption']);
        $this->assertSame('A beautiful sunset over the ocean', $result['images'][0]['alt_text']);
    }

    public function testImageWithEmptyCaptionHasNullCaption(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $chain = new MessageChain([
            self::makeMessage('Alice', '', 'photo_file_1'),
        ]);
        $result = $executor->executeToolCall([], $chain);

        $this->assertNull($result['images'][0]['caption']);
    }

    public function testBotImageLabelledAsAssistant(): void
    {
        $botUserId = 88888;
        $executor = new ListChainImagesToolCallExecutor($botUserId);
        $message = self::makeMessage('', 'Generated image', 'photo_file_1');
        $message->userId = $botUserId;
        $chain = new MessageChain([$message]);
        $result = $executor->executeToolCall([], $chain);

        $this->assertSame('assistant', $result['images'][0]['author']);
    }

    public function testRejectsUnknownArguments(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $chain = new MessageChain([
            self::makeMessage('Alice', 'Hello', null),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported argument: foo');
        $executor->executeToolCall(['foo' => 'bar'], $chain);
    }

    public function testOnlyMessagesWithPhotosAreListed(): void
    {
        $executor = new ListChainImagesToolCallExecutor(99999);
        $chain = new MessageChain([
            self::makeMessage('Alice', 'Text only', null),
            self::makeMessage('Bob', 'Text only too', null),
        ]);

        $result = $executor->executeToolCall([], $chain);
        $this->assertSame(0, $result['count']);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private static function makeMessage(string $userName, string $text, ?string $photoFileId): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->type = 'text';
        $message->userId = 12345;
        $message->date = time();
        $message->userName = $userName;
        $message->messageText = $text;
        $message->chatId = -100123;
        $message->photoFileId = $photoFileId;
        $message->altText = null;
        return $message;
    }
}
