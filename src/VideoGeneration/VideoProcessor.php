<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Perk11\Viktor89\Util\Telegram\ReactionSetter;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessorFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VideoProcessor implements MessageChainProcessor
{
    /** MiniMax-H3 renders at 24 fps; the /frames preference converts to seconds through it. */
    private const int FRAMES_PER_SECOND = 24;

    public function __construct(
        private readonly Txt2VideoClient $txt2VideoClient,
        private readonly VideoResponder $videoResponder,
        private readonly VideoImg2VidProcessor $videoImg2ImgProcessor,
        private readonly AltTextProvider $altTextProvider,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly VideoPromptPreprocessorFactory $preprocessorFactory,
        private readonly UserPreferenceReaderInterface $videoModelPreference,
        private readonly array $videoModelsConfig,
        private readonly UserPreferenceReaderInterface $img2VideoModelPreference,
        private readonly array $img2videoModelsConfig,
        private readonly UserPreferenceReaderInterface $framesPreference,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();

        $promptText = $this->resolvePromptText($messageChain, $progressUpdateCallback);
        if ($promptText === '') {
            return $this->abortWith($message, 'Непонятно, что генерировать...');
        }

        $videoPrompt = new VideoGenerationPrompt($promptText);
        $videoPrompt->durationSeconds = $this->resolveDurationSeconds($message->userId);
        try {
            $videoPrompt = $this->imgTagExtractor->extractImageAndFrameTags($videoPrompt, $messageChain);
        } catch (SavedImageNotFoundException $e) {
            return $this->abortWith(
                $message,
                sprintf('Изображение "%s" не найдено. Создайте его через /saveas или ответьте на фото.', $e->getMessage()),
            );
        }

        // A replied photo is always treated as an additional image reference.
        $replyImage = $this->downloadReplyPhoto($messageChain);
        if ($replyImage !== null) {
            $videoPrompt->referenceImages[] = $replyImage;
        }

        $abort = $this->applyReferenceRules($videoPrompt, $message);
        if ($abort !== null) {
            return $abort;
        }

        // A last-frame request needs a model that declares support for it.
        $lastFrameModel = null;
        if ($videoPrompt->lastFrame !== null) {
            $lastFrameModel = $this->resolveLastFrameModel($message->userId);
            if ($lastFrameModel === null) {
                return $this->abortWith(
                    $message,
                    'Ни одна из настроенных моделей img2video не поддерживает последний кадр. '
                    . 'Укажите модель с поддержкой последнего кадра или не используйте тег <lframe>...',
                );
            }
        }

        // Validation passed: the request will actually be processed, so show
        // the eye now (before the slow preprocessor / generation work).
        ReactionSetter::setMessageReaction($message, '👀');

        // Any concrete frame anchor (first or last) is an image-based task;
        // everything else is plain text-to-video.
        $frameImage = $videoPrompt->firstFrame ?? $videoPrompt->lastFrame;
        if ($frameImage !== null) {
            return $this->generateImg2Vid($message, $videoPrompt, $frameImage, $progressUpdateCallback, $lastFrameModel);
        }

        return $this->generateTxt2Vid($message, $videoPrompt, $progressUpdateCallback);
    }

    private function resolvePromptText(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): string
    {
        $promptText = trim($messageChain->last()->messageText);
        if ($promptText !== '' || $messageChain->count() <= 1) {
            return $promptText;
        }

        $previous = $messageChain->previous();
        $promptText = trim($previous->messageText);
        if ($promptText === '') {
            $promptText = trim($this->altTextProvider->provide($previous, $progressUpdateCallback));
        }

        return $promptText;
    }

    private function resolveDurationSeconds(int $userId): int
    {
        $frames = $this->framesPreference->getCurrentPreferenceValue($userId);
        if ($frames === null) {
            return 5; //I think this is in minimax workflow
        }

        return max(1, (int) round($frames / self::FRAMES_PER_SECOND));
    }

    private function downloadReplyPhoto(MessageChain $messageChain): ?string
    {
        $previous = $messageChain->previous();
        if ($previous?->photoFileId === null) {
            return null;
        }
        try {
            return $this->telegramFileDownloader->downloadPhotoFromInternalMessage($previous);
        } catch (Exception $e) {
            $this->logger->log(LogLevel::WARNING, 'Failed to download replied photo: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Reconciles reference images with the selected model's capabilities.
     *
     * Returns an abort result when the references cannot be satisfied; null to
     * continue. As a side effect, a lone reference on a non-reference model is
     * promoted to the first frame — the legacy reply-to-photo img2vid path.
     *
     * No-op when there are no references, so the model preference is never read
     * in the common text-only case.
     */
    private function applyReferenceRules(VideoGenerationPrompt $videoPrompt, InternalMessage $message): ?ProcessingResult
    {
        if ($videoPrompt->referenceImages === []) {
            return null;
        }

        $modelSupportsReferences = (bool) ($this->selectedImg2VideoEntry($message->userId)['supportsReferences'] ?? false);
        if ($modelSupportsReferences) {
            return null;
        }

        // A non-reference model can consume at most a single image, and only as
        // the first frame. Collapse a lone reference; reject multiple references
        // or a reference alongside an explicit first/last frame.
        if ($videoPrompt->firstFrame === null && count($videoPrompt->referenceImages) === 1) {
            $videoPrompt->firstFrame = array_pop($videoPrompt->referenceImages);

            return null;
        }

        return $this->abortWith(
            $message,
            'Выбранная модель не поддерживает изображения-референсы. '
            . 'Укажите только один референс (он будет использован как первый кадр) через ответ на фото или тег <img>...</img>, '
            . 'либо выберите модель с поддержкой референсов.',
        );
    }

    /**
     * Model to drive a last-frame request: the selected model when it declares
     * supportsLastFrame, otherwise the first configured model that does. Null
     * when none supports it.
     */
    private function resolveLastFrameModel(int $userId): ?string
    {
        $selected = $this->selectedModelName($userId);
        if (
            $selected !== null
            && ($this->img2videoModelsConfig[$selected]['supportsLastFrame'] ?? false)
        ) {
            return $selected;
        }

        return array_find_key($this->img2videoModelsConfig, static fn($config) => $config['supportsLastFrame'] ?? false);
    }

    private function generateImg2Vid(
        InternalMessage $message,
        VideoGenerationPrompt $videoPrompt,
        string $frameImage,
        ProgressUpdateCallback $progressUpdateCallback,
        ?string $lastFrameModel,
    ): ProcessingResult {
        $finalPrompt = $this->preprocessForImg2Video($message->userId, $videoPrompt, $progressUpdateCallback);
        $this->videoImg2ImgProcessor->respondWithImg2VidResult(
            $message,
            $frameImage,
            $finalPrompt,
            $progressUpdateCallback,
            $lastFrameModel,
            // The caption carries the original user idea; the rewritten prompt
            // is recorded as metadata rather than shown under the video.
            $videoPrompt->userPrompt,
            $this->processedPromptToRecord($videoPrompt, $finalPrompt),
        );

        return new ProcessingResult(null, true);
    }

    private function generateTxt2Vid(
        InternalMessage $message,
        VideoGenerationPrompt $videoPrompt,
        ProgressUpdateCallback $progressUpdateCallback,
    ): ProcessingResult {
        $finalPrompt = $this->preprocessForTxt2Video($message->userId, $videoPrompt, $progressUpdateCallback);
        $progressUpdateCallback(static::class, "Generating video for prompt: $finalPrompt", new ChatAction($message->chatId, ChatActionEnum::upload_video));
        try {
            $response = $this->txt2VideoClient->generateByPromptTxt2Vid($finalPrompt, $message->userId);
            $progressUpdateCallback(static::class, "Sending video response");
            // The caption carries the original user idea; the rewritten prompt
            // is recorded as metadata rather than shown under the video.
            $this->videoResponder->sendVideo(
                $message,
                $response->getFirstVideoAsMp4(),
                $videoPrompt->userPrompt,
                $this->processedPromptToRecord($videoPrompt, $finalPrompt),
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate video:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());
            ReactionSetter::setMessageReaction($message, '🤔');
        }

        return new ProcessingResult(null, true);
    }

    private function preprocessForImg2Video(int $userId, VideoGenerationPrompt $videoPrompt, ProgressUpdateCallback $progressUpdateCallback): string
    {
        return $this->preprocessForModel($this->img2VideoModelPreference, $this->img2videoModelsConfig, $userId, $videoPrompt, $progressUpdateCallback);
    }

    private function preprocessForTxt2Video(int $userId, VideoGenerationPrompt $videoPrompt, ProgressUpdateCallback $progressUpdateCallback): string
    {
        return $this->preprocessForModel($this->videoModelPreference, $this->videoModelsConfig, $userId, $videoPrompt, $progressUpdateCallback);
    }

    /**
     * The model-specific prompt the preprocessor rewrote, or null when no
     * rewriting happened (no preprocessor, or it returned the prompt unchanged).
     * Recorded as metadata so the caption can show the original user idea.
     */
    private function processedPromptToRecord(VideoGenerationPrompt $videoPrompt, string $finalPrompt): ?string
    {
        return $finalPrompt !== $videoPrompt->userPrompt ? $finalPrompt : null;
    }

    /**
     * Resolves the preprocessor for the given model family and runs it, falling
     * back to the raw user prompt when there is no preprocessor or it fails.
     */
    private function preprocessForModel(
        UserPreferenceReaderInterface $preference,
        array $config,
        int $userId,
        VideoGenerationPrompt $videoPrompt,
        ProgressUpdateCallback $progressUpdateCallback,
    ): string {
        $preprocessor = $this->preprocessorFactory->createForModelPreference($preference, $config, $userId);
        if ($preprocessor === null) {
            return $videoPrompt->userPrompt;
        }

        $progressUpdateCallback(static::class, 'Preprocessing video prompt');
        try {
            return $preprocessor->preprocess($videoPrompt, $progressUpdateCallback);
        } catch (Exception $e) {
            $this->logger->log(LogLevel::WARNING, 'Prompt preprocessing failed, using the raw prompt: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return $videoPrompt->userPrompt;
        }
    }

    private function abortWith(InternalMessage $message, string $text): ProcessingResult
    {
        return new ProcessingResult(InternalMessage::asResponseTo($message, $text), true);
    }

    /**
     * The user's selected img2video model name (null when unset/unknown).
     */
    private function selectedModelName(int $userId): ?string
    {
        return $this->img2VideoModelPreference->getCurrentPreferenceValue($userId);
    }

    /**
     * The selected img2video model's config entry, falling back to the first
     * configured entry when the user has no preference or it is unknown.
     */
    private function selectedImg2VideoEntry(int $userId): ?array
    {
        $modelName = $this->selectedModelName($userId);
        if ($modelName !== null && isset($this->img2videoModelsConfig[$modelName])) {
            return $this->img2videoModelsConfig[$modelName];
        }

        return current($this->img2videoModelsConfig) ?: null;
    }
}
