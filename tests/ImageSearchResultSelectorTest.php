<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchCandidate;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchResultSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageSearchResultSelector::class)]
class ImageSearchResultSelectorTest extends TestCase
{
    public function testSendsEachCandidateWithItsImageInASeparateContext(): void
    {
        $capturedContext = null;
        $assistant = $this->createMock(ContextCompletingAssistantInterface::class);
        $assistant->expects($this->once())
            ->method('getCompletionBasedOnContext')
            ->willReturnCallback(static function (AssistantContext $context) use (&$capturedContext): CompletionResponse {
                $capturedContext = $context;

                return new CompletionResponse('[2, 1]');
            });
        $selector = new ImageSearchResultSelector();
        $selector->assistantWithVision = $assistant;

        $selection = $selector->select('eiffel tower', self::images(3), 2);

        $this->assertSame([1, 0], $selection);
        $this->assertStringContainsString('at most 2', $capturedContext->systemPrompt);
        $this->assertCount(4, $capturedContext->messages);
        $this->assertSame('Candidate 1, from page: Title 0', $capturedContext->messages[0]->text);
        $this->assertSame('image-0', $capturedContext->messages[0]->photo);
        $this->assertSame('image-2', $capturedContext->messages[2]->photo);
        $this->assertStringContainsString('Search query: eiffel tower', $capturedContext->messages[3]->text);
        $this->assertNull($capturedContext->messages[3]->photo);
        foreach ($capturedContext->messages as $message) {
            $this->assertTrue($message->isUser);
        }
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function responses(): iterable
    {
        yield 'plain array' => ['[3, 1]', [2, 0]];
        yield 'last array wins after reasoning' => ["Candidates [1, 2] look similar, but 3 is sharper.\n[3]", [2]];
        yield 'out of range and duplicates are dropped' => ['[0, 4, 2, 2, 9]', [1]];
        yield 'capped at max results' => ['[1, 2, 3]', [0, 1]];
        yield 'nothing matches' => ['[]', []];
    }

    #[DataProvider('responses')]
    public function testParsesSelection(string $response, array $expected): void
    {
        $this->assertSame($expected, $this->selectorResponding($response)->select('q', self::images(3), 2));
    }

    public function testFallsBackToFirstImagesWhenResponseCannotBeParsed(): void
    {
        $this->assertSame([0, 1], $this->selectorResponding('The second one.')->select('q', self::images(3), 2));
    }

    public function testFallsBackToFirstImagesWhenAssistantFails(): void
    {
        $assistant = $this->createStub(ContextCompletingAssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willThrowException(new \RuntimeException('down'));
        $selector = new ImageSearchResultSelector();
        $selector->assistantWithVision = $assistant;

        $this->assertSame([0, 1], $selector->select('q', self::images(3), 2));
    }

    public function testFallsBackToFirstImagesWithoutAssistant(): void
    {
        $this->assertSame([0], (new ImageSearchResultSelector())->select('q', self::images(3), 1));
    }

    public function testDoesNotCallAssistantWithoutImages(): void
    {
        $assistant = $this->createMock(ContextCompletingAssistantInterface::class);
        $assistant->expects($this->never())->method('getCompletionBasedOnContext');
        $selector = new ImageSearchResultSelector();
        $selector->assistantWithVision = $assistant;

        $this->assertSame([], $selector->select('q', [], 3));
    }

    private function selectorResponding(string $response): ImageSearchResultSelector
    {
        $assistant = $this->createStub(ContextCompletingAssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')->willReturn(new CompletionResponse($response));
        $selector = new ImageSearchResultSelector();
        $selector->assistantWithVision = $assistant;

        return $selector;
    }

    /**
     * @return list<array{candidate: ImageSearchCandidate, image: string}>
     */
    private static function images(int $count): array
    {
        $images = [];
        for ($i = 0; $i < $count; $i++) {
            $images[] = [
                'candidate' => new ImageSearchCandidate("Title $i", "https://example.com/$i.jpg", 'https://example.com/'),
                'image' => "image-$i",
            ];
        }

        return $images;
    }
}
