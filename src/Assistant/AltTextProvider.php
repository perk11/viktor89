<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\NothingToTranscribeException;

class AltTextProvider
{
    public AssistantInterface $assistantWithVision;

    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly InternalMessageTranscriber $internalMessageTranscriber,
        private readonly Database $database,
    )
    {

    }
    public function provide(InternalMessage $internalMessage, ProgressUpdateCallback $progressUpdateCallback): ?string
    {
        if ($internalMessage->altText !== null) {
            return $internalMessage->altText;
        }

        if ($internalMessage->photoFileId !== null) {
            if (!isset($this->assistantWithVision)) {
                throw new \LogicException("assistantWithVision must be set on AltTextProvider before attempting to generate alt text for an image");
            }
            $image = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($internalMessage);
            $assistantContext = new AssistantContext();
            $assistantContext->systemPrompt = 'You are describing the image sent by the user to another LLM. Be as detailed as possible, as it is unknown how the other will need to use the answer, describe the image from multiple perspectives.';
            $message = new AssistantContextMessage();
            $message->isUser = true;
            $message->photo = $image;
            $message->text = 'Describe this image. Your responses will be used as is, there will be no follow up.Be as detailed as possible, but do not add anything that is not related to the image. Open with image description, your first word should already describe the image. Do not break description into sections. Put the description on a single line';
            $assistantContext->messages[] = $message;

            $progressUpdateCallback(static::class,"Generating alt text for photo " . $internalMessage->photoFileId);
            $altText = '[image] ' . $this->assistantWithVision->getCompletionBasedOnContext($assistantContext);
            $internalMessage->altText = $altText;
            $this->database->logInternalMessage($internalMessage);
            return $altText;
        }
        try {
            $this->internalMessageTranscriber->transcribe($internalMessage, $progressUpdateCallback);
            return $internalMessage->altText;
        } catch (NothingToTranscribeException) {
        }

        return null;
    }
}
