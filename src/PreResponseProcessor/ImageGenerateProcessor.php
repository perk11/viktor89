<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Automatic1111APiClient;

class ImageGenerateProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly array $triggeringCommands,
        private readonly Automatic1111APiClient $automatic1111APiClient,
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
            $image = $this->automatic1111APiClient->getPngContentsByPromptTxt2Img($prompt);
            $imagePath = tempnam(sys_get_temp_dir(), 'viktor89-image-generator');
            echo "Temporary image recorded to $imagePath\n";
            file_put_contents($imagePath, $image);
            Request::sendPhoto([
                                   'chat_id'          => $message->getChat()->getId(),
                                   'reply_parameters' => [
                                       'message_id' => $message->getMessageId(),
                                   ],
                                   'photo'            => Request::encodeFile($imagePath),
                               ]);
            echo "Deleting $imagePath\n";
            unlink($imagePath);
            Request::execute('setMessageReaction', [
                'chat_id'    => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '😎',
                    ],
                ],
            ]);
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
