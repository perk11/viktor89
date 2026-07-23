<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageMetadata;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\Telegram\ReactionReplacer;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class PhotoResponder
{

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly CacheFileManager $cacheFileManager,
        private readonly ReactionReplacer $reactionReplacer,
        private readonly ?MessageMetadataRepository $messageMetadataRepository = null,
        private readonly ?LoggerInterface $logger = null,
    )
    {
    }

    public function sendPhoto(Message|InternalMessage $message, string $photoContents, bool $sendAsWebp, ?string $caption = null): void
    {
        if ($message instanceof Message) {
            $message = InternalMessage::fromTelegramMessage($message);
        }
        $filePrefix = mb_substr(preg_replace('/[^a-zA-Z]/', '_', $caption ?? ''), 0, 50);
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
        $this->logger?->log(LogLevel::INFO, "Temporary image recorded to $imagePath");

        $options = [
            'chat_id'          => $message->chatId,
            'reply_parameters' => [
                'message_id' => $message->id,
            ],
        ];
        if ($caption !== null) {
            if ($this->needsSpoiler($caption)) {
                $options['has_spoiler'] = true;
            }

            $options['caption'] = mb_substr($caption, 0, 1024);
        }

        $encodedFile = Request::encodeFile($imagePath);
        if ($sendAsWebp) {
            $this->logger?->log(LogLevel::INFO, 'Sending document response');
            $options['document'] = $encodedFile;
            $sentMessageResult = Request::sendDocument($options);
        } else {
            $this->logger?->log(LogLevel::INFO, 'Sending photo response');
            $options['photo'] = $encodedFile;
            $sentMessageResult = Request::sendPhoto($options);
        }
        $sendOk = $sentMessageResult->isOk() && $sentMessageResult->getResult() instanceof Message;
        if ($sendOk) {
            $this->messageRepository->logMessage($sentMessageResult->getResult());
            if ($this->messageMetadataRepository !== null && $caption !== null) {
                $sentMessage = $sentMessageResult->getResult();
                $this->messageMetadataRepository->insert(new MessageMetadata(
                    $message->chatId,
                    $sentMessage->getMessageId(),
                    null,
                    null,
                    null,
                    $caption,
                ));
            }
            $photos = $sentMessageResult->getResult()->getPhoto();
            if (is_array($photos)) {
                foreach ($photos as $photo) {
                    $fileId = $photo->getFileId();
                    $this->cacheFileManager->writeFileToCache($fileId, $photoContents);
                }
            } elseif ($sentMessageResult->getResult()->getSticker() !== null) {
                $this->cacheFileManager->writeFileToCache($sentMessageResult->getResult()->getSticker()->getFileId(), $photoContents);
            } else {
                $this->logger?->log(LogLevel::WARNING, "Unexpected, Telegram server response doesn't contain a photo or a document, not caching the result");
            }
        } else {
            $this->logger?->log(LogLevel::ERROR, 'Failed to send message: ' . $sentMessageResult->getResult());
        }
        $this->logger?->log(LogLevel::INFO, "Deleting $imagePath");
        unlink($imagePath);
        if ($sendOk) {
            $this->reactionReplacer->deleteOrReplaceWith($message->chatId, $message->id, '😎');
        } else {
            Request::execute('setMessageReaction', [
                'chat_id'    => $message->chatId,
                'message_id' => $message->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }
    }

    private function needsSpoiler(string $caption): bool
    {
        $spoilerWords = file(__DIR__ . '/../../spoiler_words.txt');
        if ($spoilerWords === false) {
            $this->logger?->log(LogLevel::ERROR, 'Failed to read spoiler words list');

            return false;
        }
        $spoilerWords = array_map('trim', $spoilerWords);
        $wordsInCaption = preg_split('/\s+/', trim($caption));
        foreach ($wordsInCaption as $word) {
            if (in_array(mb_strtolower($word), $spoilerWords, true)) {
                return true;
            }
        }
        return false;
    }
}
