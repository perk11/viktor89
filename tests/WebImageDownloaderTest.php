<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\WebImageDownloader;
use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebImageDownloader::class)]
class WebImageDownloaderTest extends TestCase
{
    public function testReturnsJpegUnchanged(): void
    {
        $jpeg = self::image(400, 300, 'jpg');

        $this->assertSame($jpeg, $this->downloaderReturning(new Response(200, [], $jpeg))->download('https://example.com/a.jpg'));
    }

    public function testConvertsWebpToPng(): void
    {
        $result = $this->downloaderReturning(new Response(200, [], self::image(300, 300, 'webp')))
            ->download('https://example.com/a.webp');

        $this->assertSame('png', ContentTypeGuesser::guessFileExtension($result));
        $this->assertSame([300, 300], array_slice(getimagesizefromstring($result), 0, 2));
    }

    public function testDownscalesLargeImages(): void
    {
        $result = $this->downloaderReturning(new Response(200, [], self::image(3072, 1024, 'jpg')))
            ->download('https://example.com/a.jpg');

        $this->assertSame('jpg', ContentTypeGuesser::guessFileExtension($result));
        $this->assertSame([1536, 512], array_slice(getimagesizefromstring($result), 0, 2));
    }

    public function testRejectsTinyImages(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too small');

        $this->downloaderReturning(new Response(200, [], self::image(400, 100, 'png')))->download('https://example.com/a.png');
    }

    public function testRejectsNonImages(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->downloaderReturning(new Response(200, ['Content-Type' => 'text/html'], '<html></html>'))
            ->download('https://example.com/page');
    }

    public function testRejectsOversizedContentLength(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum allowed size');

        $this->downloaderReturning(new Response(200, ['Content-Length' => (string) (6 * 1024 * 1024)], 'x'))
            ->download('https://example.com/a.jpg');
    }

    public function testRejectsOversizedBody(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum allowed size');

        $this->downloaderReturning(new Response(200, [], str_repeat('x', 5 * 1024 * 1024 + 1)))
            ->download('https://example.com/a.jpg');
    }

    public function testRejectsErrorStatus(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->downloaderReturning(new Response(404))->download('https://example.com/a.jpg');
    }

    public function testRejectsNonHttpUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->downloaderReturning(new Response(200))->download('file:///etc/passwd');
    }

    public function testDownloadManyReturnsBytesOrFailurePerUrlInInputOrder(): void
    {
        $jpeg = self::image(400, 300, 'jpg');
        $downloader = new WebImageDownloader(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], $jpeg),
            new Response(500),
            new Response(200, [], 'not an image'),
        ]))]));

        $results = $downloader->downloadMany([
            'https://example.com/ok.jpg',
            'ftp://example.com/invalid.jpg',
            'https://example.com/error.jpg',
            'https://example.com/text.jpg',
        ]);

        $this->assertSame(
            ['https://example.com/ok.jpg', 'ftp://example.com/invalid.jpg', 'https://example.com/error.jpg', 'https://example.com/text.jpg'],
            array_keys($results),
        );
        $this->assertSame($jpeg, $results['https://example.com/ok.jpg']);
        $this->assertInstanceOf(\InvalidArgumentException::class, $results['ftp://example.com/invalid.jpg']);
        $this->assertInstanceOf(\Throwable::class, $results['https://example.com/error.jpg']);
        $this->assertInstanceOf(\RuntimeException::class, $results['https://example.com/text.jpg']);
    }

    private function downloaderReturning(Response $response): WebImageDownloader
    {
        return new WebImageDownloader(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
    }

    private static function image(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image),
            'png' => imagepng($image),
            'webp' => imagewebp($image),
        };
        return (string) ob_get_clean();
    }
}
