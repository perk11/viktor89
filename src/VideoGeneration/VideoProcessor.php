<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class VideoProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly Txt2VideoClient $txt2VideoClient,
        private readonly VideoResponder $videoResponder,
        private readonly VideoImg2VidProcessor $videoImg2ImgProcessor,
        private readonly AltTextProvider $altTextProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $prompt = trim($message->messageText);
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($messageChain->previous()->messageText);
        }
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($this->altTextProvider->provide($messageChain->previous(), $progressUpdateCallback));
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что генерировать...';
            return new ProcessingResult($response, true);
        }
        if ($messageChain->previous()?->photoFileId !== null) {
            $this->videoImg2ImgProcessor->respondWithImg2VidResultBasedOnPhotoInMessage($messageChain->previous() , $message, $prompt, $progressUpdateCallback);
            return new ProcessingResult(null, true);
        }
        $progressUpdateCallback(static::class,"Generating video for prompt: $prompt",  new ChatAction($message->chatId, ChatActionEnum::upload_video));
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);
        try {
            $response = $this->txt2VideoClient->generateByPromptTxt2Vid($prompt, $message->userId);
            $progressUpdateCallback(static::class,"Sending video response");
            $this->videoResponder->sendVideo(
                $message,
                $response->getFirstVideoAsMp4(),
                $response->getCaption()
            );
        } catch (Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to generate video:\n" . $e->getMessage() . "\n" . $e->getTraceAsString());
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

        return new ProcessingResult(null, true);
    }
}
