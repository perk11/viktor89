<?php

namespace Perk11\Viktor89\VideoGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageMetadata;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\Telegram\ReactionReplacer;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VideoResponder
{
    public function __construct(
        private readonly ReactionReplacer $reactionReplacer,
        private readonly LoggerInterface $logger,
        private readonly ?MessageRepository $messageRepository = null,
        private readonly ?MessageMetadataRepository $messageMetadataRepository = null,
    ) {
    }

    /**
     * @param string|null $caption            The prompt shown under the video.
     *                                        For preprocessed generations this is
     *                                        the original user prompt, not the
     *                                        rewritten one.
     * @param string|null $processedPrompt The model-specific prompt the
     *                                        preprocessor rewrote from the user
     *                                        idea (recorded as metadata, never
     *                                        shown as the caption).
     */
    public function sendVideo(
        InternalMessage $message,
        string $videoContents,
        ?string $caption = null,
        ?string $processedPrompt = null,
    ): void {
        $videoPath = tempnam(sys_get_temp_dir(), 'viktor89-video-generator');
        $this->logger->log(LogLevel::INFO, "Temporary video recorded to $videoPath");
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
        $sentMessageResult = Request::sendVideo($options);
        $sentMessage = $sentMessageResult->isOk() ? $sentMessageResult->getResult() : null;
        if ($sentMessage instanceof Message) {
            $this->messageRepository?->logMessage($sentMessage);
            if (
                $this->messageMetadataRepository !== null
                && ($caption !== null || $processedPrompt !== null)
            ) {
                $this->messageMetadataRepository->insert(new MessageMetadata(
                    $message->chatId,
                    $sentMessage->getMessageId(),
                    null,
                    null,
                    null,
                    $caption,
                    $processedPrompt,
                ));
            }
        }
        $this->logger->log(LogLevel::INFO, "Deleting $videoPath");
        unlink($videoPath);
        $this->reactionReplacer->deleteOrReplaceWith($message->chatId, $message->id, '😎');
    }
}
