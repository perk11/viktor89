<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Exception;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler;
use Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler;
use Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\UserSelectedAssistant;
use Perk11\Viktor89\ImageGeneration\DefaultingToFirstInConfigModelPreferenceReader;
use Perk11\Viktor89\ImageGeneration\DownscaleProcessor;
use Perk11\Viktor89\ImageGeneration\ImageRemixer;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\ImageTransformProcessor;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\RemixProcessor;
use Perk11\Viktor89\ImageGeneration\RestyleGenerator;
use Perk11\Viktor89\ImageGeneration\RmBgApiClient;
use Perk11\Viktor89\ImageGeneration\SaveAsProcessor;
use Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor;
use Perk11\Viktor89\ImageGeneration\UpscaleApiClient;
use Perk11\Viktor89\ImageGeneration\ZoomApiClient;
use Perk11\Viktor89\ImageGeneration\ZoomCommandProcessor;
use Perk11\Viktor89\IPC\EngineProgressUpdateCallback;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\IPC\StatusProcessor;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\JoinQuiz\JoinQuizProcessor;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use Perk11\Viktor89\PreResponseProcessor\HelloProcessor;
use Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor;
use Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\NumericPreferenceInRangeByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor;
use Perk11\Viktor89\PreResponseProcessor\ReactProcessor;
use Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor;
use Perk11\Viktor89\Quiz\QuestionRepository;
use Perk11\Viktor89\Quiz\RandomQuizResponder;
use Perk11\Viktor89\RateLimiting\RateLimit;
use Perk11\Viktor89\RateLimiting\RateLimitsCommandProcessor;
use Perk11\Viktor89\VideoGeneration\AssistedVideoProcessor;
use Perk11\Viktor89\VideoGeneration\Img2VideoClient;
use Perk11\Viktor89\VideoGeneration\Txt2VideoClient;
use Perk11\Viktor89\VideoGeneration\TxtAndVid2VideoClient;
use Perk11\Viktor89\VideoGeneration\VideoImg2VidProcessor;
use Perk11\Viktor89\VideoGeneration\VideoProcessor;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
use Perk11\Viktor89\VideoGeneration\VideoTxtAndVid2VidProcessor;
use Perk11\Viktor89\VoiceGeneration\DialogResponder;
use Perk11\Viktor89\VoiceGeneration\TtsApiClient;
use Perk11\Viktor89\VoiceGeneration\TtsProcessor;
use Perk11\Viktor89\VoiceGeneration\VoiceResponder;
use Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber;
use Perk11\Viktor89\VoiceRecognition\TranscribeProcessor;
use Perk11\Viktor89\VoiceRecognition\VoiceProcessor;
use Perk11\Viktor89\VoiceRecognition\VoiceRecogniser;

class ProcessMessageTask implements Task
{
    public function __construct(
        private readonly int $workerId,
        private readonly Message $message,
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): bool
    {
        ini_set('memory_limit', -1);

        $progressUpdateCallback = new EngineProgressUpdateCallback($channel, $this->workerId);
        try {
         $this->handle($channel, $progressUpdateCallback);
        } catch (Exception $e) {
            echo "Error " . $e->getMessage() . "\n". $e->getTraceAsString();
        } finally {
            if ($progressUpdateCallback->wasCalled) {
                $channel->send(new TaskCompletedMessage($this->workerId));
            }
        }
//        echo "Done handling\n";
        return true;
    }

