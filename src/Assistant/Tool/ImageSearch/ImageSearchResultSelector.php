<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Asks a vision assistant, in a context separate from the conversation, which
 * of the downloaded candidates actually match the query. Web pages contain
 * plenty of loosely related images, so titles and URLs alone are not enough.
 */
class ImageSearchResultSelector
{
    public ?ContextCompletingAssistantInterface $assistantWithVision = null;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /**
     * @param list<array{candidate: ImageSearchCandidate, image: string}> $images
     * @return list<int> Indexes into $images, best match first. Empty when nothing matches.
     */
    public function select(string $query, array $images, int $maxResults): array
    {
        if ($images === []) {
            return [];
        }
        $fallback = array_slice(array_keys($images), 0, $maxResults);
        if ($this->assistantWithVision === null) {
            return $fallback;
        }

        try {
            $response = $this->assistantWithVision
                ->getCompletionBasedOnContext($this->buildContext($query, $images, $maxResults))
                ->content;
        } catch (\Throwable $e) {
            $this->logger?->log(LogLevel::ERROR, 'Image search result selection failed: ' . $e->getMessage());
            return $fallback;
        }

        $selection = $this->parseSelection($response, count($images), $maxResults);
        if ($selection === null) {
            $this->logger?->log(LogLevel::WARNING, 'Could not parse image search selection: ' . mb_substr($response, 0, 500));
            return $fallback;
        }

        return $selection;
    }

    /**
     * @param list<array{candidate: ImageSearchCandidate, image: string}> $images
     */
    private function buildContext(string $query, array $images, int $maxResults): AssistantContext
    {
        $context = new AssistantContext();
        $context->systemPrompt = 'You select images found by a web search. You will be shown numbered candidate images, '
            . 'each with the title of the page it was found on. Judge each image by what it actually shows, not by its title. '
            . 'Pick the images that best match the search query: the subject must be clearly visible and prominent. '
            . 'Prefer real, sharp, high-resolution images. Reject images of a different subject, collages, screenshots, '
            . 'images dominated by text, watermarks or logos, and advertisements. '
            . 'Respond with only a JSON array of candidate numbers, best match first, e.g. [3, 1]. '
            . "Return at most $maxResults numbers. Return [] if no candidate matches the query.";

        foreach ($images as $index => $image) {
            $message = new AssistantContextMessage();
            $message->isUser = true;
            $message->text = 'Candidate ' . ($index + 1) . ', from page: ' . ($image['candidate']->title ?? 'unknown');
            $message->photo = $image['image'];
            $context->messages[] = $message;
        }

        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = "Search query: $query\nWhich candidates match it best? Respond with only the JSON array.";
        $context->messages[] = $message;

        return $context;
    }

    /**
     * Uses the last JSON array in the response, so reasoning that mentions
     * other numbers before the answer is ignored.
     *
     * @return list<int>|null
     */
    private function parseSelection(string $response, int $candidateCount, int $maxResults): ?array
    {
        if (preg_match_all('/\[[\d\s,]*\]/', $response, $matches) === 0) {
            return null;
        }
        $numbers = json_decode(end($matches[0]), true);
        if (!is_array($numbers)) {
            return null;
        }

        $selection = [];
        foreach ($numbers as $number) {
            $index = (int) $number - 1;
            if ($index >= 0 && $index < $candidateCount && !in_array($index, $selection, true)) {
                $selection[] = $index;
            }
        }

        return array_slice($selection, 0, $maxResults);
    }
}
