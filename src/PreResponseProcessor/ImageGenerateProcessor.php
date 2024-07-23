<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\Prompt2ImgGenerator;

class ImageGenerateProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly Prompt2ImgGenerator $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
        private readonly PhotoImg2ImgProcessor $photoImg2ImgProcessor,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        $messageText = $message->getText();
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($messageText, $triggeringCommand)) {
                $prompt = trim(str_replace($triggeringCommand, '', $messageText));
                break;
            }
        }
        if (!isset($prompt)) {
            return false;
        }
        if ($prompt === '') {
            return 'Непонятно, что генерировать...';
        }

        if ($message->getReplyToMessage() !== null) {
            if ($message->getReplyToMessage()->getPhoto() === null) {
                return 'Не вижу фото в сообщении на которое ответ. Чтобы сгенерировать новое изображение, повторите команду без ответа';
            }
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
            $response = $this->automatic1111APiClient->generateByPromptTxt2Img($prompt, $message->getFrom()->getId());
            $this->photoResponder->sendPhoto(
                $message,
                $response->getFirstImageAsPng(),
                $response->getCaption()
            );
        } catch (\Exception $e) {
            echo "Failed to generate image:\n" . $e->getTraceAsString() . "\n";
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