    public function handle(Channel $channel, ProgressUpdateCallback $progressUpdateCallback): void
    {

        $dotenv = Dotenv::createImmutable(__DIR__.'/..');
        $dotenv->load();
        $telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);
        $database = new Database($this->telegramBotId, 'siepatch-non-instruct5');
        $historyReader = new HistoryReader($database);
        $cacheFileManager = new CacheFileManager($database);
        $telegramFileDownloader = new TelegramFileDownloader($cacheFileManager, $this->telegramApiKey);
        $denoisingStrengthProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $database,
            ['/denoising_strength', '/denoisingstrength'],
            'denoising-strength',
            $this->telegramBotUsername,
            0,
            1,
        );
        $stepsProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $database,
            ['/steps',],
            'steps',
            $this->telegramBotUsername,
            1,
            75,
        );
        $seedProcessor = new UserPreferenceSetByCommandProcessor(
            $database,
            ['/seed',],
            'seed',
            $this->telegramBotUsername,
        );
        $clownProcessor = new UserPreferenceSetByCommandProcessor(
            $database,
            ['/clown',],
            'clown',
            $this->telegramBotUsername,
        );
        $openAiCompletionStringParser = new OpenAiCompletionStringParser();
        $configFilePath =__DIR__ . '/../config.json';
        $configString = file_get_contents($configFilePath);
        if ($configString === false) {
            throw new Exception("Failed to read $configFilePath");
        }
        $config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
        $imageModelConfig = $config['imageModels'];
        $editModelConfig = $config['imageEditModels'];
        $imageModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/imagemodel'],
            'imagemodel',
            $this->telegramBotUsername,
            array_keys($imageModelConfig),
        );
        $editModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/editmodel'],
            'editmodel',
            $this->telegramBotUsername,
            array_keys($editModelConfig),
        );
        $imageModelPreferenceReader = new DefaultingToFirstInConfigModelPreferenceReader(
            $imageModelProcessor,
            $config['imageModels'],
        );
        $editModelPreferenceReader = new DefaultingToFirstInConfigModelPreferenceReader(
            $editModelProcessor,
            $editModelConfig,
        );
        $imageSizeProcessor = new ListBasedPreferenceByCommandProcessor(
            $database, ['/imagesize'],
            'imagesize',
            $this->telegramBotUsername,
            $config['imageSizes'],
        );
        $automatic1111APiClient = new ImageGeneration\Automatic1111APiClient(
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $imageModelProcessor,
            $imageModelConfig,
            $imageSizeProcessor,
        );
        $editAutomatic1111APiClient = new ImageGeneration\Automatic1111APiClient(
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $editModelPreferenceReader,
            $editModelConfig,
            $imageSizeProcessor,
        );
        $systemPromptProcessor = new UserPreferenceSetByCommandProcessor(
            $database,
            ['/system_prompt', '/systemprompt'],
            'system_prompt',
            $this->telegramBotUsername,
        );
        $responseStartProcessor = new UserPreferenceSetByCommandProcessor(
            $database,
            ['/responsestart', '/response-start'],
            'response-start',
            $this->telegramBotUsername,
        );
        $voiceRecogniser = new VoiceRecogniser($config['whisperCppUrl']);
        $internalMessageTranscriber = new InternalMessageTranscriber(
            $telegramFileDownloader,
            $voiceRecogniser,
            $database,
        );

        $altTextProvider = new AltTextProvider($telegramFileDownloader, $internalMessageTranscriber, $database);
        $assistantFactory = new AssistantFactory(
            $config['assistantModels'],
            $systemPromptProcessor,
            $responseStartProcessor,
            $openAiCompletionStringParser,
            $telegramFileDownloader,
            $altTextProvider,
            $telegram->getBotId(),
        );
        $altTextProvider->assistantWithVision = $assistantFactory->getAssistantInstanceByName('vision-for-alt-text');
        $assistantModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/assistantmodel'],
            'assistantmodel',
            $this->telegramBotUsername,
            $assistantFactory->getSupportedModels(),
        );

        $sayModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/saymodel', '/voice', '/voicemodel'],
            'saymodel',
            $this->telegramBotUsername,
            array_keys($config['voiceModels']),
        );
        $imageRepository = new ImageRepository($database->sqlite3Database);
        $imgTagExtractor = new ImgTagExtractor($imageRepository);
        $assistedImageGenerator = new AssistedImageGenerator(
            $automatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $imageModelPreferenceReader,
            $imageModelConfig,
        );
        $editAssistedImageGenerator = new AssistedImageGenerator(
            $editAutomatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $editModelPreferenceReader,
            $editModelConfig,
        );
        $photoResponder = new PhotoResponder($database, $cacheFileManager);
        $processingResultExecutor= new ProcessingResultExecutor($database);
