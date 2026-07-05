<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\EchoUpdateCallback;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class)]
class EchoUpdateCallbackTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsProgressUpdateCallback(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class)
        );
    }

    public function testSubscribeAddsSubscriber(): void
    {
        $callback = new \Perk11\Viktor89\IPC\EchoUpdateCallback();
        $callback->subscribe(fn() => null);
        // No exception means subscription succeeded
        $this->assertTrue(true);
    }

    public function testInvokeMethodExists(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $method = $reflection->getMethod('__invoke');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('processor', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('status', $params[1]->getName());
    }

    public function testInvokeReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $method = $reflection->getMethod('__invoke');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    // ─── Regression + behavior ───────────────────────────────────────────────

    /**
     * Regression test for the TypeError: "TaskUpdateMessage::__construct():
     * Argument #1 ($workerId) must be of type int, null given". The callback
     * referenced an undeclared $workerId property; with the default
     * constructor it must now invoke cleanly.
     */
    public function testInvokeWithDefaultConstructorDoesNotThrow(): void
    {
        $callback = new EchoUpdateCallback();

        $output = self::captureEcho(fn () => $callback('AltTextProvider', 'Generating alt text for photo 123'));

        $this->assertStringContainsString(
            'Progress update received: AltTextProvider - Generating alt text for photo 123',
            $output,
        );
    }

    public function testInvokeDeliversTaskUpdateMessageWithDefaultWorkerId(): void
    {
        $callback = new EchoUpdateCallback();
        $received = null;
        $callback->subscribe(function (TaskUpdateMessage $message) use (&$received): void {
            $received = $message;
        });

        self::captureEcho(fn () => $callback('Processor', 'status'));

        $this->assertNotNull($received);
        $this->assertInstanceOf(TaskUpdateMessage::class, $received);
        $this->assertSame(0, $received->workerId);
        $this->assertSame('Processor', $received->processor);
        $this->assertSame('status', $received->status);
        $this->assertNull($received->chatAction);
    }

    public function testInvokeUsesProvidedWorkerId(): void
    {
        $callback = new EchoUpdateCallback(7);
        $received = null;
        $callback->subscribe(function (TaskUpdateMessage $message) use (&$received): void {
            $received = $message;
        });

        self::captureEcho(fn () => $callback('Processor', 'status'));

        $this->assertSame(7, $received->workerId);
    }

    public function testInvokeForwardsChatActionToMessage(): void
    {
        $callback = new EchoUpdateCallback();
        $received = null;
        $callback->subscribe(function (TaskUpdateMessage $message) use (&$received): void {
            $received = $message;
        });

        $chatAction = new ChatAction(-100123, ChatActionEnum::typing);

        self::captureEcho(fn () => $callback('Processor', 'status', $chatAction));

        $this->assertSame($chatAction, $received->chatAction);
    }

    public function testInvokeNotifiesAllSubscribersInOrder(): void
    {
        $callback = new EchoUpdateCallback();
        $order = [];
        $callback->subscribe(function () use (&$order): void {
            $order[] = 'first';
        });
        $callback->subscribe(function () use (&$order): void {
            $order[] = 'second';
        });

        self::captureEcho(fn () => $callback('Processor', 'status'));

        $this->assertSame(['first', 'second'], $order);
    }

    public function testConstructorAcceptsWorkerIdParameter(): void
    {
        $reflection = new \ReflectionClass(EchoUpdateCallback::class);
        $params = $reflection->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('workerId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertSame(0, $params[0]->getDefaultValue());
    }

    private static function captureEcho(callable $callable): string
    {
        ob_start();
        try {
            $callable();
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
