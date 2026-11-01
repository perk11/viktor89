<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\MessageChain;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Finds images on the web and appends the best matches to the message chain so
 * image_gen_tool / video_gen can reference them as <img>#N</img>. The images are
 * also stored in SearchedImageCache, from which SearchedImageRestorer puts them
 * back into the chain on later turns. A later search with exactly the same query is
 * answered from the cache without searching or selecting again. They are returned as context_images so the
 * assistant either sees them or gets descriptions from the auxiliary vision model.
 */
class ImageSearchToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    public const string TOOL_NAME = 'image_search';
    private const int DEFAULT_MAX_RESULTS = 3;
    private const int MAX_MAX_RESULTS = 5;
    private const int CANDIDATES_PER_RESULT = 3;
    private const int MAX_CANDIDATES = 10;

    public function __construct(
        private readonly ImageSearchProviderInterface $imageSearchProvider,
        private readonly WebImageDownloader $webImageDownloader,
        private readonly ImageSearchResultSelector $resultSelector,
        private readonly SearchedImageCache $searchedImageCache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        foreach ($arguments as $argumentName => $argumentValue) {
            if (!in_array($argumentName, ['query', 'max_results'], true)) {
                throw new \InvalidArgumentException("Unsupported argument: $argumentName");
            }
        }
        if (!isset($arguments['query']) || !is_string($arguments['query']) || trim($arguments['query']) === '') {
            throw new \InvalidArgumentException('Missing required argument: query');
        }
        $query = trim($arguments['query']);
        $maxResults = $arguments['max_results'] ?? self::DEFAULT_MAX_RESULTS;
        if (!is_int($maxResults) || $maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw new \InvalidArgumentException(
                'Invalid argument value: max_results must be an integer between 1 and ' . self::MAX_MAX_RESULTS
            );
        }

        $selectedImages = $this->searchedImageCache->findByQuery($query, $maxResults);
        if ($selectedImages === []) {
            $selectedImages = $this->searchAndSelect($query, $maxResults);
        }

        $images = [];
        $contextImages = [];
        foreach ($selectedImages as ['candidate' => $candidate, 'image' => $imageBytes]) {
            $messageChain->appendGeneratedImage(SearchedImageRestorer::createImageMessage(
                $messageChain->last(),
                $imageBytes,
                $candidate->title,
                $candidate->sourcePage,
            ));
            $reference = '#' . (count($messageChain->getImages()) - 1);
            $images[] = [
                'reference' => $reference,
                'title' => $candidate->title,
                'image_url' => $candidate->imageUrl,
                'source_page' => $candidate->sourcePage,
            ];
            $contextImages[$reference] = $imageBytes;
        }

        if ($images === []) {
            return [
                'images' => [],
                'message' => 'No images matching the query were found. Try a different or more specific query.',
            ];
        }

        return [
            'images' => $images,
            'note' => 'Reference these images as <img>#N</img> in image_gen_tool or video_gen prompts. They stay in the conversation for later replies; use list_chain_images to look up their #N later.',
            'context_images' => $contextImages,
        ];
    }

    /** @return list<array{candidate: ImageSearchCandidate, image: string}> */
    private function searchAndSelect(string $query, int $maxResults): array
    {
        $candidates = $this->imageSearchProvider->findCandidates(
            $query,
            min($maxResults * self::CANDIDATES_PER_RESULT, self::MAX_CANDIDATES),
        );
        $downloads = $this->webImageDownloader->downloadMany(
            array_map(static fn (ImageSearchCandidate $candidate): string => $candidate->imageUrl, $candidates),
        );

        $candidateImages = [];
        foreach ($candidates as $candidate) {
            $download = $downloads[$candidate->imageUrl] ?? null;
            if (is_string($download)) {
                $candidateImages[] = ['candidate' => $candidate, 'image' => $download];
            } elseif ($download instanceof \Throwable) {
                $this->logger?->log(LogLevel::INFO, "Skipping image {$candidate->imageUrl}: {$download->getMessage()}");
            }
        }

        $selectedImages = [];
        foreach ($this->resultSelector->select($query, $candidateImages, $maxResults) as $index) {
            $selectedImages[] = $candidateImages[$index];
            try {
                $this->searchedImageCache->store($query, $candidateImages[$index]['candidate'], $candidateImages[$index]['image']);
            } catch (\Exception $e) {
                $this->logger?->log(LogLevel::ERROR, $e->getMessage());
            }
        }

        return $selectedImages;
    }
}
