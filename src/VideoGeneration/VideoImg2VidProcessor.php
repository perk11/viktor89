<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Perk11\Viktor89\Util\Telegram\ReactionSetter;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VideoImg2VidProcessor
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly Img2VideoClient $img2VideoClient,
        private readonly VideoResponder $videoResponder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function respondWithImg2VidResultBasedOnPhotoInMessage(
        InternalMessage $messageWithPhoto,
        InternalMessage $messageWithCommand,
        string $prompt,
        ProgressUpdateCallback $progressUpdateCallback,
    ): void
    {
        $progressUpdateCallback(static::class, "Downloading source photo for prompt: $prompt\n");
        $photoContents = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($messageWithPhoto);
        $this->respondWithImg2VidResult($messageWithCommand, $photoContents, $prompt, $progressUpdateCallback);
    }

    /**
     * Generates an img2vid video from already-downloaded first-frame image bytes.
     * Exposed so callers that have already resolved the image (e.g. /video,
     * which needs the bytes for prompt preprocessing) can reuse them instead of
     * downloading twice. $modelName overrides the user's selected model (used to
     * fall back to a last-frame-capable model when a last frame is supplied).
     */
    public function respondWithImg2VidResult(
        InternalMessage $messageWithCommand,
        string $imageContents,
        string $prompt,
        ProgressUpdateCallback $progressUpdateCallback,
        ?string $modelName = null,
    ): void {
        ReactionSetter::setMessageReaction($messageWithCommand, '👀');
        try {
            $progressUpdateCallback(static::class, "Generating img2vid for prompt: $prompt\n",  new ChatAction($messageWithCommand->chatId, ChatActionEnum::record_video));
            $videoResponse = $this->img2VideoClient->generateByPromptImg2Vid(
                $imageContents,
                $prompt,
                $messageWithCommand->userId,
                $modelName,
            );
            $progressUpdateCallback(static::class, "Sending video for prompt: $prompt\n",  new ChatAction($messageWithCommand->chatId,ChatActionEnum::upload_video));
            $this->videoResponder->sendVideo(
                $messageWithCommand,
                $videoResponse->getFirstVideoAsMp4(),
                $videoResponse->getCaption(),
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate video:\n" . $e->getMessage());
            ReactionSetter::setMessageReaction($messageWithCommand, '🤔');
        }
    }
}
