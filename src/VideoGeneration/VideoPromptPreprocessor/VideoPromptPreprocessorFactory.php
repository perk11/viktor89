<?php

namespace Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\Assistant\UnknownAssistantException;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\MiniMaxH3\MiniMaxH3VideoPromptPreprocessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Resolves a VideoPromptPreprocessor from the `preprocessor` key found on a
 * video model's config entry. The key refers to an entry of the optional
 * `videoPromptPreprocessors` config section, which selects the LLM the rewrite
 * request is sent to:
 *
 *   "videoPromptPreprocessors": {
 *       "minimax-h3-glm53":  { "assistant": "minimax-h3-video-prompt" },
 *       "minimax-h3-flash":  { "assistant": "glm-5.3-flash" }
 *   }
 *
 * The built-in `minimax-h3` key keeps working without such an entry (it
 * defaults to the dedicated `minimax-h3-video-prompt` assistant, falling back
 * to the alt-text vision assistant). Unknown / empty keys return null (no
 * preprocessing).
 */
class VideoPromptPreprocessorFactory
{
    /** Built-in config key for the MiniMax-H3 prompt-writing guide. */
    public const string MINIMAX_H3 = 'minimax-h3';

    /**
     * Dedicated vision assistant used by the built-in MiniMax-H3 key when no
     * `videoPromptPreprocessors` entry overrides it; falls back to the existing
     * alt-text vision assistant when it is not configured.
     */
    private const string MINIMAX_H3_ASSISTANT = 'minimax-h3-video-prompt';
    private const string FALLBACK_VISION_ASSISTANT = 'vision-for-alt-text';

    public function __construct(
        private readonly AssistantFactory $assistantFactory,
        private readonly AltTextProvider $altTextProvider,
        private readonly array $preprocessorsConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createByConfigKey(?string $key): ?VideoPromptPreprocessor
    {
        if ($key === null || $key === '') {
            return null;
        }

        $entry = $this->preprocessorsConfig[$key] ?? null;
        if ($entry === null && $key !== self::MINIMAX_H3) {
            $this->logger->log(LogLevel::WARNING, "Unknown preprocessor key '{$key}', not preprocessing");

            return null;
        }

        $configuredAssistant = $entry['assistant'] ?? null;
        if ($entry !== null && !is_string($configuredAssistant)) {
            $this->logger->log(LogLevel::ERROR, "Preprocessor '{$key}' has no 'assistant' key, not preprocessing");
            return null;
        }

        // An explicitly configured assistant is required as-is: a missing one
        // disables preprocessing rather than silently rewriting with a
        // different LLM. Only the built-in key keeps its legacy fallback.
        if ($configuredAssistant !== null) {
            try {
                $assistant = $this->assistantFactory->getAssistantInstanceByName($configuredAssistant);
            } catch (UnknownAssistantException $e) {
                $this->logger->log(LogLevel::ERROR, "Preprocessor '{$key}' refers to unknown assistant '{$configuredAssistant}', not preprocessing: {$e->getMessage()}");
                return null;
            }
        } else {
            $assistant = $this->resolveVisionAssistant();
        }

        // All assistants extend AbstractOpenAIAPiAssistant, which exposes
        // a public $supportsImages flag set from config. When it is false
        // (e.g. a text-only model such as GLM-5.2), the preprocessor
        // describes the frame with the alt-text vision assistant instead
        // of attaching a photo the API would reject.
        $supportsImages = property_exists($assistant, 'supportsImages')
            && $assistant->supportsImages === true;

        return new MiniMaxH3VideoPromptPreprocessor(
            $assistant,
            $supportsImages,
            $this->altTextProvider,
            $this->logger,
        );
    }

    /**
     * Resolves the preprocessor declared on the model the user has currently
     * selected for $preference (e.g. `/videomodel` or `/img2videomodel`). When
     * the user has no preference or it is unknown, the first entry of $config
     * is used as the default — mirroring Txt2VideoClient / Img2VideoClient.
     * Returns null (no preprocessing) when that model has no `preprocessor` field.
     */
    public function createForModelPreference(
        UserPreferenceReaderInterface $preference,
        array $config,
        int $userId,
    ): ?VideoPromptPreprocessor {
        $modelName = $preference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !isset($config[$modelName])) {
            $modelName = array_key_first($config) ?: null;
        }
        $entry = ($modelName !== null && isset($config[$modelName])) ? $config[$modelName] : [];
        $key = $entry['preprocessor'] ?? null;

        if ($key !== null) {
            $this->logger->log(LogLevel::INFO, "Resolved preprocessor '{$key}' for model '{$modelName}'");
        } else {
            $this->logger->log(LogLevel::DEBUG, "No preprocessor for model '{$modelName}'");
        }

        return $this->createByConfigKey($key);
    }

    private function resolveVisionAssistant(): ContextCompletingAssistantInterface
    {
        try {
            return $this->assistantFactory->getAssistantInstanceByName(self::MINIMAX_H3_ASSISTANT);
        } catch (UnknownAssistantException) {
            return $this->assistantFactory->getAssistantInstanceByName(self::FALLBACK_VISION_ASSISTANT);
        }
    }
}
