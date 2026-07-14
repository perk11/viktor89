<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Repository\PersonaRepository;
use Perk11\Viktor89\Util\TelegramHtml;

class MetadataCommandProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly MessageMetadataRepository $messageMetadataRepository,
        private readonly PersonaRepository $personaRepository,
        private readonly string $telegramBotUserName,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();

        $response = InternalMessage::asResponseTo($lastMessage);
        $response->parseMode = 'HTML';

        $replyTarget = $messageChain->previous();
        if ($replyTarget === null) {
            $response->messageText = 'Используйте эту команду в ответ на сообщение, метаданные которого вы хотите посмотреть.';
            return new ProcessingResult($response, true);
        }

        $metadata = $this->messageMetadataRepository->findByMessageIdInChat(
            $replyTarget->id,
            $replyTarget->chatId,
        );

        if ($metadata === null || !$metadata->hasAny()) {
            $response->messageText = 'Нет сохранённых метаданных для этого сообщения.';
            return new ProcessingResult($response, true);
        }

        $response->messageText = $this->formatMetadata($metadata);
        return new ProcessingResult($response, true);
    }

    private function formatMetadata(MessageMetadata $metadata): string
    {
        $lines = ["<b>Метаданные сообщения</b>"];
        if ($metadata->model !== null) {
            $lines[] = "🤖 <b>Модель:</b> " . TelegramHtml::escape($metadata->model);
        }
        if ($metadata->systemPrompt !== null) {
            $lines[] = "📝 <b>Системный промпт:</b>\n<pre>" . TelegramHtml::escape($metadata->systemPrompt) . "</pre>";
        }
        if ($metadata->personaId !== null) {
            $persona = $this->personaRepository->findPersonaById($metadata->personaId);
            if ($persona !== null) {
                $author = $persona->userName !== '' ? ' (от ' . TelegramHtml::escape($persona->userName) . ')' : '';
                $lines[] = "🎭 <b>Персона:</b> " . TelegramHtml::escape($persona->name) . $author;
            } else {
                $lines[] = "🎭 <b>Персона:</b> ID " . $metadata->personaId . " (удалена)";
            }
        }
        if ($metadata->caption !== null) {
            $lines[] = "🖼 <b>Подпись:</b>\n<pre>" . TelegramHtml::escape($metadata->caption) . "</pre>";
        }

        return implode("\n", $lines);
    }
}
