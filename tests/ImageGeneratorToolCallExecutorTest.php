<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor::class)]
class ImageGeneratorToolCallExecutorTest extends TestCase
{
    // ─── image model used when no image references ───────────────────────────

    public function testUsesImageModelForPromptWithoutImageTags(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $editModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $imageModel->expects($this->once())
            ->method('generateImageByImagePrompt')
            ->willReturn($this->createMock(Automatic1111ImageApiResponse::class));
        $editModel->expects($this->never())
            ->method('generateImageByImagePrompt');

        $imgTagExtractor->expects($this->once())
            ->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('just text'));

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            $editModel,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $executor->executeToolCall(['prompt' => 'A beautiful sunset'], $chain);
    }

    public function testUsesImageModelWhenNoEditModelConfigured(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $imageModel->expects($this->once())
            ->method('generateImageByImagePrompt')
            ->willReturn($this->createMock(Automatic1111ImageApiResponse::class));

        $imgTagExtractor->expects($this->once())
            ->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('text'));

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            null, // no edit model
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $executor->executeToolCall(['prompt' => 'A cool <img>#1</img>'], $chain);
    }

    // ─── edit model used when image references present ───────────────────────

    public function testUsesEditModelForPromptWithSavedImageReference(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $editModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $imageModel->expects($this->never())
            ->method('generateImageByImagePrompt');
        $editModel->expects($this->once())
            ->method('generateImageByImagePrompt')
            ->willReturn($this->createMock(Automatic1111ImageApiResponse::class));

        $imgTagExtractor->expects($this->once())
            ->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('text'));

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            $editModel,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $executor->executeToolCall(['prompt' => 'A scene based on <img>MySavedImage</img>'], $chain);
    }

    public function testUsesEditModelForPromptWithChainImageReference(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $editModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $imageModel->expects($this->never())
            ->method('generateImageByImagePrompt');
        $editModel->expects($this->once())
            ->method('generateImageByImagePrompt')
            ->willReturn($this->createMock(Automatic1111ImageApiResponse::class));

        $imgTagExtractor->expects($this->once())
            ->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('text'));

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            $editModel,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $executor->executeToolCall(['prompt' => 'Remix <img>#1</img>'], $chain);
    }

    public function testUsesEditModelForPromptWithMultipleImageReferences(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $editModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $imageModel->expects($this->never())
            ->method('generateImageByImagePrompt');
        $editModel->expects($this->once())
            ->method('generateImageByImagePrompt')
            ->willReturn($this->createMock(Automatic1111ImageApiResponse::class));

        $imgTagExtractor->expects($this->once())
            ->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('text'));

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            $editModel,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $executor->executeToolCall(
            ['prompt' => 'Blend <img>#1</img> with <img>#2</img> and <img>SavedImg</img>'],
            $chain,
        );
    }

    // ─── image tag detection edge cases ──────────────────────────────────────

    #[DataProvider('imageTagDetectionProvider')]
    public function testImageTagDetection(
        string $prompt,
        bool $expectsEditModel,
        string $label,
    ): void {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $editModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $response = $this->createMock(Automatic1111ImageApiResponse::class);

        if ($expectsEditModel) {
            $imageModel->expects($this->never())
                ->method('generateImageByImagePrompt');
            $editModel->expects($this->once())
                ->method('generateImageByImagePrompt')
                ->willReturn($response);
        } else {
            $imageModel->expects($this->once())
                ->method('generateImageByImagePrompt')
                ->willReturn($response);
            $editModel->expects($this->never())
                ->method('generateImageByImagePrompt');
        }

        $imgTagExtractor->expects($this->once())
            ->method('extractImageTags')
            ->willReturn(new ImageGenerationPrompt('processed'));

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            $editModel,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $executor->executeToolCall(['prompt' => $prompt], $chain);
        $this->assertTrue(true, $label);
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function imageTagDetectionProvider(): array
    {
        return [
            'plain text'              => ['A beautiful sunset',                        false, 'no tags'],
            'single saved image'      => ['Style <img>CatPortrait</img>',              true,  'saved image tag'],
            'single chain image'      => ['Remix <img>#1</img>',                       true,  'chain image tag'],
            'mixed refs'              => ['<img>#1</img> + <img>SavedImg</img>',       true,  'mixed tags'],
            'tag with multiline'      => ["<img>#1\n</img>",                           true,  'multiline tag'],
            'only opening tag'        => ['<img>Foo</img> wait this has closing',      true,  'opening+closing'],
            'only opening no closing' => ['<img>Foo text',                             false, 'no closing tag'],
            'only closing no opening' => ['text </img>',                               false, 'no opening tag'],
            'tag in middle of text'   => ['Start <img>#2</img> End',                   true,  'tag in middle'],
        ];
    }

    // ─── validation ──────────────────────────────────────────────────────────

    public function testRejectsMissingPrompt(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            null,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt is required');
        $executor->executeToolCall([], $chain);
    }

    public function testRejectsNonStringPrompt(): void
    {
        $imageModel = $this->createMock(ImageByPromptGenerator::class);
        $photoResponder = $this->createMock(PhotoResponder::class);
        $imgTagExtractor = $this->createMock(ImgTagExtractor::class);

        $executor = new \Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor(
            $imageModel,
            null,
            $photoResponder,
            $imgTagExtractor,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage()]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt must be a string');
        $executor->executeToolCall(['prompt' => 123], $chain);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private static function makeMessage(): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->type = 'text';
        $message->userId = 12345;
        $message->date = time();
        $message->userName = 'TestUser';
        $message->messageText = 'Hello';
        $message->chatId = -100123;
        $message->photoFileId = null;
        $message->altText = null;
        return $message;
    }
}
