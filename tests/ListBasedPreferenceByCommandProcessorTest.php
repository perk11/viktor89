<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\UserSettings\ListBasedPreferenceByCommandProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListBasedPreferenceByCommandProcessor::class)]
class ListBasedPreferenceByCommandProcessorTest extends TestCase
{
    private function createProcessor(array $acceptedValues): ListBasedPreferenceByCommandProcessor
    {
        $repository = $this->createMock(\Perk11\Viktor89\Repository\UserPreferenceRepository::class);
        return new ListBasedPreferenceByCommandProcessor(
            $repository,
            ['/test'],
            'test_preference',
            'testbot',
            $acceptedValues
        , logger: new \Psr\Log\NullLogger());
    }

    public function testAcceptsValidValue(): void
    {
        $processor = $this->createProcessor(['option_a', 'option_b', 'option_c']);

        $errors = $this->getValidationErrors($processor, 'option_a');

        $this->assertSame([], $errors);
    }

    public function testAcceptsNullValue(): void
    {
        $processor = $this->createProcessor(['a', 'b']);

        $errors = $this->getValidationErrors($processor, null);

        $this->assertSame([], $errors);
    }

    public function testRejectsInvalidValue(): void
    {
        $processor = $this->createProcessor(['model_x', 'model_y']);

        $errors = $this->getValidationErrors($processor, 'unknown_model');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('model_x', $errors[0]);
        $this->assertStringContainsString('model_y', $errors[0]);
    }

    public function testRejectsEmptyString(): void
    {
        $processor = $this->createProcessor(['value1', 'value2']);

        $errors = $this->getValidationErrors($processor, '');

        $this->assertCount(1, $errors);
    }

    public function testCaseSensitiveMatching(): void
    {
        $processor = $this->createProcessor(['Model_A', 'Model_B']);

        $errors = $this->getValidationErrors($processor, 'model_a');

        $this->assertCount(1, $errors);
    }

    public function testAcceptsValueWithSpaces(): void
    {
        $processor = $this->createProcessor(['option with spaces', 'another option']);

        $errors = $this->getValidationErrors($processor, 'option with spaces');

        $this->assertSame([], $errors);
    }

    public function testPickerParamsAreEphemeralInGroupChat(): void
    {
        $processor = $this->createProcessor(['option_a', 'option_b']);

        $params = $this->invokeBuildPickerParams($processor, $this->buildMessage(-100123, 42));

        $this->assertSame(-100123, $params['chat_id']);
        $this->assertSame(42, $params['receiver_user_id']);
        $this->assertSame('Pick a value for test_preference', $params['text']);
        $this->assertArrayHasKey('inline_keyboard', $params['reply_markup']);
    }

    public function testPickerParamsAreNotEphemeralInPrivateChat(): void
    {
        $processor = $this->createProcessor(['option_a', 'option_b']);

        $params = $this->invokeBuildPickerParams($processor, $this->buildMessage(12345, 42));

        $this->assertArrayNotHasKey('receiver_user_id', $params);
    }

    private function buildMessage(int $chatId, int $userId): InternalMessage
    {
        $message = new InternalMessage();
        $message->chatId = $chatId;
        $message->userId = $userId;

        return $message;
    }

    private function invokeBuildPickerParams(ListBasedPreferenceByCommandProcessor $processor, InternalMessage $message): array
    {
        $reflection = new \ReflectionMethod($processor, 'buildPickerMessageParams');

        return $reflection->invoke($processor, $message, []);
    }

    private function getValidationErrors(ListBasedPreferenceByCommandProcessor $processor, ?string $value): array
    {
        $reflection = new \ReflectionMethod($processor, 'getValueValidationErrors');
        return $reflection->invoke($processor, $value, -1);
    }
}
