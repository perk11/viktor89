<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class ImageGenerateProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly ImageByPromptGenerator $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
        private readonly PhotoImg2ImgProcessor $photoImg2ImgProcessor,
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
        if ($messageChain->count() > 1 && $lastMessage->replyToPhoto === null) {
            $prompt = trim($messageChain->getMessages()[$messageChain->count() - 2]->messageText . "\n\n" . $prompt);
        }
        if ($prompt === '') {
            $message = InternalMessage::asResponseTo($lastMessage);
            $message->messageText = 'Непонятно, что генерировать...';
            return new ProcessingResult($message, true);
        }

        if ($lastMessage->replyToPhoto !== null) {
            $this->photoImg2ImgProcessor->respondWithImg2ImgResultBasedOnPhotoInMessage($lastMessage->replyToPhoto, $lastMessage, $prompt);
            return new ProcessingResult(null, true);
        }
        echo "Generating image for prompt: $prompt\n";
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
}
