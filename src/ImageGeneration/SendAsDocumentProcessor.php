<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class SendAsDocumentProcessor implements MessageChainProcessor
{

    public function __construct(
        private readonly CacheFileManager $cacheFileManager,
        private readonly Database $database,
    )
    {

    }
    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        $messageWithPhoto = $messageChain->previous();
        $lastMessage = $messageChain->last();
        if ($messageWithPhoto === null) {
            return new ProcessingResult(InternalMessage::asResponseTo($lastMessage, 'Используйте эту команду в ответ на фото'), true);
        }
        if ($messageWithPhoto->photoFileId === null) {
            return new ProcessingResult(InternalMessage::asResponseTo($lastMessage, 'Сообщение на которое вы отвечаете не содержит файла'), true);
        }

        $fileContents = $this->cacheFileManager->readFileFromCache($messageWithPhoto->photoFileId);
        if ($fileContents === null) {
            return new ProcessingResult(InternalMessage::asResponseTo($lastMessage,'У меня нет оригинального файла, относящегося к этому сообщению 😞'), true);
        }
        $options = [
            'chat_id'          => $lastMessage->chatId,
            'reply_parameters' => [
                'message_id' => $lastMessage->id,
            ],
        ];
        $tmpFilePath = tempnam(sys_get_temp_dir(), 'v89-' );
        $fileSuffix = '.' . $this->guessFileExtension($fileContents);
        $tmpFilePathWithSuffix = $tmpFilePath . $fileSuffix;
        rename($tmpFilePath, $tmpFilePathWithSuffix);
        $tmpFilePath = $tmpFilePathWithSuffix;
        $putResult = file_put_contents($tmpFilePath, $fileContents);
        if ($putResult === false) {
            throw new \RuntimeException("Failed to write to $tmpFilePath");
        }
        $encodedFile = Request::encodeFile($tmpFilePath);
        $options['document'] = $encodedFile;
        $progressUpdateCallback(static::class, 'Sending a file (' . strlen($fileContents) . ' bytes)');

        $sentMessageResult = Request::sendDocument($options);
        if ($sentMessageResult->isOk() && $sentMessageResult->getResult() instanceof Message) {
            $this->database->logMessage($sentMessageResult->getResult());
        } else {
            echo "Failed to send file: " . $sentMessageResult->getResult() . "\n";
            return new ProcessingResult(null, true, '🤔', $lastMessage);
        }
        echo "Deleting $tmpFilePath\n";
        unlink($tmpFilePath);

        return new ProcessingResult(null, true);
    }
    private function generateRandomString(int $length): string {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }
    private function guessFileExtension(string $fileContents): string
    {
        $dataLength = strlen($fileContents);

        // JPEG: FF D8 FF
        if (
            $dataLength >= 3 &&
            $fileContents[0] === "\xFF" &&
            $fileContents[1] === "\xD8" &&
            $fileContents[2] === "\xFF"
        ) {
            return 'jpg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (
            $dataLength >= 8 &&
            $fileContents[0] === "\x89" &&
            $fileContents[1] === 'P' &&
            $fileContents[2] === 'N' &&
            $fileContents[3] === 'G' &&
            $fileContents[4] === "\r" &&
            $fileContents[5] === "\n" &&
            $fileContents[6] === "\x1A" &&
            $fileContents[7] === "\n"
        ) {
            return 'png';
        }

        // WebP: "RIFF" .... "WEBP"
        if (
            $dataLength >= 12 &&
            $fileContents[0] === 'R' &&
            $fileContents[1] === 'I' &&
            $fileContents[2] === 'F' &&
            $fileContents[3] === 'F' &&
            $fileContents[8] === 'W' &&
            $fileContents[9] === 'E' &&
            $fileContents[10] === 'B' &&
            $fileContents[11] === 'P'
        ) {
            return 'webp';
        }

        return 'unknown';
    }

}
