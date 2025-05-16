<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\UserSelectedAssistant;
use Perk11\Viktor89\ImageGeneration\ClownifyApiClient;
use Perk11\Viktor89\ImageGeneration\ClownifyProcessor;
use Perk11\Viktor89\ImageGeneration\DownscaleProcessor;
use Perk11\Viktor89\ImageGeneration\ImageRemixer;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\PhotoImg2ImgProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\RemixProcessor;
use Perk11\Viktor89\ImageGeneration\SaveAsProcessor;
use Perk11\Viktor89\ImageGeneration\UpscaleApiClient;
use Perk11\Viktor89\ImageGeneration\UpscaleProcessor;
use Perk11\Viktor89\JoinQuiz\JoinQuizProcessor;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\NumericPreferenceInRangeByCommandProcessor;
use Perk11\Viktor89\PreResponseProcessor\ReactProcessor;
use Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\Quiz\QuestionRepository;
use Perk11\Viktor89\Quiz\RandomQuizResponder;
use Perk11\Viktor89\VideoGeneration\AssistedVideoProcessor;
use Perk11\Viktor89\VideoGeneration\Img2VideoClient;
use Perk11\Viktor89\VideoGeneration\Txt2VideoClient;
use Perk11\Viktor89\VideoGeneration\VideoImg2VidProcessor;
use Perk11\Viktor89\VideoGeneration\VideoProcessor;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
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
        private readonly Message $message,
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): bool
    {
        ini_set('memory_limit', -1);

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
        $telegramFileDownloader = new TelegramFileDownloader($this->telegramApiKey);
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
            throw new \Exception("Failed to read $configFilePath");
        }
        $config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
        $imageModelConfig = $config['imageModels'];
        $imageModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/imagemodel'],
            'imagemodel',
            $this->telegramBotUsername,
            array_keys($imageModelConfig),
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
        $assistantFactory = new AssistantFactory(
            $config['assistantModels'],
            $systemPromptProcessor,
            $responseStartProcessor,
            $openAiCompletionStringParser,
            $telegramFileDownloader,
            $telegram->getBotId(),
        );
        $assistantModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/assistantmodel'],
            'assistantmodel',
            $this->telegramBotUsername,
            $assistantFactory->getSupportedModels(),
        );

        $sayModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/saymodel', '/voice', '/voicemodel'],
            'saymodel',
            $this->telegramBotUsername,
            array_keys($config['voiceModels']),
        );
        $assistedImageGenerator = new \Perk11\Viktor89\AssistedImageGenerator(
            $automatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $imageModelProcessor,
            $imageModelConfig,
        );
        $photoResponder = new PhotoResponder($database);
        $photoImg2ImgProcessor = new PhotoImg2ImgProcessor(
            $telegramFileDownloader,
            $automatic1111APiClient,
            $photoResponder,
        );
        $assistedPhotoImg2ImgProcessor = new PhotoImg2ImgProcessor(
            $telegramFileDownloader,
            $assistedImageGenerator,
            $photoResponder,
        );
        $processingResultExecutor= new ProcessingResultExecutor($database);
//$fallBackResponder = new \Perk11\Viktor89\SiepatchNonInstruct5($database);
//$fallBackResponder = new \Perk11\Viktor89\SiepatchInstruct6($database);
        $responder = new \Perk11\Viktor89\SiepatchNonInstruct4(
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
        $responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler(2000));
        $responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\MaxNewLinesHandler(40));
        $responder->addAbortResponseHandler(new \Perk11\Viktor89\AbortStreamingResponse\RepetitionAfterAuthorHandler());
        $questionRepository = new QuestionRepository($database);
        $userSelectedAssistant = new UserSelectedAssistant($assistantFactory, $assistantModelProcessor);
        $videoModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/videomodel'],
            'videomodel',
            $this->telegramBotUsername,
            array_keys($config['videoModels']),
        );
        $imv2VideModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/img2videomodel'],
            'img2videomodel',
            $this->telegramBotUsername,
            array_keys($config['img2videoModels']),
        );
        $upscaleModelProcessor = new \Perk11\Viktor89\PreResponseProcessor\ListBasedPreferenceByCommandProcessor(
            $database,
            ['/upscalemodel'],
            'upscalemodel',
            $this->telegramBotUsername,
            array_keys($config['upscaleModels']),
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
        $voiceRecogniser = new VoiceRecogniser($config['whisperCppUrl']);
        $preResponseProcessors = [
            new \Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor(
                $database, $this->telegramBotId,
                [
//            '-4233480248' => 3,
                    '-1001804789551' => 4,
                    '6184626947' => 2,
                ]
            ),
            new SaveQuizPollProcessor($questionRepository),
            new JoinQuizProcessor($database),
        ];
        $internalMessageTranscriber = new InternalMessageTranscriber($telegramFileDownloader, $voiceRecogniser);

        $imageRepository = new ImageRepository($database->sqlite3Database);
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
            $videoModelProcessor,
            $imv2VideModelProcessor,
            $upscaleModelProcessor,
            $assistantModelProcessor,
            $sayModelProcessor,
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $systemPromptProcessor,
            $responseStartProcessor,
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/quiz'],
                false,
                new RandomQuizResponder($questionRepository)
            ),
            new \Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor(
                ['/image'],
                $automatic1111APiClient,
                $photoResponder,
                $photoImg2ImgProcessor,
                $imageRepository,
            ),
            new \Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor(
                ['/imagine'],
                $assistedImageGenerator,
                $photoResponder,
                $assistedPhotoImg2ImgProcessor,
                $imageRepository,
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/upscale'],
                false,
                new UpscaleProcessor($telegramFileDownloader, $upscaleClient, $photoResponder)
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/downscale'],
                false,
                new DownscaleProcessor($telegramFileDownloader, $photoResponder)
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/clownify'],
                false,
                new ClownifyProcessor($telegramFileDownloader,new ClownifyApiClient($config['clownifyModels']), $photoResponder)
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/remix'],
                false,
                new RemixProcessor(
                    $telegramFileDownloader,
                    $photoResponder,
                    new ImageRemixer(
                        $assistantFactory->getAssistantInstanceByName('vision-for-remix'),
                        $automatic1111APiClient,
                    )
                )
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/assistant'],
                true,
                $userSelectedAssistant,
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/video'],
                false,
                $videoProcessor,
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/vid'],
                false,
                $assistedVideoProcessor,
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/start', '/help'],
                false,
                new PrintHelpProcessor($database),
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/preferences'],
                false,
                new PrintUserPreferencesResponder($database),
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/say'],
                false,
                new TtsProcessor(
                    new TtsApiClient($config['voiceModels']),
                    new VoiceResponder(),
                    $sayModelProcessor,
                    $config['voiceModels'],
                ),
            ),
            new \Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger(
                ['/transcribe'],
                false,
                new TranscribeProcessor($internalMessageTranscriber),
            ),
            new CommandBasedResponderTrigger(
                ['/saveas'],
                false,
                new SaveAsProcessor($telegramFileDownloader, $imageRepository)
             ),
            new \Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor(),
            new \Perk11\Viktor89\PreResponseProcessor\HelloProcessor(),
        ];
        $engine = new \Perk11\Viktor89\Engine($photoImg2ImgProcessor,
                                              $database,
                                              $historyReader,
                                              $preResponseProcessors,
                                              $messageChainProcessors,
                                              $this->telegramBotUsername,
                                              $this->telegramBotId,
                                              $responder,
                                              $processingResultExecutor,
        );
        $engine->handleMessage($this->message);
    }
}