//$fallBackResponder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
//$fallBackResponder = new \Perk11\Viktor89\SiepatchInstruct6($database);
        $responder = new SiepatchNonInstruct4(
            $historyReader,
            $database,
            $processingResultExecutor,
            $responseStartProcessor,
            $openAiCompletionStringParser,
            $this->telegramBotUsername,
        );
        $responder->addPreResponseProcessor(new AllowedChatProcessor([
                                                                         '-1001804789551',
                                                                         '-1002114209100',
                                                                         '-1002398016894',
                                                                     ]));
        $responder->addAbortResponseHandler(new MaxLengthHandler(2000));
        $responder->addAbortResponseHandler(new MaxNewLinesHandler(40));
        $responder->addAbortResponseHandler(new RepetitionAfterAuthorHandler());
        $questionRepository = new QuestionRepository($database);
        $userSelectedAssistant = new UserSelectedAssistant($assistantFactory, $assistantModelProcessor);
        $videoModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/videomodel'],
            'videomodel',
            $this->telegramBotUsername,
            array_keys($config['videoModels']),
        );
        $imv2VideModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/img2videomodel'],
            'img2videomodel',
            $this->telegramBotUsername,
            array_keys($config['img2videoModels']),
        );
        $upscaleModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $database,
            ['/upscalemodel'],
            'upscalemodel',
            $this->telegramBotUsername,
            array_keys($config['upscaleModels']),
        );
        $styleProcessor = new UserPreferenceSetByCommandProcessor(
            $database,
            ['/style',],
            'style',
            $this->telegramBotUsername,
        );
        $txt2VideoClient = new Txt2VideoClient($stepsProcessor, $seedProcessor, $videoModelProcessor, $config['videoModels']);
        $img2VideoClient = new Img2VideoClient($stepsProcessor, $seedProcessor, $imv2VideModelProcessor, $config['img2videoModels']);
        $upscaleClient = new UpscaleApiClient($stepsProcessor, $seedProcessor, $upscaleModelProcessor, $config['upscaleModels']);
        $videoResponder = new VideoResponder();
        $videoImg2VidProcessor = new VideoImg2VidProcessor($telegramFileDownloader, $img2VideoClient, $videoResponder);
        $videoProcessor = new VideoProcessor($txt2VideoClient, $videoResponder, $videoImg2VidProcessor);
        $assistedVideoProcessor = new AssistedVideoProcessor(
            $automatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $videoImg2VidProcessor,
            $img2VideoClient,
            $videoResponder,
            current($config['videoFirstFrameImageModels']),
        );
        $rateLimits = [
            '-1001804789551' => 4,
            '6184626947' => 2,
//            '-1002398016894' => 3,

        ];
        $rateLimitObjects = [];
        foreach ($rateLimits as $chat => $limit) {
            $rateLimitObjects[] = new RateLimit($chat, $limit);
        }
        $preResponseProcessors = [
            new RateLimitProcessor(
                $database, $this->telegramBotId,
                $rateLimits,
            ),
            new SaveQuizPollProcessor($questionRepository),
            new JoinQuizProcessor($database),
        ];

        $zoomLevelPreference = new FixedValuePreferenceProvider(2);
        $zoomGenerator = new ZoomApiClient($seedProcessor, $zoomLevelPreference, $config['zoomModels']);
        $zoomImageTransformProcessor = new ImageTransformProcessor($telegramFileDownloader, $zoomGenerator, $photoResponder);
        $zoomCommandProcessor = new ZoomCommandProcessor($zoomImageTransformProcessor, $zoomLevelPreference);
        $restyleAutomatic1111ApiClient = new ImageGeneration\Automatic1111APiClient(
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $imageModelProcessor,
            $config['restyleModels'],
            $imageSizeProcessor,
        );
        $restyleGenerator = new RestyleGenerator(
            $restyleAutomatic1111ApiClient,
            $styleProcessor,
            $imageRepository,
            $assistantFactory->getAssistantInstanceByName('vision-for-remix'),
        );
        $ttsApiClient = new TtsApiClient($config['voiceModels']);
        $voiceResponder = new VoiceResponder();
        $imageGenerateProcessor = new ImageGenerateProcessor(
            ['/image'],
            $automatic1111APiClient,
            $photoResponder,
            $telegramFileDownloader,
            $imgTagExtractor,
            $imageModelPreferenceReader,
        );
        $imagineGenerateProcessor = new ImageGenerateProcessor(
            ['/imagine'],
            $assistedImageGenerator,
            $photoResponder,
            $telegramFileDownloader,
            $imgTagExtractor,
            $imageModelPreferenceReader,
        );
        $eProcessor = new ImageGenerateProcessor(
            ['/e'],
            $editAutomatic1111APiClient,
            $photoResponder,
            $telegramFileDownloader,
            $imgTagExtractor,
            $editModelPreferenceReader,
        );
        $editProcessor = new ImageGenerateProcessor(
            ['/edit'],
            $editAssistedImageGenerator,
            $photoResponder,
            $telegramFileDownloader,
            $imgTagExtractor,
            $editModelPreferenceReader,
        );
        $videoEProcessor = new CommandBasedResponderTrigger(
            ['/ve'],
            new VideoTxtAndVid2VidProcessor(
                $telegramFileDownloader,
                new TxtAndVid2VideoClient(
                    $stepsProcessor,
                    $seedProcessor,
                    $config['videoEditModels'],
                ),
                $videoResponder
            ),
        );
        $rmBgClient = new RmBgApiClient($config['rmBgModels']);
        $rmBgProcessor = new ImageTransformProcessor($telegramFileDownloader, $rmBgClient, $photoResponder);
        $messageChainProcessors = [
            new VoiceProcessor($internalMessageTranscriber),
            $clownProcessor,
            new ReactProcessor($clownProcessor, '🤡'),
            new BlockedChatProcessor([
//                                         '-1002398016894',
                                         '-1002076350723',
                                         '6184626947',
                                     ]),
            $imageModelProcessor,
            $imageSizeProcessor,
            $editModelProcessor,
            $videoModelProcessor,
            $imv2VideModelProcessor,
            $upscaleModelProcessor,
            $styleProcessor,
            $assistantModelProcessor,
            $sayModelProcessor,
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $systemPromptProcessor,
            $responseStartProcessor,
            new CommandBasedResponderTrigger(
                ['/quiz'],
                new RandomQuizResponder($questionRepository)
            ),
            $imageGenerateProcessor,
            $imagineGenerateProcessor,
            $editProcessor,
            $eProcessor,
            new CommandBasedResponderTrigger(
                ['/upscale'],
                new ImageTransformProcessor($telegramFileDownloader, $upscaleClient, $photoResponder)
            ),
            new CommandBasedResponderTrigger(
                ['/downscale'],
                new DownscaleProcessor($telegramFileDownloader, $photoResponder)
            ),
            new CommandBasedResponderTrigger(
                ['/zoom'],
                $zoomCommandProcessor,
            ),
            new CommandBasedResponderTrigger(
                ['/remix'],
                new RemixProcessor(
                    $telegramFileDownloader,
                    $photoResponder,
                    new ImageRemixer(
                        $assistantFactory->getAssistantInstanceByName('vision-for-remix'),
                        $automatic1111APiClient,
                    )
                )
            ),
            new CommandBasedResponderTrigger(
                ['/restyle'],
                new ImageTransformProcessor($telegramFileDownloader, $restyleGenerator, $photoResponder)
            ),
            new CommandBasedResponderTrigger(
                ['/video'],
                $videoProcessor,
            ),
            new CommandBasedResponderTrigger(
                ['/vid'],
                $assistedVideoProcessor,
            ),
            new CommandBasedResponderTrigger(
                ['/start', '/help'],
                new PrintHelpProcessor($database),
            ),
            new CommandBasedResponderTrigger(
                ['/preferences'],
                new PrintUserPreferencesResponder($database),
            ),
            new CommandBasedResponderTrigger(
                ['/say'],
                new TtsProcessor(
                    $ttsApiClient,
                    $voiceResponder,
                    $sayModelProcessor,
                    $config['voiceModels'],
                ),
            ),
            new CommandBasedResponderTrigger(
                ['/transcribe'],
                new TranscribeProcessor($internalMessageTranscriber),
            ),
            $videoEProcessor,
            new CommandBasedResponderTrigger(
                ['/saveas'],
                new SaveAsProcessor($telegramFileDownloader, $imageRepository)
             ),
            new CommandBasedResponderTrigger(
                ['/ratelimits'],
                new RateLimitsCommandProcessor($database, $rateLimitObjects)
            ),
            new CommandBasedResponderTrigger(
                ['/podcast'],
                new DialogResponder(
                    $assistantFactory->getAssistantInstanceByName('podcast'),
                    $ttsApiClient,
                    $voiceResponder,
                    $config['podcastVoices'],
                ),
            ),
            new CommandBasedResponderTrigger(
                ['/status'],
                new StatusProcessor($channel),
            ),
            new CommandBasedResponderTrigger(
                ['/file'],
                new SendAsDocumentProcessor($cacheFileManager, $database),
            ),
            new CommandBasedResponderTrigger(
                ['/rmbg'],
                $rmBgProcessor,
            ),
            new CommandBasedResponderTrigger(
                ['/assistant'],
                $userSelectedAssistant,
                $telegram->getBotId(),
            ),
            new WhoAreYouProcessor(),
            new HelloProcessor(),
        ];
        $messageChainProcessorRunner = new MessageChainProcessorRunner($processingResultExecutor, $messageChainProcessors);
        $engine = new Engine($database,
                             $historyReader,
                             $preResponseProcessors,
                             $messageChainProcessorRunner,
                             $this->telegramBotUsername,
                             $this->telegramBotId,
                             $responder,
                             $progressUpdateCallback,
        );
        $engine->handleMessage($this->message);
    }
}
