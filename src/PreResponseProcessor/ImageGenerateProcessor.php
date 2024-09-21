<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;

class ImageGenerateProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly ImageByPromptGenerator $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
        private readonly PhotoImg2ImgProcessor $photoImg2ImgProcessor,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        $triggerFound = false;
        $messageText = $message->getText();
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($messageText, $triggeringCommand)) {
                $triggerFound = true;
                $prompt = trim(str_replace($triggeringCommand, '', $messageText));
                break;
            }
        }

        if (!$triggerFound) {
            return false;
        }
        if ($message->getReplyToMessage() !== null && $message->getPhoto() === null) {
            $prompt = trim($message->getReplyToMessage()->getText() . "\n\n" . $prompt);
        }
        if ($prompt === '') {
            return 'Непонятно, что генерировать...';
        }

        if ($message->getReplyToMessage() !== null && $message->getReplyToMessage()->getPhoto() !== null) {
            $this->photoImg2ImgProcessor->respondWithImg2ImgResultBasedOnPhotoInMessage($message->getReplyToMessage(), $message, $prompt);
            return null;
        }
        echo "Generating image for prompt: $prompt\n";
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            $response = $this->automatic1111APiClient->generateImageByPrompt($prompt, $message->getFrom()->getId());
            $this->photoResponder->sendPhoto(
                $message,
                $response->getFirstImageAsPng(),
                $response->getCaption()
            );
        } catch (\Exception $e) {
            echo "Failed to generate image:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            Request::execute('setMessageReaction', [
                'chat_id'    => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }

        return null;
    }
}
