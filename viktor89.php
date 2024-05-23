<?php

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Orhanerday\OpenAi\OpenAi;

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

$openAi = new OpenAi('');
$openAi->setBaseURL($_ENV['OPENAI_SERVER']);

function parse_completion_string(string $completionString): array
{
    if (!str_starts_with($completionString, 'data: ')) {
        die("Unexpected completion string: $completionString");
    }

    return json_decode(substr($completionString, strlen('data: '), JSON_THROW_ON_ERROR), true);
}

function getCompletion(string $prompt): string
{
//    $prompt = mb_substr($prompt, 0, 2048);
//    echo $prompt;
    global $openAi;
    $opts = [
        'prompt'            => $prompt,
        'temperature'       => 0.61,
        'repeat_penalty' => 1.18,
        "penalize_nl" => true,
        "top_k"=> 40,
        "top_p" => 0.95,
        "min_p" => 0.05,
        "tfs_z" => 1,
//        "max_tokens"        => 150,
        "frequency_penalty" => 0,
        "presence_penalty"  => 0,
        "stream"            => true,
    ];
    $fullContent = '';
    try {
        $openAi->completion($opts, function ($curl_info, $data) use (&$fullContent) {
            $parsedData = parse_completion_string($data);
            echo $parsedData['content'];
            $fullContent .= $parsedData['content'];
            if (str_contains($fullContent, "\n<")) { //todo: check for >
                $fullContent = mb_substr($fullContent, 0, mb_strpos($fullContent, "\n<"));

                return 0;
            }

            return strlen($data);
        });
    } catch (\Exception $e) {
    }

    return $fullContent;
}
$chatByUser = [];
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
                    $chatByUser[$message->getFrom()->getId()] = '';
                }

                $toAddToPrompt = "<" . $message->getFrom()->getUsername() . '>: ' . $incomingMessageText . "\n<Виктор 89>: ";
                echo $toAddToPrompt;
                if (!array_key_exists($message->getFrom()->getId(), $chatByUser)) {
                    $chatByUser[$message->getFrom()->getId()] = '';
                }
                $chatByUser[$message->getFrom()->getId()] .= $toAddToPrompt;
                Request::sendChatAction([
                                            'chat_id' => $message->getChat()->getId(),
                                            'action'  => Longman\TelegramBot\ChatAction::TYPING,
                                        ]);
                $response = getCompletion($chatByUser[$message->getFrom()->getId()]);
                $addToChat = "$response\n";
                echo $addToChat;
                $chatByUser[$message->getFrom()->getId()].= $addToChat;
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
