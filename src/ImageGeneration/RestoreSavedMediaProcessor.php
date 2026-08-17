<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

/**
 * /restore: answered to the bot's reply of a successful /delete. The name and
 * the deleted media types are parsed back from that reply's first line
 * (see DeleteSavedMediaProcessor for the wording), and the matching
 * soft-deleted entries are restored. Only the user who saved (and therefore
 * deleted) them can restore.
 */
class RestoreSavedMediaProcessor implements MessageChainProcessor
{
    private const DELETE_MESSAGE_REGEXP = '/^(Изображение и аудио|Изображение|Аудио) «([^»]+)» удален/';

    public function __construct(
        private readonly ImageRepository $imageRepository,
        private readonly AudioRepository $audioRepository,
        private readonly int $botUserId,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $repliedTo = $messageChain->previous();
        if ($repliedTo === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Использование:\n/restore — ответом на сообщение бота об удалении."),
                true,
            );
        }

        if ($repliedTo->userId !== $this->botUserId
            || !preg_match(self::DELETE_MESSAGE_REGEXP, $repliedTo->messageText, $matches)
        ) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Эта команда должна использоваться в ответ на сообщение бота об удалении."),
                true,
            );
        }

        $labels = match ($matches[1]) {
            'Изображение' => ['Изображение'],
            'Аудио' => ['Аудио'],
            default => ['Изображение', 'Аудио'],
        };
        $name = $matches[2];

        $restoredLabels = [];
        $unavailableLabels = [];
        $refused = false;
        foreach ($labels as $label) {
            $repository = $label === 'Изображение' ? $this->imageRepository : $this->audioRepository;
            $deletedEntry = $repository->findDeletedByName($name);
            if ($deletedEntry !== null && $deletedEntry->userId !== $message->userId) {
                $refused = true;
            } elseif ($repository->restoreDeletedByName($name, $message->userId)) {
                $restoredLabels[] = $label;
            } else {
                // No soft-deleted row left: the name was reused via /saveas, or it is already restored.
                $unavailableLabels[] = $label;
            }
        }

        $lines = [];
        if ($restoredLabels !== []) {
            $restoredList = match ($restoredLabels) {
                ['Изображение', 'Аудио'] => 'изображение и аудио',
                ['Изображение'] => 'изображение',
                ['Аудио'] => 'аудио',
            };
            $lines[] = "Восстановлено: $restoredList «{$name}».";
        }
        foreach ($unavailableLabels as $label) {
            $lines[] = "$label «{$name}» не удалось восстановить: имя уже занято или уже восстановлено.";
        }
        if ($refused) {
            $lines[] = "Восстановить «{$name}» может только тот, кто его сохранил.";
        }

        return new ProcessingResult(
            InternalMessage::asResponseTo($message, implode("\n", $lines)),
            true,
        );
    }
}
