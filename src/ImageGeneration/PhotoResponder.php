<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;

class PhotoResponder
{

    public function __construct(private readonly Database $database, private readonly CacheFileManager $cacheFileManager)
    {
    }

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
            $sentMessageResult = Request::sendDocument($options);
        } else {
            echo "Sending photo response\n";
            $options['photo'] = $encodedFile;
            $sentMessageResult = Request::sendPhoto($options);
        }
        if ($sentMessageResult->isOk() && $sentMessageResult->getResult() instanceof Message) {
            $this->database->logMessage($sentMessageResult->getResult());
            $photos = $sentMessageResult->getResult()->getPhoto();
            if (is_array($photos) && array_key_exists(2, $photos)) {
                $fileId = $sentMessageResult->getResult()->getPhoto()[2]->getFileId();
                $this->cacheFileManager->writeFileToCache($fileId, $photoContents);
            } else {
                echo "Unexpected, Telegram server response doesn't contain a photo, not caching the result\n";
            }
        } else {
            echo "Failed to send message: " . $sentMessageResult->getResult() . "\n";
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
