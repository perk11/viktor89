<?php

namespace Perk11\Viktor89\RateLimiting;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\RateLimitRepository;

class RateLimitsCommandProcessor implements MessageChainProcessor
{
    public function __construct(private readonly RateLimitRepository $rateLimitRepository, private readonly array $chatRateLimits) {

    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $userId = $messageChain->last()->userId;

        $limits = $this->rateLimitRepository->findRateLimitsByChat($this->chatRateLimits, $userId);

        $responseMessage = InternalMessage::asResponseTo($messageChain->last());
        $responseMessage->receiverUserId = $userId;

        if (count($limits) === 0) {
            $responseMessage->messageText = 'На тебя не наложено ограничений.';
        } else {
            $responseMessage->messageText = 'Вот через сколько я начну тебе отвечать в каждом из чатов, где есть ограничения (chat id - время):';
            foreach ($limits as $limit) {
                $responseMessage->messageText .= "\n" . $limit->chatId ." - " . $this->formatDurationRus($limit->timeInSeconds);
            }
        }

        return new ProcessingResult($responseMessage, true, "🐳", $messageChain->last());
    }
    private function formatDurationRus(int $seconds): string {
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours . ' ' . $this->getPluralRus($hours, 'час', 'часа', 'часов');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' ' . $this->getPluralRus($minutes, 'минута', 'минуты', 'минут');
        }
        // Всегда показываем секунды, если нет других частей
        if ($seconds > 0 || empty($parts)) {
            $parts[] = $seconds . ' ' . $this->getPluralRus($seconds, 'секунда', 'секунды', 'секунд');
        }

        return implode(' ', $parts);
    }

    /**
     * Возвращает правильную форму слова для русского языка в зависимости от числа.
     *
     * @param int    $number Число.
     * @param string $form1  Форма для 1 (например, "секунда").
     * @param string $form2  Форма для 2-4 (например, "секунды").
     * @param string $form5  Форма для 5+ (например, "секунд").
     * @return string        Правильная форма слова.
     */
    private function getPluralRus(int $number, string $form1, string $form2, string $form5): string {
        $n = abs($number) % 100;
        if ($n >= 11 && $n <= 14) {
            return $form5;
        }
        $n %= 10;
        if ($n === 1) {
            return $form1;
        }
        if ($n >= 2 && $n <= 4) {
            return $form2;
        }
        return $form5;
    }
}
