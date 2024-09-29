<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;

class SummaryTask implements Task
{
    public function __construct(
        private readonly int $summarizedChatId,
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        try {
            $this->handle();
        } catch (\Exception $e) {
            echo "Error " . $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        return true;
    }

    private function handle(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        $telegram = new Telegram($this->telegramApiKey, $this->telegramBotUsername);
        $database = new Database($this->telegramBotId, 'siepatch-non-instruct5');
        $summaryProvider = new OpenAISummaryProvider($database);
        $summaryProvider->sendChatSummaryWithMessagesSinceLastOne($this->summarizedChatId);
    }
}
