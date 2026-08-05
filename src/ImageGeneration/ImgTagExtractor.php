<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ImgTagExtractor
{
    public function __construct(
        private readonly ImageRepository $imageRepository,
        private readonly ?TelegramFileDownloader $telegramFileDownloader = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    private const string IMG_REGEX = '/<img>(.*?)<\/img>/s';

    /** <fframe>, <lframe> and <img> frame tags, each carrying a saved-image name or #N chain reference. */
    private const string FRAME_TAG_REGEX = '/<(fframe|lframe|img)>(.*?)<\/\\1>/s';

    public function extractImageTags(
        ImageGenerationPrompt $promptTobeProcessed,
        ?string $modelName = null,
        ?MessageChain $messageChain = null,
        bool $removeTags = false,
    ): ImageGenerationPrompt {
        $newPrompt = clone $promptTobeProcessed;
        $newPrompt->text = preg_replace_callback(
            self::IMG_REGEX,
            function ($matches) use (&$newPrompt, $modelName, $messageChain, $removeTags) {
                $reference = trim($matches[1]);
                $newPrompt->sourceImagesContents[] = $this->resolveReference($reference, $messageChain);

                if ($removeTags) {
                    return '';
                }

                if ($modelName === 'OmniGen-v1') {
                    return '<img><|image_' . (count($newPrompt->sourceImagesContents)) . '|></img>';
                }

                if (str_starts_with($reference, '#')) {
                    return "image " . count($newPrompt->sourceImagesContents);
                }

                return "$reference (as depicted in image " . count($newPrompt->sourceImagesContents) .")";
            },
            $promptTobeProcessed->text,
        );

        if ($promptTobeProcessed->text !== $newPrompt->text) {
            $this->logger?->log(LogLevel::INFO, "Prompt changed to $newPrompt->text");
        }
        return $newPrompt;
    }

    /**
     * Resolves <fframe>, <lframe> and <img> tags from the prompt's userPrompt,
     * downloading each referenced image (a saved-image name or a #N chain
     * index) through the same logic as extractImageTags(). Mutates and returns
     * the prompt: the tags are stripped from userPrompt and the resolved bytes
     * are mapped to the role each tag declared. Only a single first frame and
     * last frame are kept (the first <fframe>/<lframe> wins); <img> references
     * may repeat.
     */
    public function extractImageAndFrameTags(VideoGenerationPrompt $prompt, ?MessageChain $messageChain = null): VideoGenerationPrompt
    {
        $firstFrame = $prompt->firstFrame;
        $lastFrame = $prompt->lastFrame;
        $references = $prompt->referenceImages;

        $prompt->userPrompt = trim((string) preg_replace_callback(
            self::FRAME_TAG_REGEX,
            function (array $matches) use (&$firstFrame, &$lastFrame, &$references, $messageChain): string {
                $imageData = $this->resolveReference(trim($matches[2]), $messageChain);
                match ($matches[1]) {
                    'fframe' => $firstFrame ??= $imageData,
                    'lframe' => $lastFrame ??= $imageData,
                    'img' => $references[] = $imageData,
                };

                return '';
            },
            $prompt->userPrompt,
        ));

        $prompt->firstFrame = $firstFrame;
        $prompt->lastFrame = $lastFrame;
        $prompt->referenceImages = $references;

        if ($prompt->hasAnyImage()) {
            $this->logger?->log(
                LogLevel::INFO,
                'Resolved frame tags: ' . ($firstFrame !== null ? '1' : '0') . ' first, '
                . ($lastFrame !== null ? '1' : '0') . ' last, ' . count($references) . ' reference',
            );
        }

        return $prompt;
    }

    /**
     * Resolves a single image reference to its bytes: a #N chain index reads the
     * Nth photo of the message chain, anything else is a saved-image name.
     */
    private function resolveReference(string $reference, ?MessageChain $messageChain): string
    {
        if ($messageChain !== null && str_starts_with($reference, '#')) {
            $imageIndex = (int) substr($reference, 1);
            $imageData = $this->resolveChainImage($messageChain, $imageIndex);
            if ($imageData === null) {
                throw new SavedImageNotFoundException("Chain image $reference not found");
            }

            return $imageData;
        }

        $savedImage = $this->imageRepository->retrieve($reference);
        if ($savedImage === null) {
            throw new SavedImageNotFoundException($reference);
        }

        return $savedImage;
    }

    /**
     * Resolve a chain image reference to its binary contents.
     * $imageIndex is 0-based.
     */
    private function resolveChainImage(MessageChain $messageChain, int $imageIndex): ?string
    {
        if ($imageIndex < 0) {
            return null;
        }

        $foundIndex = 0;
        foreach ($messageChain->getMessages() as $message) {
            if ($message->photoFileId !== null) {
                if ($foundIndex === $imageIndex) {
                    try {
                        return $this->telegramFileDownloader->downloadPhotoFromInternalMessage($message);
                    } catch (\Exception $e) {
                        $this->logger?->log(LogLevel::ERROR, "Failed to download chain image $imageIndex: " . $e->getMessage());
                        return null;
                    }
                }
                $foundIndex++;
            }
        }

        return null;
    }
}
