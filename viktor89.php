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

function parse_completion_string(string $completionString): array
{
    if (!str_starts_with($completionString, 'data: ')) {
        die("Unexpected completion string: $completionString");
    }

    return json_decode(substr($completionString, strlen('data: '), JSON_THROW_ON_ERROR), true);
}
//$responder = new \Perk11\Viktor89\SiepatchNoInstructResponseGenerator();
$responder = new \Perk11\Viktor89\Siepatch2Responder();

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
                if ($message->getType() !== 'text') {
                    echo "Message of type {$message->getType()} received\n";
                    var_dump($message);
                    continue;
                }
                $incomingMessageText = $message->getText();

                if (!str_starts_with($incomingMessageText, '@' . $_ENV['TELEGRAM_BOT_USERNAME'])) {
                    $replyToMessage = $message->getReplyToMessage();
                    if ($replyToMessage === null) {
                        continue;
                    }
                    if ($replyToMessage->getFrom()->getId() !== $telegram->getBotId()) {
                        continue;
                    }
                } else {
                    $incomingMessageText = str_replace('@' . $_ENV['TELEGRAM_BOT_USERNAME'], '', $incomingMessageText);
                }
                Request::sendChatAction([
                                            'chat_id' => $message->getChat()->getId(),
                                            'action'  => Longman\TelegramBot\ChatAction::TYPING,
                                        ]);
                $response = $responder->getResponseByMessage($message);

                Request::sendMessage([
                                         'chat_id'          => $message->getChat()->getId(),
                                         'reply_parameters' => [
                                             'message_id' => $message->getMessageId(),
                                         ],
                                         'text'             => $response,
                                     ]);
            }
        } else {
            echo date('Y-m-d H:i:s') . ' - Failed to fetch updates' . PHP_EOL;
            echo $serverResponse->printError();
        }
        usleep(1000);
    }
} catch (\Longman\TelegramBot\Exception\TelegramException $e) {
    TelegramLog::error($e);
}
