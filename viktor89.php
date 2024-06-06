<?php

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;

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
//$responder = new \Perk11\Viktor89\SiepatchNonInstruct4();
//$responder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
$responder = new \Perk11\Viktor89\SiepatchInstruct6($database);

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
                Request::sendChatAction([
                                            'chat_id' => $message->getChat()->getId(),
                                            'action'  => Longman\TelegramBot\ChatAction::TYPING,
                                        ]);
                $response = $responder->getResponseByMessage($message);

                $telegramServerResponse = Request::sendMessage([
                                         'chat_id'          => $message->getChat()->getId(),
                                         'reply_parameters' => [
                                             'message_id' => $message->getMessageId(),
                                         ],
                                         'text'             => $response,
                                     ]);
                if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof \Longman\TelegramBot\Entities\Message) {
                    $replyPrefix = '[отвечает ';
                    if (str_starts_with($response, $replyPrefix)) {
                        $author = mb_substr($response, mb_strlen($replyPrefix), mb_strpos($response, ']') - mb_strlen($replyPrefix));
                        $response = mb_substr($response, mb_strpos($response, '] ') + 2);
                    } else {
                        $author = 'Nanak0n'; //TODO: use username from response instead
                    }
//                    $database->logMessage($telegramServerResponse->getResult());
                    $internalMessage = new \Perk11\Viktor89\InternalMessage();
                    $internalMessage->id = $telegramServerResponse->getResult()->getMessageId();
                    $internalMessage->chatId = $message->getChat()->getId();
                    $internalMessage->userId = $telegramServerResponse->getResult()->getFrom()->getId();
                    $internalMessage->messageText = $response;
                    $internalMessage->replyToMessageId = $message->getMessageId();
                    $internalMessage->date = time();
                    $internalMessage->userName = $author;
                    $database->logInternalMessage($internalMessage);
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
