<?php

namespace Perk11\Viktor89\VideoGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class VideoProcessor implements TelegramChainBasedResponderInterface
{
    public function __construct(
        private readonly Txt2VideoClient $txt2VideoClient,
        private readonly VideoResponder $videoResponder,
    ) {
    }

    public function getResponseByMessageChain(array $messageChain): ?InternalMessage
    {
        /** @var ?InternalMessage $lastMessage */
        $message = $messageChain[count($messageChain) - 1];
        $prompt = $message->messageText;
        if (count($messageChain) > 1 ) {
            $prompt = trim($messageChain[count($messageChain) - 2]->messageText. "\n\n" . $prompt);
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что генерировать...';
            return $response;
        }

        echo "Generating video for prompt: $prompt\n";
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
            $this->videoResponder->sendVideo(
                $message,
                $response->getFirstVideoAsMp4(),
                $response->getCaption()
            );
        } catch (\Exception $e) {
            echo "Failed to generate video:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
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

        return null;
    }
}
