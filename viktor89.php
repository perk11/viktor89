<?php

use GuzzleHttp\Exception\ConnectException;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\HistoryReader;
use Perk11\Viktor89\InternalMessage;

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

function parse_completion_string(string $completionString)
{
    if (!str_starts_with($completionString, 'data: ')) {
        die("Unexpected completion string: $completionString");
    }

    return json_decode(substr($completionString, strlen('data: '), JSON_THROW_ON_ERROR), true);
}

$telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);

//$responder = new \Perk11\Viktor89\SiepatchNoInstructResponseGenerator();
//$responder = new \Perk11\Viktor89\Siepatch2Responder();
$database = new \Perk11\Viktor89\Database($telegram->getBotId(), 'siepatch-non-instruct5');
$historyReader = new HistoryReader($database);
$responder = new \Perk11\Viktor89\SiepatchNonInstruct4(
    $historyReader,
    $database,
);
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler(2000));
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler(40));
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler());
//$responder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
//$responder = new \Perk11\Viktor89\SiepatchInstruct6($database);
$preResponseProcessors = [
    new \Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor(
        $database, $telegram->getBotId(),
        [
//            '-4233480248' => 3,
            '-1001804789551' => 4,
        ]
    ),
    new \Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor(),
    new \Perk11\Viktor89\PreResponseProcessor\HelloProcessor(),
];
try {
    echo "Connecting to Telegram...\n";
    $telegram->useGetUpdatesWithoutDatabase();
    while (true) {
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
                $message = $result->getMessage();


                if ($message === null) {
                    echo "Unknown update received:\n";
                    var_dump($result);
                    continue;
                }
                /** @var \Longman\TelegramBot\Entities\Message $message */
                if ($message->getType() !== 'text' && $message->getType() !== 'command') {
                    echo "Message of type {$message->getType()} received\n";
                    if ($message->getType() === 'sticker') {
                        echo $message->getSticker()->getFileId() . "\n";
                    }
//                    var_dump($message);
                    continue;
                }
                if ($message->getFrom() === null) {
                    echo "Message without a sender received\n";
                    continue;
                }
                $database->logMessage($message);
                foreach ($preResponseProcessors as $preResponseProcessor) {
                    $replacedMessage = $preResponseProcessor->process($message);
                    if ($replacedMessage !== false) {
                        if ($replacedMessage === null) {
                            continue 2;
                        }
                        $internalMessage = new InternalMessage();
                        $internalMessage->chatId = $message->getChat()->getId();
                        $internalMessage->replyToMessageId = $message->getMessageId();
                        $internalMessage->userName = $_ENV['TELEGRAM_BOT_USERNAME'];
                        $internalMessage->messageText = $replacedMessage;

                        $internalMessage->send();
                        continue 2;
                    }
                }
                $incomingMessageText = $message->getText();

                if ($message->getType() !== 'command') {
                    if (!str_contains($incomingMessageText, '@' . $_ENV['TELEGRAM_BOT_USERNAME'])) {
                        $replyToMessage = $message->getReplyToMessage();
                        if ($replyToMessage === null) {
                            continue;
                        }
                        if ($replyToMessage->getFrom()->getId() !== $telegram->getBotId()) {
                            continue;
                        }
                    }
                }
                $responseMessage = $responder->getResponseByMessage($message);

                if ($responseMessage !== null) {
                    $telegramServerResponse = $responseMessage->send();
                    if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult(
                        ) instanceof \Longman\TelegramBot\Entities\Message) {
                        $responseMessage->id = $telegramServerResponse->getResult()->getMessageId();
                        $responseMessage->chatId = $message->getChat()->getId();
                        $responseMessage->userId = $telegramServerResponse->getResult()->getFrom()->getId();
                        $responseMessage->date = time();
                        $database->logInternalMessage($responseMessage);
                    }
                }
            }
        } else {
            echo date('Y-m-d H:i:s') . ' - Failed to fetch updates' . PHP_EOL;
            echo $serverResponse->printError();
        }
        usleep(1000);
    }
} catch (\Longman\TelegramBot\Exception\TelegramException $e) {
    TelegramLog::error($e);
    usleep(10000);
} catch (ConnectException $e) {
    echo $e->getMessage();
    echo "Curl error received, retrying in 10 seconds...\n";
    usleep(10000);
}
