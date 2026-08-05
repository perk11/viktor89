<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImgTagExtractor::class)]
class ImgTagExtractorTest extends TestCase
{
    public function testReplacesImgTagWithImageNameAndDepictedReferenceByDefault(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('a photo of <img>mycat</img> in space'));

        $this->assertSame('a photo of mycat (as depicted in image 1) in space', $result->text);
        $this->assertSame(['img-bytes'], $result->sourceImagesContents);
    }

    public function testUsesDefaultFormatWhenModelNameIsNull(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>cat</img>'), null);

        $this->assertSame('cat (as depicted in image 1)', $result->text);
    }

    public function testNumbersMultipleImgTagsSequentially(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img> and <img>b</img>'));

        $this->assertSame('a (as depicted in image 1) and b (as depicted in image 2)', $result->text);
        $this->assertSame(['img-bytes', 'img-bytes'], $result->sourceImagesContents);
    }

    public function testPreservesExistingSourceImagesCount(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img>', ['existing-img']));

        $this->assertSame(['existing-img', 'img-bytes'], $result->sourceImagesContents);
        $this->assertSame('a (as depicted in image 2)', $result->text);
    }

    public function testOmniGenV1Format(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img> and <img>b</img>'), 'OmniGen-v1');

        $this->assertSame('<img><|image_1|></img> and <img><|image_2|></img>', $result->text);
    }

    public function testOmniGenV2UsesDefaultFormat(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img>'), 'OmniGen-v2');

        $this->assertSame('a (as depicted in image 1)', $result->text);
    }

    public function testNoImgTagsLeavesTextUnchanged(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('just some text'));

        $this->assertSame('just some text', $result->text);
        $this->assertSame([], $result->sourceImagesContents);
    }

    public function testThrowsWhenSavedImageNotFound(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn(null);
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

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
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>  cat  </img>'));

        $this->assertSame('cat (as depicted in image 1)', $result->text);
    }

    public function testResolvesChainImageReference(): void
    {
        $downloader = $this->createStub(TelegramFileDownloader::class);
        $downloader->method('downloadPhotoFromInternalMessage')->willReturn('chain-img-bytes');
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader, logger: new \Psr\Log\NullLogger());

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
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader, logger: new \Psr\Log\NullLogger());

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
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader, logger: new \Psr\Log\NullLogger());

        $commandMessage = new InternalMessage();
        $commandMessage->messageText = '/imagine <img>#5</img>';
        $commandMessage->type = 'text';

        $chain = new MessageChain([$commandMessage]);

        $this->expectException(SavedImageNotFoundException::class);

        $extractor->extractImageTags(new ImageGenerationPrompt('<img>#5</img> cat'), null, $chain);
    }

    public function testRemoveTagsReplacesImgTagWithEmptyString(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('a photo of <img>mycat</img> in space'), null, null, true);

        $this->assertSame('a photo of  in space', $result->text);
        $this->assertSame(['img-bytes'], $result->sourceImagesContents);
    }

    public function testRemoveTagsStripsMultipleImgTags(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(new ImageGenerationPrompt('<img>a</img> and <img>b</img>'), null, null, true);

        $this->assertSame(' and ', $result->text);
        $this->assertSame(['img-bytes', 'img-bytes'], $result->sourceImagesContents);
    }

    public function testDoesNotMutateOriginalPrompt(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $original = new ImageGenerationPrompt('<img>a</img>');
        $extractor->extractImageTags($original);

        $this->assertSame('<img>a</img>', $original->text);
        $this->assertSame([], $original->sourceImagesContents);
    }

    public function testFrameTagsLeaveTextUnchangedWhenNonePresent(): void
    {
        $extractor = $this->frameTagExtractor();

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('just some text'));

        $this->assertSame('just some text', $result->userPrompt);
        $this->assertNull($result->firstFrame);
        $this->assertNull($result->lastFrame);
        $this->assertSame([], $result->referenceImages);
        $this->assertFalse($result->hasAnyImage());
    }

    public function testFirstFrameTagResolvesSavedImageIntoFirstFrame(): void
    {
        $extractor = $this->frameTagExtractor();

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<fframe>mycat</fframe> running'));

        $this->assertSame('running', $result->userPrompt);
        $this->assertSame('img-bytes', $result->firstFrame);
        $this->assertNull($result->lastFrame);
        $this->assertSame([], $result->referenceImages);
    }

    public function testLastFrameTagResolvesSavedImageIntoLastFrame(): void
    {
        $extractor = $this->frameTagExtractor();

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<lframe>mycat</lframe> ending'));

        $this->assertSame('ending', $result->userPrompt);
        $this->assertSame('img-bytes', $result->lastFrame);
    }

    public function testImgFrameTagResolvesSavedImageIntoReferences(): void
    {
        $extractor = $this->frameTagExtractor();

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<img>mycat</img> referenced'));

        $this->assertSame('referenced', $result->userPrompt);
        $this->assertSame(['img-bytes'], $result->referenceImages);
    }

    public function testMixedFrameTagsAreGroupedByRoleInOrder(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturnMap([
            ['ff', 'ff-bytes'],
            ['lf', 'lf-bytes'],
            ['ref', 'ref-bytes'],
        ]);
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<fframe>ff</fframe> then <img>ref</img> then <lframe>lf</lframe>'));

        $this->assertSame('then  then', $result->userPrompt);
        $this->assertSame('ff-bytes', $result->firstFrame);
        $this->assertSame('lf-bytes', $result->lastFrame);
        $this->assertSame(['ref-bytes'], $result->referenceImages);
    }

    public function testFrameChainReferenceIsResolvedFromMessageChain(): void
    {
        $downloader = $this->createStub(TelegramFileDownloader::class);
        $downloader->method('downloadPhotoFromInternalMessage')->willReturn('chain-img-bytes');
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader, logger: new \Psr\Log\NullLogger());

        $photoMessage = new InternalMessage();
        $photoMessage->photoFileId = 'file-id-1';
        $commandMessage = new InternalMessage();
        $chain = new MessageChain([$photoMessage, $commandMessage]);

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<fframe>#0</fframe> go'), $chain);

        $this->assertSame('chain-img-bytes', $result->firstFrame);
        $this->assertSame('go', $result->userPrompt);
    }

    public function testFrameTagThrowsWhenSavedImageNotFound(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn(null);
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $this->expectException(SavedImageNotFoundException::class);

        $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<fframe>missing</fframe>'));
    }

    public function testMultipleFirstFrameTagsKeepOnlyTheFirst(): void
    {
        // Only a single first frame is supported; a second <fframe> is ignored.
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturnMap([
            ['a', 'a-bytes'],
            ['b', 'b-bytes'],
        ]);
        $extractor = new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<fframe>a</fframe><fframe>b</fframe>'));

        $this->assertSame('a-bytes', $result->firstFrame);
        $this->assertSame('', $result->userPrompt);
    }

    public function testMultipleImgTagsCollectAllReferences(): void
    {
        $extractor = $this->frameTagExtractor();

        $result = $extractor->extractImageAndFrameTags(new VideoGenerationPrompt('<img>a</img><img>b</img>'));

        $this->assertSame(['img-bytes', 'img-bytes'], $result->referenceImages);
        $this->assertNull($result->firstFrame);
    }

    private function frameTagExtractor(): ImgTagExtractor
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('img-bytes');

        return new ImgTagExtractor($repo, logger: new \Psr\Log\NullLogger());
    }
}
