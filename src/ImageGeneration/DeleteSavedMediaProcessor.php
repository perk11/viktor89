<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

/**
 * /delete <имя>: soft-deletes the saved image and/or audio with that name.
 * Only the user who saved an entry can delete it; when both an image and an
 * audio exist under the name, both are deleted.
 */
class DeleteSavedMediaProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly ImageRepository $imageRepository,
        private readonly AudioRepository $audioRepository,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $name = str_replace(['<img>', '</img>'], '', trim($message->messageText));
        if ($name === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Использование:\n/delete имя"),
                true,
            );
        }
        if (preg_match('/[«»\r\n]/', $name) === 1) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Имя не должно содержать символы « и » или переводы строк.'),
                true,
            );
        }

        $deletedLabels = [];
        $refusalLines = [];
        foreach ($this->repositories() as $label => $repository) {
            $entry = $repository->findByName($name);
            if ($entry === null) {
                continue;
            }
            if ($entry->userId === $message->userId) {
                $repository->markDeletedByName($name, $message->userId);
                $deletedLabels[] = $label;
            } else {
                $refusalLines[] = "$label «{$name}» может удалить только тот, кто его сохранил.";
            }
        }

        if ($deletedLabels === [] && $refusalLines === []) {
            $messageText = $this->anyDeleted($name)
                ? "«{$name}» уже удалено. Чтобы отменить удаление, ответьте /restore на сообщение бота об удалении."
                : "Сохранённое с именем «{$name}» не найдено.";
            return new ProcessingResult(InternalMessage::asResponseTo($message, $messageText), true);
        }

        if ($deletedLabels !== []) {
            $deletedLine = count($deletedLabels) === 2
                ? "Изображение и аудио «{$name}» удалены."
                : "{$deletedLabels[0]} «{$name}» удалено.";
            array_unshift($refusalLines, $deletedLine);
        }

        return new ProcessingResult(
            InternalMessage::asResponseTo($message, implode("\n", $refusalLines)),
            true,
        );
    }

    /** @return array<string, ImageRepository|AudioRepository> */
    private function repositories(): array
    {
        return ['Изображение' => $this->imageRepository, 'Аудио' => $this->audioRepository];
    }

    private function anyDeleted(string $name): bool
    {
        foreach ($this->repositories() as $repository) {
            if ($repository->findDeletedByName($name) !== null) {
                return true;
            }
        }

        return false;
    }
}
