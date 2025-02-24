<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;

class PhotoResponder
{
    public function sendPhoto(Message|InternalMessage $message, string $photoContents, bool $sendAsWebp, ?string $caption = null): void
    {
        if ($message instanceof Message) {
            $message = InternalMessage::fromTelegramMessage($message);
        }
        $filePrefix = mb_substr(preg_replace('/[^a-zA-Z]/', '_', $caption), 0, 50);
        while (str_contains($filePrefix, '__')) {
            $filePrefix = str_replace('__', '_', $filePrefix);
        }
        $filePrefix .= '_';
        $imagePath = tempnam(sys_get_temp_dir(), 'v89-ig-' . $filePrefix);
        if ($sendAsWebp) {
            rename($imagePath, $imagePath .= '.webp');
            $image = imagecreatefromstring($photoContents);
            imagewebp($image, $imagePath, 80);
            imagedestroy($image);
        } else {
            file_put_contents($imagePath, $photoContents);
        }
        echo "Temporary image recorded to $imagePath\n";

        $options = [
            'chat_id'          => $message->chatId,
            'reply_parameters' => [
                'message_id' => $message->id,
            ],
        ];
        if ($caption !== null) {
            $options['caption'] = mb_substr($caption, 0, 1024);
        }

        $encodedFile = Request::encodeFile($imagePath);
        if ($sendAsWebp) {
            echo "Sending document response\n";
            $options['document'] = $encodedFile;
            Request::sendDocument($options);
        } else {
            echo "Sending photo response\n";
            $options['photo'] = $encodedFile;
            Request::sendPhoto($options);
        }
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
