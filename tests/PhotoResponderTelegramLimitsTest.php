<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Test\Support\NullMessageRepository;
use Perk11\Viktor89\Test\Support\TestImages;
use Perk11\Viktor89\Util\Telegram\ReactionReplacer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';
require_once __DIR__ . '/Support/AssistantTestFixtures.php';

#[CoversClass(PhotoResponder::class)]
class PhotoResponderTelegramLimitsTest extends TestCase
{
    private const int TELEGRAM_MAX_BYTES = 10_000_000;

    private ?string $uploadedPhoto = null;

    protected function setUp(): void
    {
        $_ENV['TELEGRAM_BOT_USERNAME'] = 'testbot';
        new Telegram('123456789:AAAAAAAAAAAAAAAA', 'testbot');
        Request::setClient(new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            $this->uploadedPhoto = self::extractPhotoFromMultipartBody($request);

            return new FulfilledPromise(new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'message_id' => 42,
                    'date' => time(),
                    'chat' => ['id' => -100700, 'type' => 'group', 'title' => 'Test'],
                    'from' => ['id' => 123456789, 'is_bot' => true, 'first_name' => 'Bot'],
                    'photo' => [['file_id' => 'large', 'file_size' => 500]],
                ],
            ], JSON_THROW_ON_ERROR)));
        }]));
    }

    public function testPhotoWithinLimitsIsUploadedUnchanged(): void
    {
        $png = TestImages::png(16, 16);

        $this->sendPhoto($png);

        $this->assertSame($png, $this->uploadedPhoto);
    }

    public function testPhotoOverByteLimitIsUploadedAsJpegOfSameDimensions(): void
    {
        $png = self::noisePng(2000, 2000);
        $this->assertGreaterThan(self::TELEGRAM_MAX_BYTES, strlen($png));

        $this->sendPhoto($png);

        $this->assertLessThanOrEqual(self::TELEGRAM_MAX_BYTES, strlen($this->uploadedPhoto));
        $imageInfo = getimagesizefromstring($this->uploadedPhoto);
        $this->assertSame(IMAGETYPE_JPEG, $imageInfo[2]);
        $this->assertSame([2000, 2000], [$imageInfo[0], $imageInfo[1]]);
    }

    public function testOriginalIsCachedWhenCompressedCopyIsUploaded(): void
    {
        $png = self::noisePng(2000, 2000);

        $cachedContents = $this->sendPhoto($png);

        $this->assertSame($png, $cachedContents);
        $this->assertNotSame($png, $this->uploadedPhoto);
    }

    public function testPhotoOverDimensionLimitIsDownscaled(): void
    {
        $this->sendPhoto(TestImages::png(9000, 3000));

        $imageInfo = getimagesizefromstring($this->uploadedPhoto);
        $this->assertLessThanOrEqual(10000, $imageInfo[0] + $imageInfo[1]);
        $this->assertEqualsWithDelta(3.0, $imageInfo[0] / $imageInfo[1], 0.01);
    }

    /** @return ?string the contents written to the file cache */
    private function sendPhoto(string $photoContents): ?string
    {
        $cache = new class extends CacheFileManager {
            public ?string $cachedContents = null;

            public function __construct()
            {
            }

            public function writeFileToCache(string $fileId, string $contents): void
            {
                $this->cachedContents = $contents;
            }
        };
        $responder = new PhotoResponder(
            new NullMessageRepository(),
            $cache,
            new class extends ReactionReplacer {
                public function __construct()
                {
                }

                public function deleteOrReplaceWith(int $chatId, int $messageId, string $emoji): void
                {
                }
            },
            logger: new \Psr\Log\NullLogger(),
        );

        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100700;
        $message->userId = 999;
        $message->userName = 'Tester';
        $message->messageText = 'A prompt';

        $responder->sendPhoto($message, $photoContents, false);

        return $cache->cachedContents;
    }

    private static function extractPhotoFromMultipartBody(RequestInterface $request): string
    {
        $stream = $request->getBody();
        self::assertInstanceOf(MultipartStream::class, $stream);
        $body = (string) $stream;
        $partStart = strpos($body, 'name="photo"');
        $contentStart = strpos($body, "\r\n\r\n", $partStart) + 4;
        $contentEnd = strpos($body, "\r\n--" . $stream->getBoundary(), $contentStart);

        return substr($body, $contentStart, $contentEnd - $contentStart);
    }

    private static function noisePng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
