<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchCandidate;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\SearchedImageCache;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\SearchedImageRestorer;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Repository\SearchedImageRepository;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchedImageRestorer::class)]
#[CoversClass(MessageChain::class)]
class SearchedImageRestorerTest extends TestCase
{
    private string $cacheDirectory;
    private SearchedImageCache $cache;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/viktor89-searched-image-restorer-test-' . bin2hex(random_bytes(4));
        $this->cache = new SearchedImageCache($this->createStub(SearchedImageRepository::class), $this->cacheDirectory);
    }

    protected function tearDown(): void
    {
        MessageChain::setSearchedImageRestorer(null);
        array_map(unlink(...), glob($this->cacheDirectory . '/*') ?: []);
        @rmdir($this->cacheDirectory);
    }

    public function testRestoresCachedImagesFromImageSearchToolCalls(): void
    {
        $this->cacheImage('https://one.example/tower.jpg', 'tower-bytes');
        $this->cacheImage('https://two.example/night.jpg', 'night-bytes');
        $reply = self::makeMessage(3, 2, 'bot reply');
        $reply->date = 1700000000;
        $reply->toolCalls = [
            self::imageSearchToolCall([
                ['title' => 'Tower', 'image_url' => 'https://one.example/tower.jpg', 'source_page' => 'https://one.example/'],
                ['title' => 'Gone', 'image_url' => 'https://gone.example/a.jpg', 'source_page' => 'https://gone.example/'],
                ['title' => null, 'image_url' => 'https://two.example/night.jpg', 'source_page' => 'https://two.example/'],
            ]),
            new ToolCall('call_2', 'web_search', '{}', json_encode(['images' => [['image_url' => 'https://one.example/tower.jpg']]])),
            new ToolCall('call_3', 'image_search', '{}', 'not json'),
        ];

        $images = (new SearchedImageRestorer($this->cache))->restore($reply);

        $this->assertCount(2, $images);
        $this->assertSame('tower-bytes', $images[0]->photoContents);
        $this->assertNull($images[0]->photoFileId);
        $this->assertSame('photo', $images[0]->type);
        $this->assertSame('image_search', $images[0]->userName);
        $this->assertSame(-100123, $images[0]->chatId);
        $this->assertSame(1700000000, $images[0]->date);
        $this->assertSame('Web image: Tower (https://one.example/)', $images[0]->messageText);
        $this->assertSame('Web image:  (https://two.example/)', $images[1]->messageText);
    }

    public function testRestoredImagesKeepTheNumberingTheyWereFoundWith(): void
    {
        $this->cacheImage('https://one.example/tower.jpg', 'tower-bytes');
        $userPhoto = self::makeMessage(1, null, 'my photo');
        $userPhoto->photoFileId = 'user-photo';
        $trigger = self::makeMessage(2, 1, 'make it night using a real photo');
        $generatedPhoto = self::makeMessage(3, 2, '');
        $generatedPhoto->photoFileId = 'generated-photo';
        $reply = self::makeMessage(4, 2, 'here you go');
        $reply->toolCalls = [self::imageSearchToolCall([
            ['reference' => '#1', 'title' => 'Tower', 'image_url' => 'https://one.example/tower.jpg', 'source_page' => 'https://one.example/'],
        ])];
        $followUp = self::makeMessage(5, 4, 'now make it day');
        MessageChain::setSearchedImageRestorer(new SearchedImageRestorer($this->cache));

        $chain = new MessageChain([$userPhoto, $trigger, $generatedPhoto, $reply, $followUp]);
        $images = $chain->getImages();

        $this->assertSame('user-photo', $images[0]->photoFileId);
        $this->assertSame('tower-bytes', $images[1]->photoContents);
        $this->assertSame('generated-photo', $images[2]->photoFileId);
        $this->assertCount(3, $images);
        $this->assertSame($followUp, $chain->last());
        $this->assertCount(5, $chain->getMessages());

        $replaced = $chain->withReplacedLastMessage(self::makeMessage(5, 4, 'replaced'));
        $this->assertSame('tower-bytes', $replaced->getImages()[1]->photoContents);
    }

    public function testRestoredImagesGoBeforeTheReplyWhenItsParentIsNotInTheChain(): void
    {
        $this->cacheImage('https://one.example/tower.jpg', 'tower-bytes');
        $earlierPhoto = self::makeMessage(1, null, '');
        $earlierPhoto->photoFileId = 'earlier-photo';
        $reply = self::makeMessage(4, 2, 'here you go');
        $reply->toolCalls = [self::imageSearchToolCall([
            ['title' => 'Tower', 'image_url' => 'https://one.example/tower.jpg', 'source_page' => 'https://one.example/'],
        ])];
        MessageChain::setSearchedImageRestorer(new SearchedImageRestorer($this->cache));

        $chain = new MessageChain([$reply, $earlierPhoto, self::makeMessage(5, 4, 'next')]);
        $chain->appendGeneratedImage(SearchedImageRestorer::createImageMessage($reply, 'new-bytes', 'New', 'https://new.example/'));

        $this->assertSame(
            ['tower-bytes', null, 'new-bytes'],
            array_map(static fn (InternalMessage $image): ?string => $image->photoContents, $chain->getImages()),
        );
    }

    public function testImgTagResolvesRestoredImage(): void
    {
        $this->cacheImage('https://one.example/tower.jpg', 'tower-bytes');
        $trigger = self::makeMessage(2, null, 'find the tower');
        $reply = self::makeMessage(3, 2, 'found it');
        $reply->toolCalls = [self::imageSearchToolCall([
            ['title' => 'Tower', 'image_url' => 'https://one.example/tower.jpg', 'source_page' => 'https://one.example/'],
        ])];
        MessageChain::setSearchedImageRestorer(new SearchedImageRestorer($this->cache));
        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->expects($this->never())->method('downloadPhotoFromInternalMessage');
        $extractor = new ImgTagExtractor($this->createStub(ImageRepository::class), $downloader, logger: new \Psr\Log\NullLogger());

        $result = $extractor->extractImageTags(
            new ImageGenerationPrompt('<img>#0</img> at night'),
            null,
            new MessageChain([$trigger, $reply, self::makeMessage(4, 3, 'make it night')]),
        );

        $this->assertSame(['tower-bytes'], $result->sourceImagesContents);
    }

    private function cacheImage(string $imageUrl, string $image): void
    {
        $this->cache->store('tower', new ImageSearchCandidate(null, $imageUrl, 'https://example.com/'), $image);
    }

    private static function imageSearchToolCall(array $images): ToolCall
    {
        return new ToolCall('call_1', 'image_search', '{"query":"tower"}', json_encode(['images' => $images, 'note' => '']));
    }

    private static function makeMessage(int $id, ?int $replyToMessageId, string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = $id;
        $message->replyToMessageId = $replyToMessageId;
        $message->type = 'text';
        $message->userId = 12345;
        $message->date = 0;
        $message->userName = 'Alice';
        $message->messageText = $text;
        $message->chatId = -100123;

        return $message;
    }
}
