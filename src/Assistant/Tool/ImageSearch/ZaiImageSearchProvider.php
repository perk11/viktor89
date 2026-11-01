<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

use Mcp\Client;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Monolog\Level;
use Perk11\Viktor89\Assistant\Tool\ZaiMcpClientProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Z.ai has no image search, so candidates are collected from the pages found by
 * web_search_prime using the webReader MCP tool (og:image first, then the
 * page's images).
 *
 * @see https://docs.z.ai/devpack/mcp/reader-mcp-server
 */
class ZaiImageSearchProvider implements ImageSearchProviderInterface
{
    private const string SEARCH_ENDPOINT = 'https://api.z.ai/api/mcp/web_search_prime/mcp';
    private const string SEARCH_TOOL_NAME = 'web_search_prime';
    private const string READER_ENDPOINT = 'https://api.z.ai/api/mcp/web_reader/mcp';
    private const string READER_TOOL_NAME = 'webReader';

    private const int PAGES_TO_READ = 4;
    private const int MAX_TITLE_LENGTH = 150;
    private const array SKIPPED_EXTENSIONS = ['svg', 'gif', 'ico'];
    private const array SKIPPED_PATH_FRAGMENTS = [
        'logo', 'icon', 'favicon', 'sprite', 'avatar', 'button', 'topnav', '/nav/', '/static/images/', '.svg.',
    ];
    private const array GENERIC_ALT_TEXTS = ['image', 'img', 'photo', 'picture', 'thumbnail'];

    /** @var \Closure(): Client */
    private readonly \Closure $searchClientProvider;

    /** @var \Closure(): Client */
    private readonly \Closure $readerClientProvider;

    private ?Client $searchClient = null;

    private ?Client $readerClient = null;

    /**
     * @param \Closure(): Client|null $searchClientProvider
     * @param \Closure(): Client|null $readerClientProvider
     */
    public function __construct(
        string $apiKey,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $searchClientProvider = null,
        ?\Closure $readerClientProvider = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('zAiWebSearchApiKey cannot be empty');
        }
        // webReader responses are ~100KB per page, debug logging would flood the error log.
        $this->searchClientProvider = $searchClientProvider
            ?? ZaiMcpClientProvider::create(self::SEARCH_ENDPOINT, $apiKey, 'zai-mcp-image-search', Level::Warning);
        $this->readerClientProvider = $readerClientProvider
            ?? ZaiMcpClientProvider::create(self::READER_ENDPOINT, $apiKey, 'zai-mcp-web-reader', Level::Warning);
    }

    public function findCandidates(string $query, int $limit): array
    {
        $pages = $this->prioritizeDirectLinks($this->searchPages($query));
        // Pages are read lazily since each webReader call takes seconds, but no single
        // page may provide all candidates so they are not all variations of the same thing.
        $maxCandidatesPerPage = max(2, (int) ceil($limit / 2));

        $candidates = [];
        $leftoverCandidates = [];
        $seenUrls = [];
        foreach (array_slice($pages, 0, self::PAGES_TO_READ) as $page) {
            if (count($candidates) >= $limit) {
                break;
            }
            try {
                $pageCandidates = $this->readPageImages($page);
            } catch (\Throwable $e) {
                $this->logger?->log(LogLevel::INFO, "Failed to read images from {$page['url']}: {$e->getMessage()}");
                continue;
            }
            $candidatesFromPage = 0;
            foreach ($pageCandidates as $candidate) {
                if (isset($seenUrls[$candidate->imageUrl])) {
                    continue;
                }
                $seenUrls[$candidate->imageUrl] = true;
                if ($candidatesFromPage < $maxCandidatesPerPage) {
                    $candidates[] = $candidate;
                    $candidatesFromPage++;
                } else {
                    $leftoverCandidates[] = $candidate;
                }
            }
        }

        return array_slice([...$candidates, ...$leftoverCandidates], 0, $limit);
    }

    /**
     * @return list<array{title: ?string, url: string}>
     */
    private function searchPages(string $query): array
    {
        $result = $this->callTool(
            $this->searchClient ??= ($this->searchClientProvider)(),
            self::SEARCH_TOOL_NAME,
            ['search_query' => $query, 'location' => 'us'],
        );

        $pages = [];
        $decoded = $this->decodeTextResult($result);
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = $item['link'] ?? $item['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $pages[] = ['title' => is_string($item['title'] ?? null) ? $item['title'] : null, 'url' => $url];
            }
        }

        return $pages;
    }

    /**
     * Google redirect links are often not followed by webReader, which then
     * resolves the page's relative image URLs against google.com.
     *
     * @param list<array{title: ?string, url: string}> $pages
     * @return list<array{title: ?string, url: string}>
     */
    private function prioritizeDirectLinks(array $pages): array
    {
        $direct = array_filter($pages, fn (array $page): bool => !$this->isGoogleUrl($page['url']));
        $redirects = array_filter($pages, fn (array $page): bool => $this->isGoogleUrl($page['url']));

        return array_values([...$direct, ...$redirects]);
    }

    private function isGoogleUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'google.com' || str_ends_with($host, '.google.com');
    }

    /**
     * @param array{title: ?string, url: string} $page
     * @return list<ImageSearchCandidate>
     */
    private function readPageImages(array $page): array
    {
        $result = $this->callTool(
            $this->readerClient ??= ($this->readerClientProvider)(),
            self::READER_TOOL_NAME,
            [
                'url' => $page['url'],
                'with_images_summary' => true,
                'retain_images' => true,
                'return_format' => 'text',
            ],
        );
        $decoded = $this->decodeTextResult($result);
        if (!is_array($decoded)) {
            return [];
        }

        $sourcePage = is_string($decoded['url'] ?? null) && $decoded['url'] !== '' ? $decoded['url'] : $page['url'];
        $isUnresolvedRedirect = $this->isGoogleUrl($sourcePage);
        $pageTitle = !$isUnresolvedRedirect && is_string($decoded['title'] ?? null) && $decoded['title'] !== ''
            ? $decoded['title']
            : $page['title'];

        $altAndUrlPairs = [];
        foreach (is_array($decoded['images'] ?? null) ? $decoded['images'] : [] as $alt => $imageUrl) {
            if (is_string($imageUrl)) {
                $altAndUrlPairs[] = [(string) $alt, $imageUrl];
            }
        }
        // Photos are usually jpg/webp while PNGs are often UI elements.
        usort(
            $altAndUrlPairs,
            fn (array $a, array $b): int => $this->isLikelyPhotoExtension($b[1]) <=> $this->isLikelyPhotoExtension($a[1]),
        );
        $ogImage = $decoded['metadata']['og:image'] ?? null;
        if (is_string($ogImage)) {
            array_unshift($altAndUrlPairs, ['', $ogImage]);
        }

        $candidates = [];
        foreach ($altAndUrlPairs as [$alt, $imageUrl]) {
            if (!$this->isLikelyPhoto($imageUrl)) {
                continue;
            }
            if ($isUnresolvedRedirect && $this->isGoogleUrl($imageUrl)) {
                continue;
            }
            $altText = $this->cleanAltText($alt);
            $candidates[] = new ImageSearchCandidate(
                $this->shortenTitle($altText !== '' ? $altText : $pageTitle),
                $imageUrl,
                $sourcePage,
            );
        }

        return $candidates;
    }

    private function isLikelyPhoto(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (in_array(pathinfo($path, PATHINFO_EXTENSION), self::SKIPPED_EXTENSIONS, true)) {
            return false;
        }
        foreach (self::SKIPPED_PATH_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        return true;
    }

    private function isLikelyPhotoExtension(string $url): bool
    {
        $extension = pathinfo(strtolower((string) parse_url($url, PHP_URL_PATH)), PATHINFO_EXTENSION);

        return in_array($extension, ['jpg', 'jpeg', 'webp'], true);
    }

    private function shortenTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return mb_strlen($title) > self::MAX_TITLE_LENGTH
            ? mb_substr($title, 0, self::MAX_TITLE_LENGTH - 1) . '…'
            : $title;
    }

    /** webReader names images like "Image 8,12: <alt>" */
    private function cleanAltText(string $alt): string
    {
        $alt = trim((string) preg_replace('/^Image [\d,]+:?\s*/', '', $alt));

        return in_array(mb_strtolower($alt), self::GENERIC_ALT_TEXTS, true) ? '' : $alt;
    }

    private function callTool(Client $client, string $toolName, array $arguments): CallToolResult
    {
        try {
            $result = $client->callTool($toolName, $arguments);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Z.ai MCP $toolName request failed: " . $e->getMessage(), 0, $e);
        }
        if ($result->isError) {
            $errorText = '';
            foreach ($result->content as $content) {
                if ($content instanceof TextContent) {
                    $errorText .= $content->text;
                }
            }
            throw new \RuntimeException("Z.ai MCP $toolName returned an error: $errorText");
        }

        return $result;
    }

    private function decodeTextResult(CallToolResult $result): mixed
    {
        if (is_array($result->structuredContent)) {
            return $result->structuredContent;
        }

        $text = '';
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $text .= $content->text;
            }
        }
        $decoded = json_decode($text, true);
        // Z.ai sometimes wraps the JSON payload in a JSON string.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return $decoded;
    }
}
