<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use Perk11\Viktor89\Repository\PersonaRepository;

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
        $response->parseMode = 'RichMarkdown';

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
        $lines = ['## Метаданные сообщения'];
        if ($metadata->model !== null) {
            $lines[] = "🤖 **Модель:** " . $metadata->model;
        }
        if ($metadata->systemPrompt !== null) {
            $lines[] = "📝 **Системный промпт:**\n" . self::codeBlock($metadata->systemPrompt);
        }
        if ($metadata->personaId !== null) {
            $persona = $this->personaRepository->findPersonaById($metadata->personaId);
            if ($persona !== null) {
                $author = $persona->userName !== '' ? " (от {$persona->userName})" : '';
                $lines[] = "🎭 **Персона:** {$persona->name}{$author}";
            } else {
                $lines[] = "🎭 **Персона:** ID {$metadata->personaId} (удалена)";
            }
        }
        if ($metadata->caption !== null) {
            $lines[] = "🖼 **Подпись:**\n" . self::codeBlock($metadata->caption);
        }
        if ($metadata->processedPrompt !== null) {
            $lines[] = "✏️ **Переписанный промпт:**\n" . self::codeBlock($metadata->processedPrompt);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Wrap arbitrary text in a fenced code block. The fence is grown to be
     * longer than the longest run of backticks inside the content, so prompts
     * that themselves contain ``` (or longer) sequences cannot break the block.
     */
    private static function codeBlock(string $content): string
    {
        $longestBacktickRun = 0;
        if (preg_match('/`++/', $content, $matches)) {
            $longestBacktickRun = strlen($matches[0]);
        }
        $fence = str_repeat('`', max(3, $longestBacktickRun + 1));

        return "{$fence}\n{$content}\n{$fence}";
    }
}
