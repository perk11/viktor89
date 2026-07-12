<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Repository\UserPreferenceRepository;
use Perk11\Viktor89\UserSettings\DynamicListBasedPreferenceByCommandProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DynamicListBasedPreferenceByCommandProcessor::class)]
class DynamicListBasedPreferenceByCommandProcessorTest extends TestCase
{
    private const RESET_VALUE = 'Default';

    private DynamicListBasedPreferenceByCommandProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new DynamicListBasedPreferenceByCommandProcessor(
            $this->createMock(UserPreferenceRepository::class),
            ['/persona'],
            'persona',
            'testbot',
            static function (int $chatId): array {
                return [
                    ['value' => 'Default', 'label' => 'Default (без персоны)'],
                    ['value' => 'pirate', 'label' => 'pirate (от Bob)'],
                ];
            },
            [self::RESET_VALUE],
        );
    }

    public function testTransformValueMapsResetValueToNull(): void
    {
        $this->assertNull($this->invokeTransform(self::RESET_VALUE));
    }

    public function testTransformValueIsCaseInsensitive(): void
    {
        $this->assertNull($this->invokeTransform('default'));
        $this->assertNull($this->invokeTransform('DEFAULT'));
    }

    public function testTransformValueKeepsRegularValue(): void
    {
        $this->assertSame('pirate', $this->invokeTransform('pirate'));
    }

    public function testTransformValueKeepsEmptyStringForButtonDisplay(): void
    {
        // Empty must NOT become null: null would mean "reset", '' means "show buttons".
        $this->assertSame('', $this->invokeTransform(''));
    }

    public function testValidationAcceptsKnownValue(): void
    {
        $this->assertSame([], $this->invokeValidation('pirate'));
    }

    public function testValidationRejectsUnknownValue(): void
    {
        $errors = $this->invokeValidation('ghost');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('pirate', $errors[0]);
    }

    public function testValidationAcceptsNull(): void
    {
        $this->assertSame([], $this->invokeValidation(null));
    }

    public function testButtonLabelHasAuthorButSwitchOnlyHasValue(): void
    {
        $keyboard = $this->invokeBuildKeyboard();

        $pirateButton = $keyboard[1][0];
        $this->assertSame('pirate (от Bob)', $pirateButton['text'], 'Button label shows the author');
        $this->assertSame(
            '/persona pirate',
            $pirateButton['switch_inline_query_current_chat'],
            'Switch query must contain only the persona name, not the author'
        );
    }

    public function testKeyboardIncludesAllOptions(): void
    {
        $keyboard = $this->invokeBuildKeyboard();
        $this->assertCount(2, $keyboard);
        $this->assertSame('Default (без персоны)', $keyboard[0][0]['text']);
        $this->assertSame('/persona Default', $keyboard[0][0]['switch_inline_query_current_chat']);
    }

    private function invokeTransform(string $value): mixed
    {
        return (new \ReflectionMethod($this->processor, 'transformValue'))->invoke($this->processor, $value);
    }

    private function invokeValidation(?string $value): array
    {
        return (new \ReflectionMethod($this->processor, 'getValueValidationErrors'))->invoke($this->processor, $value, -1);
    }

    private function invokeBuildKeyboard(): array
    {
        return (new \ReflectionMethod($this->processor, 'buildInlineKeyboard'))->invoke($this->processor, -1);
    }
}
