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
        if ($message->audio !== null) {
            $fileId =  $message->audio->getFileId();
            $fileName = $message->audio->getFileName();
            $type = 'audio';
        } elseif ($message->video !== null) {
            $fileId =  $message->video->getFileId();
            $fileName = $message->video->getFileName();
            $type = 'video';
        } elseif ($message->voice !== null) {
            $fileId =  $message->voice->getFileId();
            $fileName = 'voice.ogg';
            $type = 'voice';
        } elseif ($message->videoNote !== null) {
            $fileId =  $message->videoNote->getFileId();
            $fileName = 'video.mp4';
            $type = 'videoNote';
        } else {
            throw new NothingToTranscribeException('Message does not contain audio, video, voice or video note');
        }
        $progressUpdateCallback(static::class,"Transcribing file $fileName with fileId $fileId");
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        $file = $this->telegramFileDownloader->downloadFile($fileId);
        $recognizedText = $this->voiceRecogniser->recogniseByFileContents($file, $extension);

        $message->altText = "[$type] $recognizedText";
        $this->database->logInternalMessage($message);
        return $recognizedText === '' ? null : $recognizedText;
    }
}
