<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class ImageGenerateProcessor implements MessageChainProcessor
{
    private const IMG_REGEX = '/<img>(.*?)<\/img>/s';
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly ImageByPromptGenerator $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
        private readonly PhotoImg2ImgProcessor $photoImg2ImgProcessor,
        private readonly ImageRepository $imageRepository,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $triggerFound = false;
        $lastMessage = $messageChain->last();
        $messageText = $lastMessage->messageText;
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($messageText, $triggeringCommand)) {
                $triggerFound = true;
                $prompt = trim(str_replace($triggeringCommand, '', $messageText));
                break;
            }
        }

        if (!$triggerFound) {
            return new ProcessingResult(null, false);
        }
        if ($messageChain->count() > 1 && $messageChain->previous()->photoFileId === null) {
            $prompt = trim($messageChain->getMessages()[$messageChain->count() - 2]->messageText . "\n\n" . $prompt);
        }
        if ($prompt === '') {
            $message = InternalMessage::asResponseTo(
                $lastMessage,
                'Непонятно, что генерировать...',
            );
            return new ProcessingResult($message, true);
        }

        if ($messageChain->previous()?->photoFileId !== null) {
            $this->photoImg2ImgProcessor->respondWithImg2ImgResultBasedOnPhotoInMessage($messageChain->previous(), $lastMessage, $prompt);
            return new ProcessingResult(null, true);
        }
        echo "Generating image for prompt: $prompt\n";

        $processingResult = $this->processPromptImgReplacementsAndUseImg2ImgIfTheyArePresent($prompt, $lastMessage);
        if ($processingResult->abortProcessing) {
            return $processingResult;
        }
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
            $response = $this->automatic1111APiClient->generateImageByPrompt($prompt, $lastMessage->userId);
            $this->photoResponder->sendPhoto(
                $lastMessage,
                $response->getFirstImageAsPng(),
                $response->sendAsFile,
                $response->getCaption()
            );
        } catch (\Exception $e) {
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

    private function processPromptImgReplacementsAndUseImg2ImgIfTheyArePresent(
        string $prompt,
        InternalMessage $lastMessage
    ): ProcessingResult {
        if (preg_match(self::IMG_REGEX, $prompt) === 0) {
            return new ProcessingResult(null, false);
        }
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
        $images = [];
        try {
            $processedPrompt = preg_replace_callback(
                self::IMG_REGEX,
                function ($matches) use (&$images) {
                    $savedImage = $this->imageRepository->retrieve($matches[1]);
                    if ($savedImage === null) {
                        throw new SavedImageNotFoundException($matches[1]);
                    }
                    $images[] = $savedImage;

                    return '<img><|image_' . (count($images)) . '|></img>';
                },
                $prompt,
            );
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

        echo "Prompt changed to $processedPrompt\n";
        try {
            $response = $this->automatic1111APiClient->generateImageByPromptAndImages(
                $images,
                $processedPrompt,
                $lastMessage->userId,
            );
            $this->photoResponder->sendPhoto(
                $lastMessage,
                $response->getFirstImageAsPng(),
                $response->sendAsFile,
                $response->getCaption(),
            );
        } catch (\Exception $e) {
            echo "Failed to generate image with prompt replacement:\n" . $e->getMessage() . "\n" . $e->getTraceAsString(
                ) . "\n";
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
}
