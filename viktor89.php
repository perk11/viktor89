<?php

use GuzzleHttp\Exception\ConnectException;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\HistoryReader;

use Revolt\EventLoop;

use function Amp\delay;
use function Amp\Parallel\Worker\workerPool;

require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
if (!isset($_ENV['TELEGRAM_BOT_TOKEN'])) {
    die('TELEGRAM_BOT_TOKEN is undefined');
}
if (!isset($_ENV['TELEGRAM_BOT_USERNAME'])) {
    die('TELEGRAM_BOT_USERNAME is undefined');
}
if (!isset($_ENV['OPENAI_SERVER'])) {
    die('OPENAI_SERVER is undefined');
}

$telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);

//$fallBackResponder = new \Perk11\Viktor89\SiepatchNoInstructResponseGenerator();
//$fallBackResponder = new \Perk11\Viktor89\Siepatch2Responder();
$database = new \Perk11\Viktor89\Database($telegram->getBotId(), 'siepatch-non-instruct5');
$historyReader = new HistoryReader($database);
$summaryProvider = new \Perk11\Viktor89\ChatGptSummaryProvider($database);

$workerPool = workerPool();
echo "Connecting to Telegram...\n";
$telegram->useGetUpdatesWithoutDatabase();
$iterationId =0;
\Revolt\EventLoop::repeat(1, static function () use ($telegram, $workerPool, &$iterationId, $summaryProvider) {
    try {
        $serverResponse = $telegram->handleGetUpdates([
                                                          'allowed_updates' => [
                                                              Update::TYPE_MESSAGE,
                                                          ],
                                                      ]);

        if ($serverResponse->isOk()) {
            $results = $serverResponse->getResult();
            if (count($results) > 0) {
                echo date('Y-m-d H:i:s') . ' - Processing ' . count($results) . " updates\n";
            }
            foreach ($results as $result) {
                if ($result->getMessage() === null) {
                    echo "Unknown update received:\n";
                    return;
                }
                $handleTask = new \Perk11\Viktor89\ProcessMessageTask(
                    $result->getMessage(),
                    $telegram->getBotId(),
                    $telegram->getApiKey(),
                    $_ENV['TELEGRAM_BOT_USERNAME'],
                );
                $workerPool->submit($handleTask);
            }
        } else {
            echo date('Y-m-d H:i:s') . ' - Failed to fetch updates' . PHP_EOL;
            echo $serverResponse->printError();
        }

        if ($iterationId % 60 === 0) {
            $newSummary = $summaryProvider->provideSummaryIf24HoursPassedSinceLastOneA('-1001804789551');
            if ($newSummary !== null) {
                $formattedText = str_replace('**', '*', $newSummary);
                $newSummary = "*Анализ чата за последние 24 часа*\n$newSummary";
                // Define the maximum size of each message
                $maxSize = 4000;

                // Split the summary into chunks of 4000 characters
                $chunks = mb_str_split($newSummary, $maxSize);

                foreach ($chunks as $chunk) {
                    sleep(20);
                    // Send each chunk as a separate message
                    var_dump(Request::sendMessage([
                                             'chat_id'    => -1001804789551,
                                             'text'       => $chunk,
                                         ]));
                }
            }
        }
        $iterationId++;
        delay(1);
    } catch (\Longman\TelegramBot\Exception\TelegramException $e) {
        echo "Telegram error\n";
        TelegramLog::error($e);
        delay(10);
    } catch (ConnectException $e) {
        echo "Curl error received, retrying in 10 seconds:\n";
        echo $e->getMessage()."\n";
        delay(10);
    }
});
EventLoop::run();
