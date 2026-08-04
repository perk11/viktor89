<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Perk11\Viktor89\Util\Telegram\ReactionSetter;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessor;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessorFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VideoProcessor implements MessageChainProcessor
{
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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $prompt = trim($message->messageText);
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($messageChain->previous()->messageText);
        }
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($this->altTextProvider->provide($messageChain->previous(), $progressUpdateCallback));
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что генерировать...';
            return new ProcessingResult($response, true);
        }
        if ($messageChain->previous()?->photoFileId !== null) {
            $preprocessor = $this->preprocessorFactory->createForModelPreference(
                $this->img2VideoModelPreference,
                $this->img2videoModelsConfig,
                $message->userId,
            );
            if ($preprocessor !== null) {
                $photo = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($messageChain->previous());
                $prompt = $this->preprocess($preprocessor, $prompt, $photo, $message->chatId, $progressUpdateCallback);
            }
            $this->videoImg2ImgProcessor->respondWithImg2VidResultBasedOnPhotoInMessage($messageChain->previous(), $message, $prompt, $progressUpdateCallback);
            return new ProcessingResult(null, true);
        }
        $preprocessor = $this->preprocessorFactory->createForModelPreference(
            $this->videoModelPreference,
            $this->videoModelsConfig,
            $message->userId,
        );
        if ($preprocessor !== null) {
            $prompt = $this->preprocess($preprocessor, $prompt, null, $message->chatId, $progressUpdateCallback);
        }
        $progressUpdateCallback(static::class, "Generating video for prompt: $prompt", new ChatAction($message->chatId, ChatActionEnum::upload_video));
        ReactionSetter::setMessageReaction($message, '👀');
        try {
            $response = $this->txt2VideoClient->generateByPromptTxt2Vid($prompt, $message->userId);
            $progressUpdateCallback(static::class, "Sending video response");
            $this->videoResponder->sendVideo(
                $message,
                $response->getFirstVideoAsMp4(),
                $response->getCaption()
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate video:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());
            ReactionSetter::setMessageReaction($message, '🤔');
        }

        return new ProcessingResult(null, true);
    }

    private function preprocess(
        VideoPromptPreprocessor $preprocessor,
        string $prompt,
        ?string $image,
        int $chatId,
        ProgressUpdateCallback $progressUpdateCallback,
    ): string {
        $progressUpdateCallback(static::class, 'Preprocessing video prompt');
        try {
            return $preprocessor->preprocess(
                new VideoGenerationPrompt($prompt, firstFrame: $image),
                $progressUpdateCallback,
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::WARNING, 'Prompt preprocessing failed, using the raw prompt: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return $prompt;
        }
    }
}
