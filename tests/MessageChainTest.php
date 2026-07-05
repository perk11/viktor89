<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use LogicException;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageChain::class)]
class MessageChainTest extends TestCase
{
    public function testEmptyMessagesThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Message chain initialized with no messages');
        new MessageChain([]);
    }

    public function testFirstReturnsFirstMessage(): void
    {
        $first = self::makeMessage('Alice');
        $second = self::makeMessage('Bob');
        $chain = new MessageChain([$first, $second]);

        $this->assertSame($first, $chain->first());
    }

    public function testLastReturnsLastMessage(): void
    {
        $first = self::makeMessage('Alice');
        $second = self::makeMessage('Bob');
        $chain = new MessageChain([$first, $second]);

        $this->assertSame($second, $chain->last());
    }

    public function testSingleMessageFirstAndLastAreSame(): void
    {
        $message = self::makeMessage('Alice');
        $chain = new MessageChain([$message]);

        $this->assertSame($message, $chain->first());
        $this->assertSame($message, $chain->last());
    }

    public function testPreviousReturnsNullForSingleMessage(): void
    {
        $chain = new MessageChain([self::makeMessage('Alice')]);
        $this->assertNull($chain->previous());
    }

    public function testPreviousReturnsSecondToLastMessage(): void
    {
        $first = self::makeMessage('Alice');
        $second = self::makeMessage('Bob');
        $chain = new MessageChain([$first, $second]);

        $this->assertSame($first, $chain->previous());
    }

    public function testPreviousReturnsCorrectMessageForThreeMessages(): void
    {
        $first = self::makeMessage('Alice');
        $second = self::makeMessage('Bob');
        $third = self::makeMessage('Charlie');
        $chain = new MessageChain([$first, $second, $third]);

        $this->assertSame($second, $chain->previous());
    }

    public function testCountReturnsCorrectCount(): void
    {
        $chain = new MessageChain([
            self::makeMessage('Alice'),
            self::makeMessage('Bob'),
            self::makeMessage('Charlie'),
        ]);

        $this->assertSame(3, $chain->count());
    }

    public function testGetMessagesReturnsAllMessages(): void
    {
        $messages = [
            self::makeMessage('Alice'),
            self::makeMessage('Bob'),
        ];
        $chain = new MessageChain($messages);

        $this->assertSame($messages, $chain->getMessages());
    }

    public function testWithReplacedLastMessageCreatesNewChain(): void
    {
        $first = self::makeMessage('Alice');
        $second = self::makeMessage('Bob');
        $chain = new MessageChain([$first, $second]);

        $replaced = self::makeMessage('Charlie');
        $newChain = $chain->withReplacedLastMessage($replaced);

        $this->assertNotSame($chain, $newChain);
        $this->assertSame($first, $newChain->first());
        $this->assertSame($replaced, $newChain->last());
        // Original chain is unchanged
        $this->assertSame($second, $chain->last());
    }

    public function testWithReplacedMessageCreatesNewChain(): void
    {
        $first = self::makeMessage('Alice');
        $second = self::makeMessage('Bob');
        $third = self::makeMessage('Charlie');
        $chain = new MessageChain([$first, $second, $third]);

        $replaced = self::makeMessage('Dave');
        $newChain = $chain->withReplacedMessage($replaced, 1);

        $this->assertNotSame($chain, $newChain);
        $this->assertSame($first, $newChain->getMessages()[0]);
        $this->assertSame($replaced, $newChain->getMessages()[1]);
        $this->assertSame($third, $newChain->getMessages()[2]);
        // Original chain is unchanged
        $this->assertSame($second, $chain->getMessages()[1]);
    }

    private static function makeMessage(string $userName): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = random_int(1, 100000);
        $message->chatId = -100123;
        $message->userId = 12345;
        $message->userName = $userName;
        $message->messageText = "Message from $userName";
        $message->type = 'text';
        $message->date = time();
        return $message;
    }
}
