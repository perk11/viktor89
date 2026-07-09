<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class DeletePersonaProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly PersonaHelper $helper,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $argument = $this->helper->normalizeArgument($message->messageText);

        if ($argument === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Использование:\n/delpersona Название"),
                true,
            );
        }

        $name = $this->helper->extractName($argument);
        $persona = $this->database->findPersonaByName($name);
        if ($persona === null) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, "Персона \"$name\" не найдена."),
                true,
            );
        }
        if ($persona->userId !== $message->userId) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Удалять персону может только тот, кто её создал.'),
                true,
            );
        }

        $this->database->deletePersonaByName($name);

        return $this->helper->reactOrRespond($message, "Персона \"$name\" удалена.");
    }
}
