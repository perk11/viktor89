<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class PhotoResponder
{
    public function sendPhoto(Message $message, string $photoContents): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'viktor89-image-generator');
        echo "Temporary image recorded to $imagePath\n";
        file_put_contents($imagePath, $photoContents);
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
    }
}
