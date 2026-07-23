<?php
ini_set('memory_limit', '-1');
use GuzzleHttp\Exception\ConnectException;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\Container\ContainerFactory;
use Perk11\Viktor89\HistoryReader;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\JoinQuiz\PollResponseProcessor;
use Perk11\Viktor89\Log\Viktor89Logger;
use Perk11\Viktor89\OpenAISummaryProvider;
use Perk11\Viktor89\PatchesMonitorTask;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\ProcessMessageTask;
use Perk11\Viktor89\Repository\KickQueueRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Repository\SystemVariableRepository;
use Perk11\Viktor89\SummaryTask;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use Psr\Log\LogLevel;
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

// Compile the DI container once for all worker processes to load.
ContainerFactory::warmup($telegram->getBotId(), $_ENV['TELEGRAM_BOT_USERNAME'], $telegram->getApiKey());

$logger = new Viktor89Logger();
InternalMessage::setLogger($logger);
AssistantContext::setLogger($logger);
BotAdminChecker::setLogger($logger);

//$fallBackResponder = new \Perk11\Viktor89\SiepatchNoInstructResponseGenerator();
//$fallBackResponder = new \Perk11\Viktor89\Siepatch2Responder();
$database = new Database($telegram->getBotId(), 'siepatch-non-instruct5');
$messageRepository = new MessageRepository($database);
$historyReader = new HistoryReader($messageRepository);
$pollResponseProcessor = new PollResponseProcessor(new KickQueueRepository($database, $logger), $logger);

