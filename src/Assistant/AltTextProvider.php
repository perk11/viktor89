<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\NothingToTranscribeException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AltTextProvider
{
    public AssistantInterface $assistantWithVision;

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly InternalMessageTranscriber $internalMessageTranscriber,
        private readonly MessageRepository $messageRepository,
        private readonly LoggerInterface $logger,
    )
    {

    }
    public function provide(InternalMessage $internalMessage, ProgressUpdateCallback $progressUpdateCallback): ?string
    {
        if ($internalMessage->altText !== null) {
            return $internalMessage->altText;
        }

        if ($internalMessage->photoFileId !== null) {
            try {
                $image = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($internalMessage);
            } catch (\Exception $e) {
                $this->logger->log(LogLevel::ERROR, 'Failed to download image ' . $internalMessage->photoFileId . ': ' . $e->getMessage());
                return null;
            }
            $progressUpdateCallback(static::class,"Generating alt text for photo " . $internalMessage->photoFileId);
            $altText = $this->describeImageString($image);
            $internalMessage->altText = $altText;
            $this->messageRepository->logInternalMessage($internalMessage);
            return $altText;
        }
        try {
            $this->internalMessageTranscriber->transcribe($internalMessage, $progressUpdateCallback);
            return $internalMessage->altText;
        } catch (NothingToTranscribeException) {
        }

        return null;
    }

    /**
     * Describe a raw image blob (e.g. a tool-generated PNG) with the vision
     * assistant, so non-vision models can still learn what was produced.
     * Unlike provide(), no Telegram photoFileId or persistence is needed.
     */
    public function generateAltTextForImageString(string $image, ?ProgressUpdateCallback $progressUpdateCallback = null): string
    {
        if ($progressUpdateCallback !== null) {
            $progressUpdateCallback(static::class, 'Generating alt text for generated image');
        }

        return $this->describeImageString($image);
    }

    private function describeImageString(string $image): string
    {
        if (!isset($this->assistantWithVision)) {
            throw new \LogicException("assistantWithVision must be set on AltTextProvider before attempting to generate alt text for an image");
        }
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = 'You are describing the image sent by the user to another LLM. Be as detailed as possible, as it is unknown how the other will need to use the answer, describe the image from multiple perspectives.';
        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->photo = $image;
        $message->text = 'Describe this image. Your responses will be used as is, there will be no follow up.Be as detailed as possible, but do not add anything that is not related to the image. Open with image description, your first word should already describe the image. Do not break description into sections. Put the description on a single line';
        $assistantContext->messages[] = $message;

        return '[image] ' . $this->assistantWithVision->getCompletionBasedOnContext($assistantContext)->content;
    }
}
