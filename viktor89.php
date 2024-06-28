<?php

use GuzzleHttp\Exception\ConnectException;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\HistoryReader;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PhotoImg2ImgProcessor;
use Perk11\Viktor89\PhotoResponder;
use Perk11\Viktor89\PreResponseProcessor\NumericPreferenceInRangeByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;

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
$summaryProvider = new \Perk11\Viktor89\ChatGptSummaryProvider($database);
$telegramPhotoDownloader = new \Perk11\Viktor89\TelegramPhotoDownloader($telegram->getApiKey());
$denoisingStrengthProcessor = new NumericPreferenceInRangeByCommandProcessor(
    $database,
    ['/denoising_strength', '/denoisingstrength'],
    'denoising-strength',
    0,
    1,
);
$stepsProcessor = new NumericPreferenceInRangeByCommandProcessor(
    $database,
    ['/steps',],
    'steps',
    1,
    75,
);
$seedProcessor = new UserPreferenceSetByCommandProcessor(
    $database,
    ['/seed',],
    'seed',
);
$automatic1111APiClient = new \Perk11\Viktor89\Automatic1111APiClient(
    $denoisingStrengthProcessor,
    $stepsProcessor,
    $seedProcessor,
);
$photoResponder = new PhotoResponder();
$photoImg2ImgProcessor = new PhotoImg2ImgProcessor(
    $telegramPhotoDownloader,
    $automatic1111APiClient,
    $photoResponder,
);

$tutors = [
    'https://cloud.nw-sys.ru/index.php/s/z97QnXmfcM8QKDn/download',
    'https://cloud.nw-sys.ru/index.php/s/xqpNxq6Akk6SbDX/download',
    'https://cloud.nw-sys.ru/index.php/s/eCkqzWGqGAFRjMQ/download',
];

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
    new \Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor(
        ['/image'],
        $automatic1111APiClient,
        $photoResponder,
    ),
    $denoisingStrengthProcessor,
    $stepsProcessor,
    $seedProcessor,
    new \Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor(),
    new \Perk11\Viktor89\PreResponseProcessor\HelloProcessor(),
    new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
        ['/assistant'],
        $database,
        new \Perk11\Viktor89\PreResponseProcessor\OpenAIAPIAssistant(),
    ),
];
echo "Connecting to Telegram...\n";
$telegram->useGetUpdatesWithoutDatabase();
$iterationId = 0;
while (true) {
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
                $message = $result->getMessage();


                if ($message === null) {
                    echo "Unknown update received:\n";
                    var_dump($result);
                    continue;
                }
                if ($message->getType() === 'photo') {
                    $photoImg2ImgProcessor->processPhoto($message);
                    continue;
                }
                /** @var \Longman\TelegramBot\Entities\Message $message */
                if ($message->getType() !== 'text' && $message->getType() !== 'command' && $message->getType(
                    ) !== 'new_chat_members') {
                    echo "Message of type {$message->getType()} received\n";
                    if ($message->getType() === 'sticker') {
                        echo $message->getSticker()->getFileId() . "\n";
                    }
//                    var_dump($message);
                    continue;
                }

                if ($message->getType() === 'new_chat_members') {
                    echo "New member detected, sending tutorial\n";
                    Request::sendVideo([
                                           'chat_id'             => $message->getChat()->getId(),
                                           'reply_to_message_id' => $message->getMessageId(),
                                           'video'               => $tutors[array_rand($tutors)],
                                       ]);
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

                        $response = $internalMessage->send();
                        if ($response->isOk()) {
                            $database->logMessage($response->getResult());
                        } else {
                            echo "Failed to send message: ";
                            print_r($response->getRawData());
                            echo "\n";
                        }
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
        usleep(1000000);
    } catch (\Longman\TelegramBot\Exception\TelegramException $e) {
        TelegramLog::error($e);
        usleep(10000000);
    } catch (ConnectException $e) {
        echo "Curl error received, retrying in 10 seconds:\n";
        echo $e->getMessage()."\n";
        usleep(10000000);
    }
}
