<?php

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\HistoryReader;

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
//$responder = new \Perk11\Viktor89\SiepatchNoInstructResponseGenerator();
//$responder = new \Perk11\Viktor89\Siepatch2Responder();
$database = new \Perk11\Viktor89\Database('siepatch-non-instruct5');
$historyReader = new HistoryReader($database);
$responder = new \Perk11\Viktor89\SiepatchNonInstruct4(
    $historyReader,
    new \Perk11\Viktor89\PreResponseProcessor\PersonalityProcessor($database),
);
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler(2000));
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler(40));
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler());
//$responder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
//$responder = new \Perk11\Viktor89\SiepatchInstruct6($database);

try {
    $telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);
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
//                    var_dump($message);
                    continue;
                }
                if ($message->getFrom() === null) {
                    echo "Message without a sender received\n";
                    continue;
                }
                $database->logMessage($message);
                $incomingMessageText = $message->getText();
                $incomingMessageTextLower = mb_strtolower($incomingMessageText);
                $whoAreYouTriggerPhrases = [
                    'как тебя зовут',
                    'тебя как зовут',
                    'ты кто?',
                    'кто ты?',
                ];
                foreach ($whoAreYouTriggerPhrases as $whoAreYouTriggerPhrase) {
                    if (str_contains($incomingMessageTextLower, $whoAreYouTriggerPhrase)) {
                        echo "Sending message with viktor89 sticker";
                        $viktor89Stickers = [
                            'CAACAgIAAxkBAAIGvGZhcMm-j-Fa2u-jsOXYTBpNHPGpAAKxTQACaw0oSRHS0GD7_dE6NQQ',
                            'CAACAgIAAxkBAAIGvWZhcNXDnZVd9vZ4Rydl7KyKeDcCAAJyWwACpg2ISv8GUoIYyRcrNQQ',
                        ];
                        $result = Request::sendSticker([
                                                           'chat_id' => $message->getChat()->getId(),
                                                           'reply_parameters' => [
                                                               'message_id' => $message->getMessageId(),
                                                           ],
                                                           'sticker' => $viktor89Stickers[array_rand(
                                                               $viktor89Stickers
                                                           )],

                                                       ]);
                        continue 2;
                    }
                }

                if ($message->getType() !== 'command') {
                    if (!str_contains($incomingMessageText, '@' . $_ENV['TELEGRAM_BOT_USERNAME'])) {
                        $replyToMessage = $message->getReplyToMessage();
                        if ($replyToMessage === null) {
                            continue;
                        }
                        if ($replyToMessage->getFrom()->getId() !== $telegram->getBotId()) {
                            continue;
                        }
                    } else {
                        $incomingMessageText = str_replace(
                            '@' . $_ENV['TELEGRAM_BOT_USERNAME'],
                            '',
                            $incomingMessageText
                        );
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
}
