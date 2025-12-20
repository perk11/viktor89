<?php
namespace Perk11\Viktor89\VoiceRecognition;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\TelegramFileDownloader;

class InternalMessageTranscriber
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly VoiceRecogniser $voiceRecogniser,
        private readonly Database $database,
    ) {
    }
    public function transcribe(InternalMessage $message, ProgressUpdateCallback $progressUpdateCallback): ?string
    {
        if ($message->altText !== null) {
            return $message->altText;
        }
        $audio = $message->getMessageAudio();
        if ($audio === null) {
            throw new NothingToTranscribeException('Message does not contain audio, video, voice or video note');
        }
        $progressUpdateCallback(static::class,"Transcribing file $audio->fileName with fileId $audio->fileId");
        $extension = pathinfo($audio->fileName, PATHINFO_EXTENSION);

        $file = $this->telegramFileDownloader->downloadFile($audio->fileId);
        $recognizedText = $this->voiceRecogniser->recogniseByFileContents($file, $extension);

        $message->altText = "[$audio->type] $recognizedText";
        $this->database->logInternalMessage($message);
        return $recognizedText === '' ? null : $recognizedText;
    }
}
