<?php

use GuzzleHttp\Exception\ConnectException;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Perk11\Viktor89\HistoryReader;
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

//$fallBackResponder = new \Perk11\Viktor89\SiepatchNoInstructResponseGenerator();
//$fallBackResponder = new \Perk11\Viktor89\Siepatch2Responder();
$database = new \Perk11\Viktor89\Database($telegram->getBotId(), 'siepatch-non-instruct5');
$historyReader = new HistoryReader($database);


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
$modelConfigFilePath =__DIR__ . '/automatic1111-model-config.json';
$modelConfigString = file_get_contents($modelConfigFilePath);
if ($modelConfigString === false) {
    throw new \Exception("Failed to read $modelConfigFilePath");
}
$modelConfig = json_decode($modelConfigString, true, 512, JSON_THROW_ON_ERROR);
$imageModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
    $database,
    ['/imagemodel'],
    'imagemodel',
    array_keys($modelConfig),
);
$automatic1111APiClient = new \Perk11\Viktor89\Automatic1111APiClient(
    $denoisingStrengthProcessor,
    $stepsProcessor,
    $seedProcessor,
    $imageModelProcessor,
    $modelConfig,
);
$photoResponder = new PhotoResponder();
$photoImg2ImgProcessor = new PhotoImg2ImgProcessor(
    $telegramPhotoDownloader,
    $automatic1111APiClient,
    $photoResponder,
);
$systemPromptProcessor = new UserPreferenceSetByCommandProcessor(
    $database,
    ['/system_prompt', '/systemprompt'],
    'system_prompt',
);
$responseStartProcessor = new UserPreferenceSetByCommandProcessor(
    $database,
    ['/responsestart', '/response-start'],
    'response-start',
);

//$fallBackResponder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
//$fallBackResponder = new \Perk11\Viktor89\SiepatchInstruct6($database);
$responder = new \Perk11\Viktor89\SiepatchNonInstruct4(
    $historyReader,
    $database,
    $responseStartProcessor,
);
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler(2000));
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler(40));
$responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler());
$preResponseProcessors = [
    new \Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor(
        $database, $telegram->getBotId(),
        [
//            '-4233480248' => 3,
            '-1001804789551' => 4,
        ]
    ),
    $imageModelProcessor,
    new \Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor(
        ['/image'],
        $automatic1111APiClient,
        $photoResponder,
        $photoImg2ImgProcessor,
    ),
    $denoisingStrengthProcessor,
    $stepsProcessor,
    $seedProcessor,
    $systemPromptProcessor,
    $responseStartProcessor,
    new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
        ['/assistant'],
        $database,
        new \Perk11\Viktor89\PreResponseProcessor\OpenAIAPIAssistant($systemPromptProcessor, $responseStartProcessor),
    ),
    new \Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor(),
    new \Perk11\Viktor89\PreResponseProcessor\HelloProcessor(),
];
echo "Connecting to Telegram...\n";
$telegram->useGetUpdatesWithoutDatabase();
$iterationId = 0;
$engine = new \Perk11\Viktor89\Engine($photoImg2ImgProcessor, $database, $preResponseProcessors, $telegram, $responder);
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
                if ($result->getMessage() === null) {
                    echo "Unknown update received:\n";
                    return;
                }
                $engine->handleMessage($result->getMessage());
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
