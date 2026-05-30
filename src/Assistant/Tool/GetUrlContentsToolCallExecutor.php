<?php

namespace Perk11\Viktor89\Assistant\Tool;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GetUrlContentsToolCallExecutor implements ToolCallExecutorInterface
{
    private const int MAX_CONTENT_CHARS = 10000;
    private const int MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

    private readonly Client $client;

    private array $supportedArguments = ['url'];

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    public function executeToolCall(array $arguments): array
    {
        foreach ($arguments as $argumentName => $argumentValue) {
            if (!in_array($argumentName, $this->supportedArguments, true)) {
                throw new \InvalidArgumentException("Unsupported argument: $argumentName");
            }
        }

        if (!isset($arguments['url'])) {
            throw new \InvalidArgumentException('Missing required argument: url');
        }

        if (!is_string($arguments['url'])) {
            throw new \InvalidArgumentException('Invalid argument type: url must be a string');
        }

        $url = trim($arguments['url']);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid argument value: url must be a valid URL');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Invalid argument value: only http and https schemes are allowed');
        }

        return $this->fetchUrlContents($url);
    }

    private function fetchUrlContents(string $url): array
    {
        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'Viktor89/1.0',
                ],
                'stream' => true,
            ]);
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('Failed to fetch URL: ' . $exception->getMessage(), 0, $exception);
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200 && $statusCode !== 202) {throw new \RuntimeException("URL returned status code: $statusCode");
        }

        $contentLengthHeader = $response->getHeaderLine('Content-Length');
        if ($contentLengthHeader !== '' && (int) $contentLengthHeader > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException("URL content exceeds maximum allowed size: " . number_format((int) $contentLengthHeader / (1024 * 1024), 2) . "MB (max 5MB)");
        }
        $body = '';
        $totalBytes = 0;
        $stream = $response->getBody();
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            $totalBytes += strlen($chunk);
            if ($totalBytes > self::MAX_FILE_SIZE_BYTES) {
                throw new \RuntimeException("URL content exceeds maximum allowed size: " . number_format($totalBytes / (1024 * 1024), 2) . "MB (max 5MB)");
            }
            $body .= $chunk;
        }
        $contentType = $response->getHeaderLine('Content-Type');
        // If it's already plain text, skip HTML stripping
        if (str_contains($contentType, 'text/html')) {
            $text = $this->stripHtml($body);
        } elseif (str_contains($contentType, 'text/')) {
            $text = $body;
        } else {
            throw new \RuntimeException("Unsupported content type $contentType. Only text/html and text/* are supported.");
        }
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

    private function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $matches)) {
            return trim(strip_tags($matches[1]));
        }

        return null;
    }
}
