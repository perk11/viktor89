<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;

class ListChainImagesToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    public function __construct(private readonly int $botUserId)
    {
    }

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        foreach ($arguments as $key => $value) {
            throw new ToolArgumentException("Unsupported argument: $key");
        }

        $images = [];
        foreach ($messageChain->getImages() as $index => $message) {
            $images[] = [
                'id' => $index,
                'reference' => '#' . $index,
                'author' => $this->getAuthorLabel($message),
                'caption' => $message->messageText !== '' ? $message->messageText : null,
                'alt_text' => $message->altText !== '' ? $message->altText : null,
            ];
        }

        return [
            'images' => $images,
            'count' => count($images),
        ];
    }

    private function getAuthorLabel(InternalMessage $message): string
    {
        if ($message->userId === $this->botUserId) {
            return 'assistant';
        }

        return $message->userName;
    }
}