$workerPool = workerPool();
$logger->log(LogLevel::INFO, 'Connecting to Telegram...');
$telegram->useGetUpdatesWithoutDatabase();
$iterationId =0;
$processingResultExecutor = new ProcessingResultExecutor($messageRepository, logger: $logger);
$systemVariableRepository = new SystemVariableRepository($database);
$lastSummaryTimestamp = $systemVariableRepository->readSystemVariable(
    OpenAISummaryProvider::LAST_SUMMARY_TIMESTAMP_SYSTEM_VARIABLE_NAME
) ?? 0;
$finalMessageTracker = new FinalMessageTracker();
$chatActionUpdater = new ChatActionUpdater($finalMessageTracker, logger: $logger);
$draftUpdater = new DraftUpdater($finalMessageTracker, logger: $logger);
$runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, $logger);
$workerId = 1;
EventLoop::repeat(
    1,
    static function () use ($logger, $systemVariableRepository, $telegram, $workerPool, &$iterationId, &$lastSummaryTimestamp, $database, $pollResponseProcessor, $processingResultExecutor, $runningTaskTracker, &$workerId) {
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
                $logger->log(LogLevel::INFO, date('Y-m-d H:i:s') . ' - Processing ' . count($results) . ' updates');
            }
            foreach ($results as $result) {
                $message = $result->getMessage();
                if ($message === null) {
                    if ($result->getPollAnswer() !== null) {
                        $pollResponseProcessingResult = $pollResponseProcessor->process($result->getPollAnswer());
                        $processingResultExecutor->execute($pollResponseProcessingResult);
                        return;
                    }
                    $logger->log(LogLevel::WARNING, 'Unknown update received. Message is null: ' . print_r($result, true));
                    return;
                }
                $handleTask = new ProcessMessageTask(
                    $workerId++,
                    $message,
                    $telegram->getBotId(),
                    $telegram->getApiKey(),
                    $_ENV['TELEGRAM_BOT_USERNAME'],
                    $logger,
                );
                $taskExecution = $workerPool->submit($handleTask);
                Amp\async(function () use ($taskExecution, $runningTaskTracker) {
                   $runningTaskTracker->receive($taskExecution);
                });
                $taskExecution->getFuture()->catch(function (Throwable $e) use ($message, $logger) {
                    $logger->log(LogLevel::ERROR, 'Error when handling message ' . $message->getMessageId() . $e->getMessage());
                });
            }
        } else {
            $logger->log(LogLevel::ERROR, date('Y-m-d H:i:s') . ' - Failed to fetch updates: ' . $serverResponse->printError(true));
        }

        $secondsSinceLastSummary = time() - $lastSummaryTimestamp;
        if ($secondsSinceLastSummary > 25 * 60 * 60 || ($secondsSinceLastSummary > 7200 && date('H') === '05')) {
            $logger->log(LogLevel::INFO, 'Running chat summaries');
            $chats = [
                '-1001804789551',
                '-1002114209100',
                '-4285233729',
            ];
            $lastSummaryTimestamp = time();
            $systemVariableRepository->writeSystemVariable(
                OpenAISummaryProvider::LAST_SUMMARY_TIMESTAMP_SYSTEM_VARIABLE_NAME,
                $lastSummaryTimestamp
            );

            foreach ($chats as $chat) {
                $handleTask = new SummaryTask(
                    $workerId++,
                    $chat,
                    $telegram->getBotId(),
                    $telegram->getApiKey(),
                    $_ENV['TELEGRAM_BOT_USERNAME'],
                    $logger,
                );
                $taskExecution = $workerPool->submit($handleTask);
                Amp\async(function () use ($taskExecution, $runningTaskTracker) {
                    $runningTaskTracker->receive($taskExecution);
                });
                $taskExecution->getFuture()->catch(function (Throwable $e) use ($chat, $logger) {
                    $logger->log(LogLevel::ERROR, 'Error when providing summary for chat' . $chat . $e->getMessage());
                });
            }
        }
        $iterationId++;
    } catch (TelegramException $e) {
        $logger->log(LogLevel::ERROR, 'Telegram error');
        TelegramLog::error($e);
        delay(10);
    } catch (ConnectException $e) {
        $logger->log(LogLevel::ERROR, 'Curl error received, retrying in 10 seconds: ' . $e->getMessage());
        delay(10);
    }
});
EventLoop::repeat(300, static function () use ($logger, $database, $processingResultExecutor) {
    $kickQueueRepository = new KickQueueRepository($database, $logger);
    foreach ($kickQueueRepository->findPendingKickQueueItems() as $item) {
        $logger->log(LogLevel::INFO, 'Found pending kick');
        $message = new InternalMessage();
        $message->chatId = $item->chatId;
        $message->replyToMessageId = $item->joinMessageId;

        $banRequest = Request::banChatMember([
                                                 'chat_id' => $item->chatId,
                                                 'user_id' => $item->userId,
                                             ]);

        if (!$banRequest->isOk()) {
            $logger->log(LogLevel::ERROR, 'Failed to ban user ' . $item->userId . ' in chat ' . $item->chatId . ': ' . print_r($banRequest, true));

            $message->messageText = "Пользователь id" . $item->userId. " не ответил вовремя на вопрос, но мне не удалось удалить его. Баньте!";
        } else {
            $unbanRequest = Request::unbanChatMember([
                                                         'chat_id' => $item->chatId,
                                                         'user_id' => $item->userId,
                                                     ]);
            if (!$unbanRequest->isOk()) {
                $logger->log(LogLevel::ERROR, 'Failed to unban user: ' . print_r($unbanRequest, true));
                $message->messageText = "Пользователь id" . $item->userId. " не ответил вовремя на вопрос и был забанен!";
            } else {
                $message->messageText = "Пользователь id" . $item->userId . " не ответил вовремя на вопрос и был удалён из чата";
            }
        }
        $processingResultExecutor->execute(new ProcessingResult($message, true));
        $logger->log(LogLevel::INFO, 'Deleting messages ' . json_encode($item->messagesToDelete, JSON_THROW_ON_ERROR));
        $deleteMessagesResult = Request::execute('deleteMessages', [
            'chat_id' => $item->chatId,
            'message_ids' => json_encode($item->messagesToDelete, JSON_THROW_ON_ERROR),
        ]);
        $logger->log(LogLevel::DEBUG, 'deleteMessages result: ' . print_r($deleteMessagesResult, true));
        $kickQueueRepository->nullKickTime($item->pollId);
    }

});

$patchesTaskRunning = false;
EventLoop::repeat(300, static function() use($logger, $telegram, $workerPool, &$patchesTaskRunning) {
    if ($patchesTaskRunning) {
        $logger->log(LogLevel::INFO, 'Previous last patches read has not yet completed');
        return;
    }
    $patchesTaskRunning = true;
    $logger->log(LogLevel::INFO, 'Reading last patches...');

    $task = new PatchesMonitorTask(
        $telegram->getBotId(),
        $telegram->getApiKey(),
        $telegram->getBotUsername(),
        $logger,
    );
    $futureWithHandler = $workerPool->submit($task)->getFuture()->catch(
        function (Throwable $error) use ($logger) {
            $logger->log(LogLevel::ERROR, 'Error when getting last patches: ' . $error->getMessage());
            return null;
        }
    );

    $futureWithHandler->await();
    $patchesTaskRunning = false;
});
EventLoop::run();
