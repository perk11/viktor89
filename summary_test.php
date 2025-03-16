<?php
ini_set('memory_limit', '-1');

use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\OpenAISummaryProvider;

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
$database = new \Perk11\Viktor89\Database($telegram->getBotId(), 'siepatch-non-instruct5');
$summaryProvider = new OpenAISummaryProvider($database);
$summaryProvider->provideSummary(-1001804789551, 1741150883);
