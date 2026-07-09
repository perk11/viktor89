<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Exception;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use Perk11\Viktor89\Repository\ChatSummaryRepository;
use Perk11\Viktor89\Repository\FileCacheRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\VoiceRecogniser;

class SummaryTask implements Task
{
    public function __construct(
        private readonly int $workerId,
        private readonly int $summarizedChatId,
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $channel->send(new TaskUpdateMessage($this->workerId, 'Summary', 'Generating summary', new ChatAction($this->summarizedChatId, ChatActionEnum::typing)));
        try {
            $this->handle();
        } catch (Exception $e) {
            echo "Error " . $e->getMessage() . "\n" . $e->getTraceAsString();
        } finally {
            $channel->send(new TaskCompletedMessage($this->workerId));
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
        $messageRepository = new MessageRepository($database);
        $cacheFileManager = new CacheFileManager(new FileCacheRepository($database));
        $telegramFileDownloader = new TelegramFileDownloader($cacheFileManager, $telegram->getApiKey());
        $voiceRecogniser = new VoiceRecogniser($config['whisperCppUrl']);
        $internalMessageTranscriber = new InternalMessageTranscriber(
            $telegramFileDownloader,
            $voiceRecogniser,
            $messageRepository,
        );
        $altTextProvider = new AltTextProvider($telegramFileDownloader, $internalMessageTranscriber, $messageRepository);
        $assistantConfig =$config['assistantModels']['vision-for-alt-text'];
        $systemPromptProcessor = new FixedValuePreferenceProvider('');
        $nullProcessor = new FixedValuePreferenceProvider(null);
        $altTextProvider->assistantWithVision = new \Perk11\Viktor89\Assistant\OpenAiChatAssistant(
            $assistantConfig['model'],
            $systemPromptProcessor,
            $systemPromptProcessor,
            $nullProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            new ProcessingResultExecutor($messageRepository),
            $telegram->getBotId(),
            $assistantConfig['url'],
            $assistantConfig['apiKey'] ?? '',
            true,
        );

        $summaryProvider = new OpenAISummaryProvider($messageRepository, new ChatSummaryRepository($database), $altTextProvider);
        $summaryProvider->sendChatSummaryWithMessagesSinceLastOne($this->summarizedChatId);
    }
}
