<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Exception;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\VoiceRecogniser;

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
        $channel->send(new TaskUpdateMessage($this->summarizedChatId,'Summary', 'Generating summary'));
        try {
            $this->handle();
        } catch (Exception $e) {
            echo "Error " . $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        return true;
    }

    private function handle(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        $configFilePath =__DIR__ . '/../config.json';
        $configString = file_get_contents($configFilePath);
        if ($configString === false) {
            throw new \Exception("Failed to read $configFilePath");
        }
        $config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
        $telegram = new Telegram($this->telegramApiKey, $this->telegramBotUsername);
        $database = new Database($this->telegramBotId, 'siepatch-non-instruct5');
        $cacheFileManager = new CacheFileManager($database);
        $telegramFileDownloader = new TelegramFileDownloader($cacheFileManager, $telegram->getApiKey());
        $voiceRecogniser = new VoiceRecogniser($config['whisperCppUrl']);
        $internalMessageTranscriber = new InternalMessageTranscriber(
            $telegramFileDownloader,
            $voiceRecogniser,
            $database,
        );
        $altTextProvider = new AltTextProvider($telegramFileDownloader, $internalMessageTranscriber, $database);
        $assistantConfig =$config['assistantModels']['vision-for-alt-text'];
        $systemPromptProcessor = new FixedValuePreferenceProvider('');
        $altTextProvider->assistantWithVision = new \Perk11\Viktor89\Assistant\OpenAiChatAssistant(
            $assistantConfig['model'],
            $systemPromptProcessor,
            $systemPromptProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegram->getBotId(),
            $assistantConfig['url'],
            $assistantConfig['apiKey'] ?? '',
            true,
        );

        $summaryProvider = new OpenAISummaryProvider($database, $altTextProvider);
        $summaryProvider->sendChatSummaryWithMessagesSinceLastOne($this->summarizedChatId);
    }
}
