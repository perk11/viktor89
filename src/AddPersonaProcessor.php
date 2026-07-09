<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class AddPersonaProcessor implements MessageChainProcessor
{
    private const COMMAND = '/addpersona';

    public function __construct(
        private readonly Database $database,
        private readonly PersonaHelper $helper,
        private readonly int $maxPersonasPerUser = 5,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $argument = $this->helper->normalizeArgument($message->messageText);

        if ($argument === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Использование:\n" . self::COMMAND . " Название\nСистемный промпт"),
                true,
            );
        }

        $newlinePos = strpos($argument, "\n");
        if ($newlinePos === false) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    "Укажите название персоны на первой строке, а системный промпт — на следующих строках.\n\n"
                    . "Пример:\n" . self::COMMAND . " Пират\nТы пиратский капитан. Говори морским сленгом."
                ),
                true,
            );
        }

        $name = trim(substr($argument, 0, $newlinePos));
        $systemPrompt = trim(substr($argument, $newlinePos + 1));

        if ($name === '' || $systemPrompt === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Название персоны и системный промпт не могут быть пустыми.'),
                true,
            );
        }
        if (mb_strlen($name) > PersonaHelper::MAX_NAME_LENGTH) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Название персоны слишком длинное (максимум ' . PersonaHelper::MAX_NAME_LENGTH . ' символов).'),
                true,
            );
        }
        if ($this->helper->isReservedName($name)) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Это название зарезервировано. Выберите другое.'),
                true,
            );
        }
        if ($this->database->findPersonaByName($name) !== null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Персона \"$name\" уже существует. Выберите другое название."),
                true,
            );
        }
        if ($this->database->countPersonasByUserId($message->userId) >= $this->maxPersonasPerUser) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    "Вы уже создали максимум персон ($this->maxPersonasPerUser). Удалите одну из них командой /delpersona."
                ),
                true,
            );
        }

        $this->database->addPersona($name, $systemPrompt, $message->userId, $message->userName);

        return $this->helper->reactOrRespond($message, "Персона \"$name\" сохранена. Используйте /persona $name, чтобы применить её.");
    }
}
