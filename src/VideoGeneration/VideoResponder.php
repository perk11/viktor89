<?php

namespace Perk11\Viktor89\VideoGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;

class VideoResponder
{
    public function sendVideo(InternalMessage $message, string $videoContents, ?string $caption = null): void
    {
        $videoPath = tempnam(sys_get_temp_dir(), 'viktor89-video-generator');
        echo "Temporary video recorded to $videoPath\n";
        file_put_contents($videoPath, $videoContents);
        $options = [
            'chat_id'          => $message->chatId,
            'reply_parameters' => [
                'message_id' => $message->id,
            ],
            'video'            => Request::encodeFile($videoPath),
        ];
        if ($caption !== null) {
            $options['caption'] = mb_substr($caption, 0, 1024);
        }
        Request::sendVideo($options);
        echo "Deleting $videoPath\n";
//        unlink($videoPath);
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
