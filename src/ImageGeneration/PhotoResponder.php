<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;

class PhotoResponder
{
    public function sendPhoto(Message|InternalMessage $message, string $photoContents, ?string $caption = null): void
    {
        if ($message instanceof Message) {
            $message = InternalMessage::fromTelegramMessage($message);
        }
        $imagePath = tempnam(sys_get_temp_dir(), 'viktor89-image-generator');
        echo "Temporary image recorded to $imagePath\n";
        file_put_contents($imagePath, $photoContents);
        $options = [
            'chat_id'          => $message->chatId,
            'reply_parameters' => [
                'message_id' => $message->id,
            ],
            'photo'            => Request::encodeFile($imagePath),
        ];
        if ($caption !== null) {
            $options['caption'] = mb_substr($caption, 0, 1024);
        }
        Request::sendPhoto($options);
        echo "Deleting $imagePath\n";
        unlink($imagePath);
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '😎',
                ],
            ],
        ]);
    }
}
