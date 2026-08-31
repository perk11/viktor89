<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PrintHelpProcessor;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\PrintHelpProcessor::class)]
class PrintHelpProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testHasSectionsConstant(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $sections = $reflection->getConstant('SECTIONS');
        $this->assertIsArray($sections);
        $this->assertNotEmpty($sections);

        $documentedCommands = [];
        foreach ($sections as $section) {
            $this->assertArrayHasKey('title', $section);
            $this->assertArrayHasKey('commands', $section);
            foreach (array_keys($section['commands']) as $command) {
                $documentedCommands[] = $command;
            }
        }
        $this->assertContains('/image', $documentedCommands);
    }

    public function testConstructorTakesNoParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $constructor = $reflection->getConstructor();
        $this->assertNull($constructor);
    }

    public function testCommandCatalogueIsCollapsedInsideSingleDetailsBlock(): void
    {
        $result = (new PrintHelpProcessor())->processMessageChain(
            new MessageChain([$this->helpCommandMessage()]),
            $this->createStub(ProgressUpdateCallback::class),
        );

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertNotNull($result->response);
        $this->assertSame('RichMarkdown', $result->response->parseMode);

        $text = $result->response->messageText;
        $this->assertSame(1, substr_count($text, '<details>'));
        $this->assertSame(1, substr_count($text, '</details>'));

        $detailsStart = strpos($text, '<details>');
        $detailsEnd = strpos($text, '</details>');
        $this->assertNotFalse($detailsStart);
        $this->assertNotFalse($detailsEnd);

        // The intro and the closing note stay outside the collapsed block.
        $this->assertStringStartsWith('Привет', $text);
        $this->assertStringContainsString('**Виктор89** 🤖', substr($text, 0, $detailsStart));
        $this->assertStringContainsString('👀', substr($text, $detailsEnd));

        // Every section and command lives inside the collapsed block, behind a summary header.
        $catalogue = substr($text, $detailsStart, $detailsEnd - $detailsStart);
        $this->assertStringContainsStringIgnoringCase('<summary>📖 Список команд', $catalogue);
        $this->assertStringContainsString('## 🤖 Чат и ИИ', $catalogue);
        $this->assertStringContainsString('## ℹ️ Прочее', $catalogue);
        $this->assertStringContainsString('/image', $catalogue);
        $this->assertStringNotContainsString('/image', substr($text, 0, $detailsStart));
    }

    private function helpCommandMessage(): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 10;
        $message->chatId = 11111;
        $message->userId = 222;
        $message->userName = 'Alice';
        $message->messageText = '/help';
        $message->type = 'command';

        return $message;
    }
}
