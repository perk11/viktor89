<?php
/**
 * Generates a chat summary without sending it or writing to the database.
 * The database is opened read-only; photos downloaded from Telegram are cached
 * in a separate directory instead of the bot's cache.
 *
 * Usage: php summary_test.php --chat=<chat id> [options]
 *   --since=<time>               Start of the period: unix timestamp or anything strtotime() accepts
 *                                (default: the chat's last recorded summary, as the bot does)
 *   --until=<time>               End of the period (default: now)
 *   --hours=<n>                  Shortcut for --since=<now - n hours>
 *   --max-messages=<n>           Default: 10000
 *   --url=<url> --model=<name> --api-key=<key> --context-size=<tokens> --max-response-tokens=<tokens>
 *   --chars-per-token=<n> --image-tokens=<n> --max-image-edge=<px> --image-share=<0..1>
 *   --images / --no-images       Send photos to the model / use alt text instead
 *   --cache-dir=<dir>            Where downloaded photos are cached (default: system temp dir)
 *   --output=<file>              Also write the summary to this file
 * Model options default to the "summary" section of config.json.
 */
ini_set('memory_limit', '-1');

use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\OpenAiChatAssistant;
use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Log\Viktor89Logger;
use Perk11\Viktor89\OpenAISummaryProvider;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\Repository\ChatSummaryRepository;
use Perk11\Viktor89\Repository\FileCacheRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\SummaryModelConfig;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\VoiceRecogniser;
use Psr\Log\LoggerInterface;

require __DIR__ . '/vendor/autoload.php';

$options = getopt('', [
    'chat:', 'since:', 'until:', 'hours:', 'max-messages:',
    'url:', 'model:', 'api-key:', 'context-size:', 'max-response-tokens:',
    'chars-per-token:', 'image-tokens:', 'max-image-edge:', 'image-share:', 'images', 'no-images',
    'cache-dir:', 'output:',
]);
if (!isset($options['chat'])) {
    fwrite(STDERR, "Usage: php summary_test.php --chat=<chat id> [options], see the comment at the top of the file\n");
    exit(1);
}

$parseTime = static function (string $value): int {
    if (is_numeric($value)) {
        return (int) $value;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new InvalidArgumentException("Can not parse time: $value");
    }

    return $timestamp;
};

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$summaryConfig = $config['summary'] ?? [];
$overrides = [
    'url'                  => 'url',
    'model'                => 'model',
    'api-key'              => 'apiKey',
    'context-size'         => 'contextSize',
    'max-response-tokens'  => 'maxResponseTokens',
    'chars-per-token'      => 'charactersPerToken',
    'image-tokens'         => 'imageTokens',
    'max-image-edge'       => 'maxImageEdgePixels',
    'image-share'          => 'maxImageContextShare',
];
foreach ($overrides as $option => $key) {
    if (isset($options[$option])) {
        $summaryConfig[$key] = is_numeric($options[$option]) ? $options[$option] + 0 : $options[$option];
    }
}
if (isset($options['images'])) {
    $summaryConfig['supportsImages'] = true;
}
if (isset($options['no-images'])) {
    $summaryConfig['supportsImages'] = false;
}
$summaryModelConfig = SummaryModelConfig::fromConfig($summaryConfig);

$logger = new Viktor89Logger();
InternalMessage::setLogger($logger);
$telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);
$database = new Database($telegram->getBotId(), 'siepatch-non-instruct5', readOnly: true);

$messageRepository = new class($database) extends MessageRepository {
    public function logInternalMessage(InternalMessage $message): void
    {
    }
};

$cacheDir = $options['cache-dir'] ?? sys_get_temp_dir() . '/viktor89-summary-test-cache';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true)) {
    throw new RuntimeException("Failed to create $cacheDir");
}
$cacheFileManager = new class(new FileCacheRepository($database), $logger, $cacheDir) extends CacheFileManager {
    public function __construct(FileCacheRepository $fileCacheRepository, LoggerInterface $logger, private readonly string $cacheDir)
    {
        parent::__construct($fileCacheRepository, $logger);
    }

    public function readFileFromCache(string $fileId): ?string
    {
        $path = $this->cacheDir . '/' . sha1($fileId);
        if (is_file($path)) {
            return file_get_contents($path);
        }

        return parent::readFileFromCache($fileId);
    }

    public function writeFileToCache(string $fileId, string $contents): void
    {
        file_put_contents($this->cacheDir . '/' . sha1($fileId), $contents);
    }
};

$telegramFileDownloader = new TelegramFileDownloader($cacheFileManager, $telegram->getApiKey(), $logger);
$internalMessageTranscriber = new InternalMessageTranscriber(
    $telegramFileDownloader,
    new VoiceRecogniser($config['whisperCppUrl'], $logger),
    $messageRepository,
);
$altTextProvider = new AltTextProvider($telegramFileDownloader, $internalMessageTranscriber, $messageRepository, $logger);
$assistantConfig = $summaryModelConfig->altTextAssistantConfig($config['assistantModels']['vision-for-alt-text']);
$systemPromptProcessor = new FixedValuePreferenceProvider('');
$altTextProvider->assistantWithVision = new OpenAiChatAssistant(
    $assistantConfig['model'],
    $systemPromptProcessor,
    $systemPromptProcessor,
    new FixedValuePreferenceProvider(null),
    $telegramFileDownloader,
    $altTextProvider,
    new ProcessingResultExecutor($messageRepository, logger: $logger),
    $telegram->getBotId(),
    $assistantConfig['url'],
    $assistantConfig['apiKey'],
    true,
    [],
    $logger,
);

$chatId = (int) $options['chat'];
$chatSummaryRepository = new ChatSummaryRepository($database);
$since = match (true) {
    isset($options['since']) => $parseTime($options['since']),
    isset($options['hours']) => time() - (int) ($options['hours'] * 3600),
    default                  => $chatSummaryRepository->getLastChatSummaryDate($chatId) ?? 0,
};
$until = isset($options['until']) ? $parseTime($options['until']) : PHP_INT_MAX;

$summaryProvider = new OpenAISummaryProvider(
    $messageRepository,
    $chatSummaryRepository,
    $altTextProvider,
    $telegramFileDownloader,
    $summaryModelConfig,
    $logger,
);
fwrite(STDERR, sprintf(
    "Summarizing chat %d from %s to %s with %s at %s (context %d, images %s)\n",
    $chatId,
    date('Y-m-d H:i:s', $since),
    $until === PHP_INT_MAX ? 'now' : date('Y-m-d H:i:s', $until),
    $summaryModelConfig->model ?? 'the default model',
    $summaryModelConfig->url,
    $summaryModelConfig->contextSize,
    $summaryModelConfig->supportsImages ? 'on' : 'off',
));
$startTime = microtime(true);
$summary = $summaryProvider->provideSummary($chatId, $since, (int) ($options['max-messages'] ?? 10000), $until);
fwrite(STDERR, sprintf("Done in %.1fs\n", microtime(true) - $startTime));
if ($summary === null) {
    fwrite(STDERR, "Fewer than 10 messages in this period, no summary\n");
    exit(2);
}
echo $summary, "\n";
if (isset($options['output'])) {
    file_put_contents($options['output'], $summary . "\n");
}
