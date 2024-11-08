<?php
namespace Perk11\Viktor89\VoiceRecognition;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramFileDownloader;

class InternalMessageTranscriber
{
    public function __construct(
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly VoiceRecogniser $voiceRecogniser,
    ) {
    }
    public function transcribe(InternalMessage $message): ?string
    {
        if ($message->audio !== null) {
            $fileId =  $message->audio->getFileId();
            $fileName = $message->audio->getFileName();
        } elseif ($message->video !== null) {
            $fileId =  $message->video->getFileId();
            $fileName = $message->video->getFileName();
        } elseif ($message->voice !== null) {
            $fileId =  $message->voice->getFileId();
            $fileName = 'voice.ogg';
        } elseif ($message->videoNote !== null) {
            $fileId =  $message->videoNote->getFileId();
            $fileName = 'video.mp4';
        } else {
            throw new NothingToTranscribeException('Message does not contain audio, video, voice or video note');
        }
        echo "Transcribing file $fileName with fileId $fileId\n";
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        $file = $this->telegramFileDownloader->downloadFile($fileId);
        $recognizedText = $this->voiceRecogniser->recogniseByFileContents($file, $extension);

        return $recognizedText === '' ? null : $recognizedText;
    }
}
