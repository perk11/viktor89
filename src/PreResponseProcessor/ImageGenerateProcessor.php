<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Exception;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\GetTriggeringCommandsInterface;
use Perk11\Viktor89\ImageGeneration\ImageByPromptAndImageGenerator;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class ImageGenerateProcessor implements MessageChainProcessor, GetTriggeringCommandsInterface
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly ImageByPromptGenerator&ImageByPromptAndImageGenerator $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly UserPreferenceReaderInterface $imageModelPreference,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $triggerFound = false;
        $lastMessage = $messageChain->last();
        $messageText = $lastMessage->messageText;
        $promptText = '';
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($messageText, $triggeringCommand)) {
                $triggerFound = true;
                $promptText = trim(str_replace($triggeringCommand, '', $messageText));
                break;
            }
        }

        if (!$triggerFound) {
            return new ProcessingResult(null, false);
        }
        if ($messageChain->count() > 1 && $messageChain->previous()->photoFileId === null) {
            $promptText = trim($messageChain->previous()->messageText . "\n\n" . $promptText);
        }
        if ($promptText === '') {
            $message = InternalMessage::asResponseTo(
                $lastMessage,
                'Непонятно, что генерировать...',
            );
            return new ProcessingResult($message, true);
        }
        $prompt = new ImageGenerationPrompt($promptText);
        if ($messageChain->previous()?->photoFileId !== null) {
            $progressUpdateCallback(static::class, "Downloading source photo");
            try {
                $prompt->sourceImagesContents[] = $this->telegramFileDownloader->downloadPhotoFromInternalMessage(
                    $messageChain->previous()
                );
            } catch (Exception $e) {
                echo "Failed to download source image from Telegram: " . $e->getMessage() . "\n";
                return new ProcessingResult(null, true, '🤔', $messageChain->previous());
            }
        }
        $modelName = $this->imageModelPreference->getCurrentPreferenceValue($lastMessage->userId);
        try {
            $prompt = $this->imgTagExtractor->extractImageTags($prompt, $modelName);
        } catch (SavedImageNotFoundException $e) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $lastMessage,
                    sprintf(
                        'Изображение с именем "%s" не найдено, создайте его используя команду /saveas',
                        $e->getMessage()
                    )
                ), true
            );
        }
        $progressMessage = "Generating image for prompt: $promptText";
        if (count($prompt->sourceImagesContents) > 0) {
            $progressMessage .= " and " . count($prompt->sourceImagesContents) . " source images";
        }
        $progressUpdateCallback(static::class, $progressMessage);
        Request::execute('setMessageReaction', [
            'chat_id'    => $lastMessage->chatId,
            'message_id' => $lastMessage->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            if (count($prompt->sourceImagesContents) === 0) {
                $response = $this->automatic1111APiClient->generateImageByPrompt($prompt->text, $lastMessage->userId);
            } else {
                $response = $this->automatic1111APiClient->generateImageByPromptAndImages(
                    $prompt,
                    $lastMessage->userId
                );
            }
            $progressUpdateCallback(static::class, "Sending photo response");
            $this->photoResponder->sendPhoto(
                $lastMessage,
                $response->getFirstImageAsPng(),
                $response->sendAsFile,
                $response->getCaption()
            );
        } catch (Exception $e) {
            echo "Failed to generate image:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            Request::execute('setMessageReaction', [
                'chat_id'    => $lastMessage->chatId,
                'message_id' => $lastMessage->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }

        return new ProcessingResult(null, true);
    }

    public function getTriggeringCommands(): array
    {
        return $this->triggeringCommands;
    }
}
