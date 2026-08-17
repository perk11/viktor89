<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

/**
 * /restore: answered to the bot's response of a /delete (the reply thread
 * then contains the original /delete command — see HistoryReader). The name is
 * taken from that command message, and everything currently soft-deleted under
 * it that belongs to the command's author is restored. Only the user who
 * issued the /delete (and therefore saved the media) can restore.
 */
class RestoreSavedMediaProcessor implements MessageChainProcessor
{
    private const DELETE_COMMAND_REGEXP = '/^\/delete(?:@\w+)?\s+(.+)$/s';

    public function __construct(
        private readonly ImageRepository $imageRepository,
        private readonly AudioRepository $audioRepository,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $name = $this->findDeleteCommandTarget($messageChain, $message->userId);
        if ($name === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    $messageChain->count() > 1
                        ? "Эта команда должна использоваться в ответ на сообщение бота об удалении."
                        : "Использование:\n/restore — ответом на сообщение бота об удалении."
                ),
                true,
            );
        }

        $restoredLabels = [];
        $unavailableLabels = [];
        foreach ($this->repositories() as $label => $repository) {
            if ($repository->restoreDeletedByName($name, $message->userId)) {
                $restoredLabels[] = $label;
            } elseif ($repository->findByName($name) !== null) {
                // The name is occupied by a live entry: restored before, or
                // reused via /saveas (which discards the soft-deleted row).
                $unavailableLabels[] = $label;
            }
            // No entry of this type under the name at all: nothing to report.
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
        if ($lines === []) {
            $lines[] = "«{$name}» нечего восстанавливать.";
        }

        return new ProcessingResult(
            InternalMessage::asResponseTo($message, implode("\n", $lines)),
            true,
        );
    }

    /** @return array<string, ImageRepository|AudioRepository> */
    private function repositories(): array
    {
        return ['Изображение' => $this->imageRepository, 'Аудио' => $this->audioRepository];
    }

    /**
     * The name the closest preceding /delete command of this user in the reply
     * thread targeted, or null if there is none.
     */
    private function findDeleteCommandTarget(MessageChain $messageChain, int $userId): ?string
    {
        $messages = $messageChain->getMessages();
        for ($i = count($messages) - 2; $i >= 0; $i--) {
            if ($messages[$i]->userId !== $userId) {
                continue;
            }
            if (preg_match(self::DELETE_COMMAND_REGEXP, trim($messages[$i]->messageText), $matches) === 1) {
                return trim(str_replace(['<img>', '</img>'], '', $matches[1]));
            }
        }

        return null;
    }
}
