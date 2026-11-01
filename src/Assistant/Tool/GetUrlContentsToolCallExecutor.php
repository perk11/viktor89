<?php

namespace Perk11\Viktor89\Assistant\Tool;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\BlockedUrlException;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\PublicUrlGuard;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\UrlFetchException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class GetUrlContentsToolCallExecutor implements ToolCallExecutorInterface
{
    private const int MAX_CONTENT_CHARS = 10000;
    private const int MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB
    private const int MAX_REDIRECTS = 5;

    private readonly Client $client;

    private array $supportedArguments = ['url'];

    public function __construct(
        private readonly PublicUrlGuard $publicUrlGuard,
        private readonly LoggerInterface $logger,
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client([
            'timeout' => 30,
        ]);
    }

    public function executeToolCall(array $arguments): array
    {
        foreach ($arguments as $argumentName => $argumentValue) {
            if (!in_array($argumentName, $this->supportedArguments, true)) {
                throw new ToolArgumentException("Unsupported argument: $argumentName");
            }
        }

        if (!isset($arguments['url'])) {
            throw new ToolArgumentException('Missing required argument: url');
        }

        if (!is_string($arguments['url'])) {
            throw new ToolArgumentException('Invalid argument type: url must be a string');
        }

        $url = trim($arguments['url']);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ToolArgumentException('Invalid argument value: url must be a valid URL');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ToolArgumentException('Invalid argument value: only http and https schemes are allowed');
        }

        return $this->fetchUrlContents($url);
    }

    /**
     * Redirects are followed by hand so that every hop goes through
     * PublicUrlGuard; Guzzle's own redirect handling would happily follow a
     * public URL into the LAN. Each request is pinned to the address that was
     * validated, so a DNS answer cannot change between the check and the fetch.
     */
    private function fetchUrlContents(string $url): array
    {
        $redirects = 0;
        while (true) {
            $pinned = $this->publicUrlGuard->pin($url);
            try {
                $response = $this->client->get($pinned->url, [
                    'headers' => [
                        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'User-Agent' => 'Viktor89/1.0',
                    ],
                    'stream' => true,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [$pinned->curlResolveEntry()],
                    ],
                ]);
            } catch (GuzzleException $exception) {
                $this->logger->log(LogLevel::WARNING, "Failed to fetch $url: " . $exception->getMessage());
                throw new UrlFetchException('Failed to fetch URL', 0, $exception);
            }

            $location = $this->redirectTarget($response, $url);
            if ($location === null) {
                break;
            }
            if (++$redirects > self::MAX_REDIRECTS) {
                throw new UrlFetchException('Failed to fetch URL: too many redirects');
            }
            $response->getBody()->close();
            $url = $location;
        }

        return $this->parseResponse($url, $response);
    }

    private function redirectTarget(ResponseInterface $response, string $currentUrl): ?string
    {
        if (!in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)) {
            return null;
        }
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            return null;
        }
        try {
            $target = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (\InvalidArgumentException $exception) {
            $this->logger->log(LogLevel::WARNING, "Unparseable redirect from $currentUrl: $location");
            throw new BlockedUrlException('the URL redirected to an invalid location');
        }
        $this->logger->log(LogLevel::INFO, "Following redirect from $currentUrl to $target");

        return $target;
    }

    private function parseResponse(string $url, ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200 && $statusCode !== 202) {
            throw new UrlFetchException("URL returned status code: $statusCode");
        }

        $contentLengthHeader = $response->getHeaderLine('Content-Length');
        if ($contentLengthHeader !== '' && (int) $contentLengthHeader > self::MAX_FILE_SIZE_BYTES) {
            throw new UrlFetchException("URL content exceeds maximum allowed size: " . number_format((int) $contentLengthHeader / (1024 * 1024), 2) . "MB (max 5MB)");
        }
        $body = '';
        $totalBytes = 0;
        $stream = $response->getBody();
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            $totalBytes += strlen($chunk);
            if ($totalBytes > self::MAX_FILE_SIZE_BYTES) {
                throw new UrlFetchException("URL content exceeds maximum allowed size: " . number_format($totalBytes / (1024 * 1024), 2) . "MB (max 5MB)");
            }
            $body .= $chunk;
        }
        $contentType = $response->getHeaderLine('Content-Type');

        // Detect charset from Content-Type header and convert body to UTF-8
        $encoding = $this->extractCharsetFromContentType($contentType);
        if ($encoding !== null && !in_array($encoding, ['utf-8', 'utf8'], true)) {
            $converted = mb_convert_encoding($body, 'UTF-8', $encoding);
            if ($converted !== false) {
                $body = $converted;
            }
        }

        // If it's already plain text, skip HTML stripping
        if (str_contains($contentType, 'text/html')) {
            $text = $this->stripHtml($body);
        } elseif (str_contains($contentType, 'text/') || str_contains($contentType, 'application/json')) {
            $text = $body;
        } else {
            throw new UrlFetchException("Unsupported content type $contentType. Only text/html, applicaiton/json and text/* are supported.");
        }
        // Strip invalid UTF-8 byte sequences before any mb_* or /u regex operations
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove control/format characters except newlines and tabs
        $text = preg_replace('/[^\P{C}\r\n\t]/u', '', $text);

        // Trim each line, collapse horizontal whitespace only, preserve line breaks
        $text = preg_replace('/[ \t\p{Zs}]+/u', ' ', $text);
        $text = preg_replace('/\R{3,}/u', "\n\n", trim($text));

        $truncated = false;
        if (mb_strlen($text, 'UTF-8') > self::MAX_CONTENT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CONTENT_CHARS, 'UTF-8');
            // Try to break at a sentence boundary rather than mid-sentence
            $lastSentenceEnd = max(
                mb_strrpos($text, '. '),
                mb_strrpos($text, '! '),
                mb_strrpos($text, '? '),
                mb_strrpos($text, "\n"),
                false
            );
            if ($lastSentenceEnd !== false) {
                $text = mb_substr($text, 0, $lastSentenceEnd + 1, 'UTF-8');
            }
            $truncated = true;
        }

        $result = [
            'url' => $url,
            'title' => $this->extractTitle($body),
            'content' => $text,
        ];

        if ($truncated) {
            $result['truncated'] = true;
        }

        return $result;
    }

    private function stripHtml(string $html): string
    {
        // Remove script and style contents
        $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $text;
    }

    /**
     * Extract charset from Content-Type header value.
     * Example: 'text/html; charset=windows-1251' -> 'windows-1251'
     */
    private function extractCharsetFromContentType(string $contentType): ?string
    {
        if (preg_match('/charset\s*=\s*([^\s;]+)/i', $contentType, $matches)) {
            $charset = trim($matches[1]);
            // Remove surrounding quotes if present
            $charset = trim($charset, '"\'');
            return $charset !== '' ? $charset : null;
        }

        return null;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $matches)) {
            $title = trim(strip_tags($matches[1]));
            // Strip invalid UTF-8 byte sequences
            return mb_convert_encoding($title, 'UTF-8', 'UTF-8');
        }

        return null;
    }
}
