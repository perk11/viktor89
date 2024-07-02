<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Automatic1111APiClient;
use Perk11\Viktor89\PhotoResponder;

class ImageGenerateProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly Automatic1111APiClient $automatic1111APiClient,
        private readonly PhotoResponder $photoResponder,
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
            echo "Failed to generate image:\n" . $e->getMessage(),
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
