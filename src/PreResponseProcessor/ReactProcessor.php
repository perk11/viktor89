<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

class ReactProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $enabledProcessor,
        private readonly string $reactEmoji,
    )
    {
    }
    public function process(Message $message): false|string|null
    {
        if ($this->enabledProcessor->getCurrentPreferenceValue($message->getFrom()->getId()) === null) {
            return false;
        }

        Request::execute('setMessageReaction', [
            'chat_id'    => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => $this->reactEmoji,
                ],
            ],
        ]);

        return false;
    }
}
