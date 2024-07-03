<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\PreResponseProcessor\NumericPreferenceInRangeByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;

class ProcessMessageTask implements Task
{
    public function __construct(
        private readonly Message $message,
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): bool
    {

        try {
         $this->handle();
        } catch (\Exception $e) {
            echo "Error " . $e->getMessage() . "\n". $e->getTraceAsString();
        }
//        echo "Done handling\n";
        return true;
    }

    public function handle()
    {

        $dotenv = Dotenv::createImmutable(__DIR__.'/..');
        $dotenv->load();
        $telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);
        $database = new Database($this->telegramBotId, 'siepatch-non-instruct5');
        $historyReader = new HistoryReader($database);
        $telegramPhotoDownloader = new TelegramPhotoDownloader($this->telegramApiKey);
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
        $modelConfigFilePath =__DIR__ . '/../automatic1111-model-config.json';
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
                $database, $this->telegramBotId,
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

        $engine = new \Perk11\Viktor89\Engine($photoImg2ImgProcessor, $database, $preResponseProcessors, $this->telegramBotUsername, $responder);
        $engine->handleMessage($this->message);
    }
}
