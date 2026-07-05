<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Exception;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiCompletionStringParser::class)]
class OpenAiCompletionStringParserTest extends TestCase
{
    private OpenAiCompletionStringParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OpenAiCompletionStringParser();
    }

    public function testParseReturnsNullForDoneMarker(): void
    {
        $result = $this->parser->parse("data: [DONE]");
        $this->assertNull($result);
    }

    public function testParseReturnsDecodedJsonForValidData(): void
    {
        $jsonPayload = json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]);
        $result = $this->parser->parse("data: $jsonPayload");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('choices', $result);
        $this->assertSame('Hello', $result['choices'][0]['delta']['content']);
    }

    public function testParseThrowsForMissingDataPrefix(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unexpected completion string:');
        $this->parser->parse('no data prefix here');
    }

    public function testParseThrowsForInvalidJson(): void
    {
        $this->expectException(Exception::class);
        $this->parser->parse('data: {invalid json');
    }

    public function testParseHandlesEmptyObject(): void
    {
        $result = $this->parser->parse('data: {}');
        $this->assertSame([], $result);
    }

    public function testParseHandlesComplexJson(): void
    {
        $payload = [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'created' => 1234567890,
            'model' => 'gpt-4',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['role' => 'assistant', 'content' => 'Test content'],
                    'finish_reason' => null,
                ],
            ],
        ];
        $result = $this->parser->parse('data: ' . json_encode($payload));

        $this->assertSame('chatcmpl-123', $result['id']);
        $this->assertSame('gpt-4', $result['model']);
        $this->assertSame('Test content', $result['choices'][0]['delta']['content']);
    }

    public function testParseHandlesDoneWithWhitespace(): void
    {
        $result = $this->parser->parse("data: [DONE]  ");
        $this->assertNull($result);
    }

    public function testParsePreservesJsonTypes(): void
    {
        $payload = [
            'number' => 42,
            'float' => 3.14,
            'boolean' => true,
            'nullValue' => null,
            'string' => 'hello',
            'array' => [1, 2, 3],
        ];
        $result = $this->parser->parse('data: ' . json_encode($payload));

        $this->assertSame(42, $result['number']);
        $this->assertSame(3.14, $result['float']);
        $this->assertTrue($result['boolean']);
        $this->assertNull($result['nullValue']);
        $this->assertSame('hello', $result['string']);
        $this->assertSame([1, 2, 3], $result['array']);
    }
}
