<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;
use Psr\Http\Message\ResponseInterface;

/**
 * Downloads images from arbitrary URLs and normalizes them into a form every
 * downstream consumer accepts: jpg/png only, bounded dimensions.
 */
class WebImageDownloader
{
    private const int MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;
    public const int MIN_SHORT_SIDE_PIXELS = 200;
    public const int MAX_LONG_SIDE_PIXELS = 1536;

    private readonly Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 8, 'connect_timeout' => 4]);
    }

    public function download(string $url): string
    {
        $result = $this->downloadMany([$url])[$url];
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    /**
     * Downloads all URLs concurrently.
     *
     * @param list<string> $urls
     * @return array<string, string|\Throwable> Image bytes or the failure reason, keyed by URL in input order.
     */
    public function downloadMany(array $urls): array
    {
        $results = array_fill_keys($urls, null);
        $promises = [];
        foreach (array_keys($results) as $url) {
            $url = (string) $url;
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                $results[$url] = new \InvalidArgumentException("Invalid image URL: $url");
                continue;
            }
            $promises[$url] = $this->client->getAsync($url, [
                'headers' => [
                    'Accept'     => 'image/jpeg,image/png,image/webp,image/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (compatible; Viktor89/1.0)',
                ],
                'on_headers' => static function (ResponseInterface $response): void {
                    $contentLength = $response->getHeaderLine('Content-Length');
                    if ($contentLength !== '' && (int) $contentLength > self::MAX_FILE_SIZE_BYTES) {
                        throw new \RuntimeException('Image exceeds maximum allowed size of 5MB');
                    }
                },
                'curl' => [CURLOPT_MAXFILESIZE_LARGE => self::MAX_FILE_SIZE_BYTES],
            ])->then(fn (ResponseInterface $response): string => $this->normalize($this->readBody($response)));
        }

        foreach (Utils::settle($promises)->wait() as $url => $outcome) {
            $reason = $outcome['reason'] ?? null;
            if ($reason instanceof RequestException && $reason->getPrevious() !== null) {
                $reason = $reason->getPrevious();
            }
            $results[$url] = $outcome['state'] === 'fulfilled'
                ? $outcome['value']
                : ($reason instanceof \Throwable ? $reason : new \RuntimeException('Failed to download image'));
        }

        return $results;
    }

    private function readBody(ResponseInterface $response): string
    {
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Image URL returned status code: ' . $response->getStatusCode());
        }
        $body = (string) $response->getBody();
        if (strlen($body) > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException('Image exceeds maximum allowed size of 5MB');
        }

        return $body;
    }

    private function normalize(string $image): string
    {
        $extension = ContentTypeGuesser::guessFileExtension($image);
        if (!in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new \RuntimeException('URL does not point to a jpg, png or webp image');
        }

        $size = getimagesizefromstring($image);
        if ($size === false) {
            throw new \RuntimeException('Failed to read image dimensions');
        }
        [$width, $height] = $size;
        if (min($width, $height) < self::MIN_SHORT_SIDE_PIXELS) {
            throw new \RuntimeException("Image is too small: {$width}x{$height}");
        }

        $longSide = max($width, $height);
        if ($longSide <= self::MAX_LONG_SIDE_PIXELS && $extension !== 'webp') {
            return $image;
        }

        $gdImage = imagecreatefromstring($image);
        if ($gdImage === false) {
            throw new \RuntimeException('Failed to decode image');
        }

        if ($longSide > self::MAX_LONG_SIDE_PIXELS) {
            $scale = self::MAX_LONG_SIDE_PIXELS / $longSide;
            $gdImage = imagescale($gdImage, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if ($gdImage === false) {
                throw new \RuntimeException('Failed to downscale image');
            }
        }

        ob_start();
        if ($extension === 'jpg') {
            imagejpeg($gdImage, null, 90);
        } else {
            imagesavealpha($gdImage, true);
            imagepng($gdImage);
        }

        return ob_get_clean();
    }
}
