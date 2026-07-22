<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImgTagExtractor::class)]
class ImgTagExtractorTest extends TestCase
{
    public function testReplacesImgTagWithImageNByDefault(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('a photo of <img>mycat</img> in space'));

        $this->assertSame('a photo of image 1 in space', $result->text);
        $this->assertSame(['img-bytes'], $result->sourceImagesContents);
    }

    public function testUsesImageNWhenModelNameIsNull(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>cat</img>'), null);

        $this->assertSame('image 1', $result->text);
    }

    public function testNumbersMultipleImgTagsSequentially(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img> and <img>b</img>'));

        $this->assertSame('image 1 and image 2', $result->text);
        $this->assertSame(['img-bytes', 'img-bytes'], $result->sourceImagesContents);
    }

    public function testPreservesExistingSourceImagesCount(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img>', ['existing-img']));

        $this->assertSame(['existing-img', 'img-bytes'], $result->sourceImagesContents);
        $this->assertSame('image 2', $result->text);
    }

    public function testOmniGenV1Format(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img> and <img>b</img>'), 'OmniGen-v1');

        $this->assertSame('<img><|image_1|></img> and <img><|image_2|></img>', $result->text);
    }

    public function testOmniGenV2UsesDefaultImageNFormat(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img>'), 'OmniGen-v2');

        $this->assertSame('image 1', $result->text);
    }

    public function testNoImgTagsLeavesTextUnchanged(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('just some text'));

        $this->assertSame('just some text', $result->text);
        $this->assertSame([], $result->sourceImagesContents);
    }

    public function testThrowsWhenSavedImageNotFound(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn(null);
        $extractor = new ImgTagExtractor($repo);

        $this->expectException(SavedImageNotFoundException::class);

        $extractor->extractImageTags(new ImageGenerationPrompt('<img>missing</img>'));
    }

    public function testTrimsReferenceNameBeforeLookup(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturnCallback(function (string $name): ?string {
            $this->assertSame('cat', $name);
            return 'img-bytes';
        });
        $extractor = new ImgTagExtractor($repo);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>  cat  </img>'));

        $this->assertSame('image 1', $result->text);
    }

    public function testResolvesChainImageReference(): void
    {
        $downloader = $this->createStub(TelegramFileDownloader::class);
        $downloader->method('downloadPhotoFromInternalMessage')->willReturn('chain-img-bytes');
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader);

        $photoMessage = new InternalMessage();
        $photoMessage->photoFileId = 'file-id-1';
        $photoMessage->messageText = 'photo';
        $photoMessage->type = 'photo';

        $commandMessage = new InternalMessage();
        $commandMessage->messageText = '/imagine <img>#0</img> cat';
        $commandMessage->type = 'text';

        $chain = new MessageChain([$photoMessage, $commandMessage]);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>#0</img> cat'), null, $chain);

        $this->assertSame('image 1 cat', $result->text);
        $this->assertSame(['chain-img-bytes'], $result->sourceImagesContents);
    }

    public function testNumbersChainImageReferencesSequentially(): void
    {
        $downloader = $this->createStub(TelegramFileDownloader::class);
        $downloader->method('downloadPhotoFromInternalMessage')->willReturn('chain-img-bytes');
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader);

        $photoMessage = new InternalMessage();
        $photoMessage->photoFileId = 'file-id-1';
        $photoMessage->messageText = 'photo';
        $photoMessage->type = 'photo';

        $commandMessage = new InternalMessage();
        $commandMessage->messageText = '/imagine <img>#0</img> <img>#0</img>';
        $commandMessage->type = 'text';

        $chain = new MessageChain([$photoMessage, $commandMessage]);

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>#0</img> <img>#0</img>'), null, $chain);

        $this->assertSame('image 1 image 2', $result->text);
        $this->assertSame(['chain-img-bytes', 'chain-img-bytes'], $result->sourceImagesContents);
    }

    public function testThrowsWhenChainImageIndexNotFound(): void
    {
        $downloader = $this->createStub(TelegramFileDownloader::class);
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader);

        $commandMessage = new InternalMessage();
        $commandMessage->messageText = '/imagine <img>#5</img>';
        $commandMessage->type = 'text';

        $chain = new MessageChain([$commandMessage]);

        $this->expectException(SavedImageNotFoundException::class);

        $extractor->extractImageTags(new ImageGenerationPrompt('<img>#5</img> cat'), null, $chain);
    }

    public function testDoesNotMutateOriginalPrompt(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo);

        $original = new ImageGenerationPrompt('<img>a</img>');
        $extractor->extractImageTags($original);

        $this->assertSame('<img>a</img>', $original->text);
        $this->assertSame([], $original->sourceImagesContents);
    }
}
