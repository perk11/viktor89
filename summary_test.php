<?php
ini_set('memory_limit', '-1');

use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\CacheFileManager;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\OpenAISummaryProvider;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\VoiceRecogniser;

require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
if (!isset($_ENV['TELEGRAM_BOT_TOKEN'])) {
    die('TELEGRAM_BOT_TOKEN is undefined');
}
if (!isset($_ENV['TELEGRAM_BOT_USERNAME'])) {
    die('TELEGRAM_BOT_USERNAME is undefined');
}
$telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);
$database = new Database($telegram->getBotId(), 'siepatch-non-instruct5');
$configFilePath =__DIR__ . '/config.json';
$configString = file_get_contents($configFilePath);
if ($configString === false) {
    throw new \Exception("Failed to read $configFilePath");
}
$config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
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
$summaryProvider->provideSummary(-1001804789551, time()-3600*24*2);
