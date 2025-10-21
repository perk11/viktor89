<?php
ini_set('memory_limit', '-1');
use GuzzleHttp\Exception\ConnectException;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\HistoryReader;

use Perk11\Viktor89\OpenAISummaryProvider;
use Perk11\Viktor89\ProcessingResult;
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
$summaryProvider = new \Perk11\Viktor89\OpenAISummaryProvider($database);
$pollResponseProcessor = new \Perk11\Viktor89\JoinQuiz\PollResponseProcessor($database);

$workerPool = workerPool();
echo "Connecting to Telegram...\n";
$telegram->useGetUpdatesWithoutDatabase();
$iterationId =0;
$processingResultExecutor = new \Perk11\Viktor89\ProcessingResultExecutor($database);
$lastSummaryTimestamp = $database->readSystemVariable(
    OpenAISummaryProvider::LAST_SUMMARY_TIMESTAMP_SYSTEM_VARIABLE_NAME
) ?? 0;
\Revolt\EventLoop::repeat(
    1,
    static function () use ($telegram, $workerPool, &$iterationId, &$lastSummaryTimestamp, $database, $pollResponseProcessor, $processingResultExecutor) {
    try {
        $serverResponse = $telegram->handleGetUpdates([
                                                          'allowed_updates' => [
                                                              Update::TYPE_MESSAGE,
                                                              Update::TYPE_POLL_ANSWER,
                                                          ],
                                                      ]);

        if ($serverResponse->isOk()) {
            $results = $serverResponse->getResult();
            if (count($results) > 0) {
                echo date('Y-m-d H:i:s') . ' - Processing ' . count($results) . " updates\n";
            }
            foreach ($results as $result) {
                $message = $result->getMessage();
                if ($message === null) {
                    if ($result->getPollAnswer() !== null) {
                        $pollResponseProcessingResult = $pollResponseProcessor->process($result->getPollAnswer());
                        $processingResultExecutor->execute($pollResponseProcessingResult);
                        return;
                    }
                    echo "Unknown update received. Message is null\n";
                    print_r($result);
                    return;
                }
                $handleTask = new \Perk11\Viktor89\ProcessMessageTask(
                    $message,
                    $telegram->getBotId(),
                    $telegram->getApiKey(),
                    $_ENV['TELEGRAM_BOT_USERNAME'],
                );
                $taskExecution = $workerPool->submit($handleTask);
                $taskExecution->getFuture()->catch(function (\Throwable $e) use ($message) {
                    echo "Error when handling message " . $message->getMessageId(). $e->getMessage();
                });
            }
        } else {
            echo date('Y-m-d H:i:s') . ' - Failed to fetch updates' . PHP_EOL;
            echo $serverResponse->printError();
        }

        $secondsSinceLastSummary = time() - $lastSummaryTimestamp;
        if ($secondsSinceLastSummary > 25 * 60 * 60 || ($secondsSinceLastSummary > 7200 && date('H') === '05')) {
            echo "Running chat summaries\n";
            $chats = [
                '-1001804789551',
                '-1001537530453',
                '-1002051845250',
                '-1002114209100',
                '-1001284940664',
                '-4233480248',
                '-1002076350723',
                '-1001634709774',
                '-4285233729',
                '-1002363376342',

            ];
            $lastSummaryTimestamp = time();
            $database->writeSystemVariable(
                OpenAISummaryProvider::LAST_SUMMARY_TIMESTAMP_SYSTEM_VARIABLE_NAME,
                $lastSummaryTimestamp
            );

            foreach ($chats as $chat) {
                $handleTask = new \Perk11\Viktor89\SummaryTask(
                    $chat,
                    $telegram->getBotId(),
                    $telegram->getApiKey(),
                    $_ENV['TELEGRAM_BOT_USERNAME'],
                );
                $taskExecution = $workerPool->submit($handleTask);
                $taskExecution->getFuture()->catch(function (\Throwable $e) use ($chat) {
                    echo "Error when providing summary for chat" . $chat . $e->getMessage();
                });
            }
        }
        $iterationId++;
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
EventLoop::repeat(300, static function () use ($database, $processingResultExecutor) {
    foreach ($database->findPendingKickQueueItems() as $item) {
        echo "Found pending kick\n";
        $message = new \Perk11\Viktor89\InternalMessage();
        $message->chatId = $item->chatId;
        $message->replyToMessageId = $item->joinMessageId;

        $banRequest = Request::banChatMember([
                                                 'chat_id' => $item->chatId,
                                                 'user_id' => $item->userId,
                                             ]);

        if (!$banRequest->isOk()) {
            echo "Failed to ban user " . $item->userId . " in chat " . $item->chatId. " \n";
            print_r($banRequest);

            $message->messageText = "Пользователь id" . $item->userId. " не ответил вовремя на вопрос, но мне не удалось удалить его. Баньте!";
        } else {
            $unbanRequest = Request::unbanChatMember([
                                                         'chat_id' => $item->chatId,
                                                         'user_id' => $item->userId,
                                                     ]);
            if (!$unbanRequest->isOk()) {
                echo "Failed to unban user\n";
                print_r($unbanRequest);
                $message->messageText = "Пользователь id" . $item->userId. " не ответил вовремя на вопрос и был забанен!";
            } else {
                $message->messageText = "Пользователь id" . $item->userId . " не ответил вовремя на вопрос и был удалён из чата";
            }
        }
        $processingResultExecutor->execute(new ProcessingResult($message, true));
        echo "Deleting messages " . json_encode($item->messagesToDelete, JSON_THROW_ON_ERROR) . "\n";
        $deleteMessagesResult = Request::execute('deleteMessages', [
            'chat_id' => $item->chatId,
            'message_ids' => json_encode($item->messagesToDelete, JSON_THROW_ON_ERROR),
        ]);
        print_r($deleteMessagesResult);
        $database->nullKickTime($item->pollId);
    }

});

$patchesTaskRunning = false;
//EventLoop::repeat(300, static function() use($telegram, $workerPool, &$patchesTaskRunning) {
//    if ($patchesTaskRunning) {
//        echo "Previous last patches read has not yet completed\n";
//        return;
//    }
//    $patchesTaskRunning = true;
//    echo "Reading last patches...\n";
//
//    $task = new \Perk11\Viktor89\PatchesMonitorTask(
//        $telegram->getBotId(),
//        $telegram->getApiKey(),
//        $telegram->getBotUsername(),
//    );
//    $taskExecution = $workerPool->submit($task);
//    $taskExecution->getFuture()->catch(function (\Throwable $e) {
//        echo "Error when getting last patches: " . $e->getMessage();
//    });
//    $taskExecution->await();
//    $patchesTaskRunning = false;
//});
EventLoop::run();
