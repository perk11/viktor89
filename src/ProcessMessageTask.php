<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\PreResponseProcessor\NumericPreferenceInRangeByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\Quiz\QuestionRepository;
use Perk11\Viktor89\Quiz\RandomQuizResponder;

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
        $openAiCompletionStringParser = new OpenAiCompletionStringParser();
        $configFilePath =__DIR__ . '/../config.json';
        $configString = file_get_contents($configFilePath);
        if ($configString === false) {
            throw new \Exception("Failed to read $configFilePath");
        }
        $config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
        $imageModelConfig = $config['imageModels'];
        $imageModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/imagemodel'],
            'imagemodel',
            array_keys($imageModelConfig),
        );
        $automatic1111APiClient = new ImageGeneration\Automatic1111APiClient(
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $imageModelProcessor,
            $imageModelConfig,
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
        $assistantFactory = new AssistantFactory(
            $config['assistantModels'],
            $systemPromptProcessor,
            $responseStartProcessor,
            $openAiCompletionStringParser,
        );
        $assistedImageGenerator = new \Perk11\Viktor89\AssistedImageGenerator(
            $automatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
        );
        $photoResponder = new PhotoResponder();
        $photoImg2ImgProcessor = new PhotoImg2ImgProcessor(
            $telegramPhotoDownloader,
            $automatic1111APiClient,
            $photoResponder,
        );
        $assistedPhotoImg2ImgProcessor = new PhotoImg2ImgProcessor(
            $telegramPhotoDownloader,
            $assistedImageGenerator,
            $photoResponder,
        );
//$fallBackResponder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
//$fallBackResponder = new \Perk11\Viktor89\SiepatchInstruct6($database);
        $responder = new \Perk11\Viktor89\SiepatchNonInstruct4(
            $historyReader,
            $database,
            $responseStartProcessor,
            $openAiCompletionStringParser,
        );
        $responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler(2000));
        $responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler(40));
        $responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler());
        $questionRepository = new QuestionRepository($database);
        $preResponseProcessors = [
            new \Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor(
                $database, $this->telegramBotId,
                [
//            '-4233480248' => 3,
                    '-1001804789551' => 4,
                ]
            ),
            new SaveQuizPollProcessor($questionRepository),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/quiz'],
                $database,
                new RandomQuizResponder($questionRepository)
            ),
            $imageModelProcessor,
            new \Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor(
                ['/image'],
                $automatic1111APiClient,
                $photoResponder,
                $photoImg2ImgProcessor,
            ),
            new \Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor(
                ['/imagine'],
                $assistedImageGenerator,
                $photoResponder,
                $assistedPhotoImg2ImgProcessor,
            ),
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $systemPromptProcessor,
            $responseStartProcessor,
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/assistant'],
                $database,
                $assistantFactory->getAssistantInstanceByName('gemma2'),
            ),
            new \Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor(),
            new \Perk11\Viktor89\PreResponseProcessor\HelloProcessor(),
        ];

        $engine = new \Perk11\Viktor89\Engine($photoImg2ImgProcessor,
                                              $database,
                                              $historyReader,
                                              $preResponseProcessors,
                                              $this->telegramBotUsername,
                                              $this->telegramBotId,
                                              $responder
        );
        $engine->handleMessage($this->message);
    }
}
