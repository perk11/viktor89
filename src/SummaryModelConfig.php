<?php

declare(strict_types=1);

namespace Perk11\Viktor89;

use InvalidArgumentException;

/** The `summary` section of config.json: which model writes chat summaries and how much fits in its context. */
final readonly class SummaryModelConfig
{
    public function __construct(
        public string $url,
        public string $apiKey,
        public ?string $model,
        public int $contextSize,
        public bool $supportsImages,
        public int $maxResponseTokens,
        public float $charactersPerToken,
        public int $imageTokens,
        public int $maxImageEdgePixels,
        public float $maxImageContextShare,
    ) {
        if ($this->url === '' && $this->apiKey === '') {
            throw new InvalidArgumentException('Summary url and apiKey are both empty, at least one is required');
        }
        if ($this->maxResponseTokens >= $this->contextSize) {
            throw new InvalidArgumentException('Summary maxResponseTokens must be smaller than contextSize');
        }
        if ($this->maxImageContextShare < 0 || $this->maxImageContextShare > 1) {
            throw new InvalidArgumentException('Summary maxImageContextShare must be between 0 and 1');
        }
    }

    /**
     * A summary model that sees images also writes the alt text for images that
     * did not fit into its image budget, otherwise $visionAssistantConfig is used.
     *
     * @return array{url: string, model: ?string, apiKey: string}
     */
    public function altTextAssistantConfig(array $visionAssistantConfig): array
    {
        if (!$this->supportsImages) {
            return [
                'url'    => $visionAssistantConfig['url'],
                'model'  => $visionAssistantConfig['model'] ?? null,
                'apiKey' => $visionAssistantConfig['apiKey'] ?? '',
            ];
        }

        return ['url' => $this->url, 'model' => $this->model, 'apiKey' => $this->apiKey];
    }

    /** url and apiKey fall back to the legacy SUMMARY_SERVER and SUMMARY_OPENAI_KEY env variables. */
    public static function fromConfig(array $summaryConfig): self
    {
        return new self(
            url: $summaryConfig['url'] ?? $_ENV['SUMMARY_SERVER'] ?? '',
            apiKey: $summaryConfig['apiKey'] ?? $_ENV['SUMMARY_OPENAI_KEY'] ?? '',
            model: $summaryConfig['model'] ?? null,
            contextSize: $summaryConfig['contextSize'] ?? 32768,
            supportsImages: $summaryConfig['supportsImages'] ?? false,
            maxResponseTokens: $summaryConfig['maxResponseTokens'] ?? 8192,
            charactersPerToken: (float) ($summaryConfig['charactersPerToken'] ?? 2.5),
            imageTokens: $summaryConfig['imageTokens'] ?? 1024,
            maxImageEdgePixels: $summaryConfig['maxImageEdgePixels'] ?? 1024,
            maxImageContextShare: (float) ($summaryConfig['maxImageContextShare'] ?? 0.3),
        );
    }
}
