<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\ImageGeneration\ZoomCommandProcessor;
use Perk11\Viktor89\ImageGeneration\ImageTransformProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZoomCommandProcessor::class)]
class ZoomCommandProcessorTest extends TestCase
{
    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createMock(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    private function createMockTransformProcessor(): ImageTransformProcessor
    {
        return $this->createMock(ImageTransformProcessor::class);
    }

    public function testSetsDefaultZoomForEmptyText(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->messageText = '  ';
        $chain = new MessageChain([$message]);

        $transform->expects($this->once())->method('processMessageChain');

        $processor->processMessageChain($chain, $this->createMockCallback());
    }

    public function testAcceptsValidZoomLevel(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->messageText = ' 5 ';
        $chain = new MessageChain([$message]);

        $transform->expects($this->once())->method('processMessageChain');

        $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertSame('5', $preference->value);
    }

    public function testRejectsNonNumericValue(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->chatId = -100;
        $message->id = 99;
        $message->messageText = 'abc';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('Неверный формат', $result->response->messageText);
    }

    public function testRejectsValueAboveMaximum(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->chatId = -100;
        $message->id = 99;
        $message->messageText = '15';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }

    public function testRejectsValueBelowMinimum(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->chatId = -100;
        $message->id = 99;
        $message->messageText = '0';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }

    public function testRejectsNegativeValue(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->chatId = -100;
        $message->id = 99;
        $message->messageText = '-1';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }

    public function testAcceptsMinimumBoundaryValue(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->messageText = '1';
        $chain = new MessageChain([$message]);

        $transform->expects($this->once())->method('processMessageChain');

        $processor->processMessageChain($chain, $this->createMockCallback());
    }

    public function testAcceptsMaximumBoundaryValue(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->messageText = '14';
        $chain = new MessageChain([$message]);

        $transform->expects($this->once())->method('processMessageChain');

        $processor->processMessageChain($chain, $this->createMockCallback());
    }

    public function testRejectsFloat(): void
    {
        $transform = $this->createMockTransformProcessor();
        $preference = new FixedValuePreferenceProvider(null);
        $processor = new ZoomCommandProcessor($transform, $preference);

        $message = new InternalMessage();
        $message->chatId = -100;
        $message->id = 99;
        $message->messageText = '5.5';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }
}
