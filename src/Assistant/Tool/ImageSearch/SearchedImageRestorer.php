<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

use Perk11\Viktor89\InternalMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Rebuilds the images found by earlier image_search calls from the persisted
 * tool call results of a bot message, reading their bytes from SearchedImageCache.
 */
class SearchedImageRestorer
{
    public function __construct(
        private readonly SearchedImageCache $searchedImageCache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return list<InternalMessage> */
    public function restore(InternalMessage $message): array
    {
        $images = [];
        foreach ($message->toolCalls as $toolCall) {
            if ($toolCall->name !== ImageSearchToolCallExecutor::TOOL_NAME || $toolCall->result === null) {
                continue;
            }
            $result = json_decode($toolCall->result, true);
            if (!is_array($result) || !is_array($result['images'] ?? null)) {
                continue;
            }
            foreach ($result['images'] as $image) {
                $imageUrl = $image['image_url'] ?? null;
                if (!is_string($imageUrl)) {
                    continue;
                }
                $imageBytes = $this->searchedImageCache->read($imageUrl);
                if ($imageBytes === null) {
                    $this->logger?->log(LogLevel::WARNING, "Searched image is missing from cache: $imageUrl");
                    continue;
                }
                $images[] = self::createImageMessage(
                    $message,
                    $imageBytes,
                    is_string($image['title'] ?? null) ? $image['title'] : null,
                    is_string($image['source_page'] ?? null) ? $image['source_page'] : '',
                    is_int($message->date) ? $message->date : null,
                );
            }
        }

        return $images;
    }

    public static function createImageMessage(
        InternalMessage $contextMessage,
        string $imageBytes,
        ?string $title,
        string $sourcePage,
        ?int $date = null,
    ): InternalMessage {
        $message = new InternalMessage();
        $message->chatId = $contextMessage->chatId;
        $message->userId = 0;
        $message->userName = ImageSearchToolCallExecutor::TOOL_NAME;
        $message->type = 'photo';
        $message->date = $date ?? time();
        $message->messageText = 'Web image: ' . ($title ?? '') . ' (' . $sourcePage . ')';
        $message->photoContents = $imageBytes;

        return $message;
    }
}
