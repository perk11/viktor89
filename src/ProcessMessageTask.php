<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dotenv\Dotenv;
use Exception;
use LanguageDetection\Language;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Container\ContainerFactory;
use Perk11\Viktor89\EmojiArt\EmojiArtProcessor;
use Perk11\Viktor89\EmojiArt\EmojiPalette;
use Perk11\Viktor89\Assistant\Tool\GetUrlContentsToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ImageGeneratorTelegramPhotoToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ImageUploader;
use Perk11\Viktor89\Assistant\Tool\ImageGeneratorInlineToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ListChainImagesToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ListSavedImagesToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ReactToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\WebSearchToolFactory;
use Perk11\Viktor89\Assistant\UnknownAssistantException;
use Perk11\Viktor89\Assistant\UserSelectedAssistant;
use Perk11\Viktor89\ImageGeneration\DefaultingToFirstInConfigModelPreferenceReader;
use Perk11\Viktor89\ImageGeneration\DownscaleProcessor;
use Perk11\Viktor89\ImageGeneration\ImageRemixer;
use Perk11\Viktor89\ImageGeneration\ImageCatalogPdfProcessor;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\ImageTransformProcessor;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\ImageGeneration\MultipleModelsImageGenerateProcessor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\ImageGeneration\RemixProcessor;
use Perk11\Viktor89\ImageGeneration\RestyleGenerator;
use Perk11\Viktor89\ImageGeneration\RmBgApiClient;
use Perk11\Viktor89\ImageGeneration\SaveAsProcessor;
use Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor;
use Perk11\Viktor89\ImageGeneration\UpscaleApiClient;
use Perk11\Viktor89\ImageGeneration\ZoomApiClient;
use Perk11\Viktor89\ImageGeneration\ZoomCommandProcessor;
use Perk11\Viktor89\IPC\ChannelBeforeMessageSentNotifier;
use Perk11\Viktor89\IPC\ChannelDraftUpdateCallback;
use Perk11\Viktor89\IPC\EngineProgressUpdateCallback;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\IPC\StatusProcessor;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\JoinQuiz\JoinQuizProcessor;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use Perk11\Viktor89\PreResponseProcessor\HelloProcessor;
use Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor;
use Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor;
use Perk11\Viktor89\PreResponseProcessor\ReactProcessor;
use Perk11\Viktor89\PreResponseProcessor\SaveQuizPollProcessor;
use Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor;
use Perk11\Viktor89\PersonalityCard\PersonalityCardProcessor;
use Perk11\Viktor89\PersonalityCard\PersonalityCardRenderer;
use Perk11\Viktor89\UserSettings\DynamicListBasedPreferenceByCommandProcessor;
use Perk11\Viktor89\UserSettings\ListBasedPreferenceByCommandProcessor;
use Perk11\Viktor89\UserSettings\NumericPreferenceInRangeByCommandProcessor;
use Perk11\Viktor89\UserSettings\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\Quiz\RandomQuizResponder;
use Perk11\Viktor89\TalkersCommandProcessor;
use Perk11\Viktor89\RateLimiting\RateLimit;
use Perk11\Viktor89\RateLimiting\RateLimitsCommandProcessor;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use Perk11\Viktor89\VideoGeneration\AssistedVideoProcessor;
use Perk11\Viktor89\VideoGeneration\AudioImgTxt2VidClient;
use Perk11\Viktor89\VideoGeneration\AudioImgTxt2VidProcessor;
use Perk11\Viktor89\VideoGeneration\Img2VideoClient;
use Perk11\Viktor89\VideoGeneration\Txt2VideoClient;
use Perk11\Viktor89\VideoGeneration\TxtAndVid2VideoClient;
use Perk11\Viktor89\VideoGeneration\VideoImg2VidProcessor;
use Perk11\Viktor89\VideoGeneration\VideoProcessor;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
use Perk11\Viktor89\VideoGeneration\VideoSayProcessor;
use Perk11\Viktor89\VideoGeneration\VideoTxtAndVid2VidProcessor;
use Perk11\Viktor89\VoiceGeneration\DialogResponder;
use Perk11\Viktor89\VoiceGeneration\SingApiClient;
use Perk11\Viktor89\VoiceGeneration\SingProcessor;
use Perk11\Viktor89\VoiceGeneration\SoundAndPromptToTargetAndResidualApiClient;
use Perk11\Viktor89\VoiceGeneration\SoundAndPromptToTargetAndResidualProcessor;
use Perk11\Viktor89\VoiceGeneration\TtsApiClient;
use Perk11\Viktor89\VoiceGeneration\TtsProcessor;
use Perk11\Viktor89\VoiceGeneration\VoiceResponder;
use Perk11\Viktor89\VoiceRecognition\TranscribeProcessor;
use Perk11\Viktor89\VoiceRecognition\VoiceProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ProcessMessageTask implements Task
{
    public function __construct(
        private readonly int $workerId,
        private readonly Message $message,
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): bool
    {
        ini_set('memory_limit', -1);
        InternalMessage::setLogger($this->logger);
        AssistantContext::setLogger($this->logger);
        BotAdminChecker::setLogger($this->logger);

        // amphp disables display_errors and pipes worker stdio, so a fatal would
        // otherwise surface only as a bare "Channel source closed unexpectedly"
        // on the main-process side. The shutdown handler captures uncatchable
        // E_ERRORs (undefined function, parse errors, …); the Throwable catch
        // below covers Engine Errors. emit() echoes + flushes because Monolog
        // (error_log) is not reliably visible in the worker's journal output.
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null || !in_array(
                $error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                true,
            )) {
                return;
            }
            $this->emit(sprintf(
                'Worker %d fatal error: [%d] %s in %s:%d',
                $this->workerId,
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line'],
            ), LogLevel::CRITICAL);
        });

        $progressUpdateCallback = new EngineProgressUpdateCallback($channel, $this->workerId);
        $draftUpdateCallback = new ChannelDraftUpdateCallback($channel, $this->workerId);
        try {
            $this->handle($channel, $progressUpdateCallback, $draftUpdateCallback);
        } catch (\Throwable $e) {
            $this->emit("Worker {$this->workerId} error: {$e->getMessage()}\n" . $e->getTraceAsString(), LogLevel::ERROR);
        } finally {
            if ($progressUpdateCallback->wasCalled) {
                $channel->send(new TaskCompletedMessage($this->workerId));
            }
        }

        return true;
    }

    private function emit(string $message, string $level): void
    {
        $this->logger->log($level, $message);
        echo $message . "\n";
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    /**
     * Dedicated "personalitycard" assistant if the operator configured one;
     * otherwise reuse the existing "vibecheck" assistant so the feature works
     * without any config.json change.
     */
    private function resolvePersonalityCardAssistant(AssistantFactory $assistantFactory): \Perk11\Viktor89\Assistant\AssistantInterface
    {
        try {
            return $assistantFactory->getAssistantInstanceByName('personalitycard');
        } catch (UnknownAssistantException) {
            return $assistantFactory->getAssistantInstanceByName('vibecheck');
        }
    }

    public function handle(
        Channel $channel,
        ProgressUpdateCallback $progressUpdateCallback,
        ChannelDraftUpdateCallback $draftUpdateCallback
    ): void
    {
        $logger = $this->logger;

        $dotenv = Dotenv::createImmutable(__DIR__.'/..');
        $dotenv->load();
        $telegram = new Telegram($_ENV['TELEGRAM_BOT_TOKEN'], $_ENV['TELEGRAM_BOT_USERNAME']);
        $container = ContainerFactory::getContainer($this->telegramBotId, $this->telegramBotUsername, $this->telegramApiKey);
        $database = $container->get(Database::class);
        BotAdminChecker::setDatabase($database);
        $messageRepository = $container->get(\Perk11\Viktor89\Repository\MessageRepository::class);
        $userPreferenceRepository = $container->get(\Perk11\Viktor89\Repository\UserPreferenceRepository::class);
        $personaRepository = $container->get(\Perk11\Viktor89\Repository\PersonaRepository::class);
        $rateLimitRepository = $container->get(\Perk11\Viktor89\Repository\RateLimitRepository::class);
        $historyReader = $container->get(HistoryReader::class);
        $telegramFileDownloader = $container->get(TelegramFileDownloader::class);
        $denoisingStrengthProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $userPreferenceRepository,
            ['/denoisingstrength'],
            'denoising-strength',
            $this->telegramBotUsername,
            0,
            1,
            $logger,
        );
        $stepsProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $userPreferenceRepository,
            ['/steps',],
            'steps',
            $this->telegramBotUsername,
            1,
            75,
            $logger,
        );
        $seedProcessor = new UserPreferenceSetByCommandProcessor(
            $userPreferenceRepository,
            ['/seed',],
            'seed',
            $this->telegramBotUsername,
            $logger,
        );
        $framesProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $userPreferenceRepository,
            ['/frames',],
            'frames',
            $this->telegramBotUsername,
            8,
            480,
            $logger,
        );
        $durationProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $userPreferenceRepository,
            ['/duration',],
            'duration',
            $this->telegramBotUsername,
            8,
            1200,
            $logger,
        );
        $clownProcessor = new UserPreferenceSetByCommandProcessor(
            $userPreferenceRepository,
            ['/clown',],
            'clown',
            $this->telegramBotUsername,
            $logger,
        );
        $openAiCompletionStringParser = $container->get(OpenAiCompletionStringParser::class);
        $configFilePath =__DIR__ . '/../config.json';
        $configString = file_get_contents($configFilePath);
        if ($configString === false) {
            throw new Exception("Failed to read $configFilePath");
        }
        $config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
        $imageModelConfig = $config['imageModels'];
        $editModelConfig = $config['imageEditModels'];
        $imageModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/imagemodel'],
            'imagemodel',
            $this->telegramBotUsername,
            array_keys($imageModelConfig),
            $logger,
        );
        $editModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/editmodel'],
            'editmodel',
            $this->telegramBotUsername,
            array_keys($editModelConfig),
            $logger,
        );
        $videoEditModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/vemodel'],
            'vemodel',
            $this->telegramBotUsername,
            array_keys($config['videoEditModels']),
            $logger,
        );
        $singModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/singmodel'],
            'singmodel',
            $this->telegramBotUsername,
            array_keys($config['singModels']),
            $logger,
        );
        $imageModelPreferenceReader = new DefaultingToFirstInConfigModelPreferenceReader(
            $imageModelProcessor,
            $config['imageModels'],
        );
        $editModelPreferenceReader = new DefaultingToFirstInConfigModelPreferenceReader(
            $editModelProcessor,
            $editModelConfig,
        );
        $singModelPreferenceReader = new DefaultingToFirstInConfigModelPreferenceReader(
            $singModelProcessor,
            $config['singModels'],
        );
        $imageSizeProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository, ['/imagesize'],
            'imagesize',
            $this->telegramBotUsername,
            $config['imageSizes'],
            $logger,
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
            $userPreferenceRepository,
            ['/systemprompt'],
            'system_prompt',
            $this->telegramBotUsername,
            $logger,
        );
        $responseStartProcessor = new UserPreferenceSetByCommandProcessor(
            $userPreferenceRepository,
            ['/responsestart',],
            'response-start',
            $this->telegramBotUsername,
            $logger,
        );
        $editFrequencyProcessor = new NumericPreferenceInRangeByCommandProcessor(
            $userPreferenceRepository,
            ['/editfrequency'],
            'edit-frequency',
            $this->telegramBotUsername,
            3,
            120,
            $logger,
        );
        $personalityProcessor = new UserPreferenceSetByCommandProcessor(
            $userPreferenceRepository,
            ['/personality'],
            'personality',
            $this->telegramBotUsername,
            $logger,
        );
        $photoResponder = $container->get(PhotoResponder::class);
        $generatedImageMarkdownUploaderConfig = $config['generatedImageMarkdownUploader'] ?? null;
        if (!is_array($generatedImageMarkdownUploaderConfig)) {
            throw new Exception('Missing generatedImageMarkdownUploader configuration');
        }
        if (!is_string($generatedImageMarkdownUploaderConfig['scpTarget'] ?? null)
            || !is_string($generatedImageMarkdownUploaderConfig['publicUrlPrefix'] ?? null)
            || !is_string($generatedImageMarkdownUploaderConfig['privateKeyPath'] ?? null)
        ) {
            throw new Exception('generatedImageMarkdownUploader config must contain string scpTarget, publicUrlPrefix and privateKeyPath');
        }
        $generatedImageMarkdownUploader = new ImageUploader(
            $generatedImageMarkdownUploaderConfig['scpTarget'],
            $generatedImageMarkdownUploaderConfig['publicUrlPrefix'],
            $generatedImageMarkdownUploaderConfig['privateKeyPath'],
            is_string($generatedImageMarkdownUploaderConfig['publicKeyPath'] ?? null) ? $generatedImageMarkdownUploaderConfig['publicKeyPath'] : null,
            is_string($generatedImageMarkdownUploaderConfig['keyPassphrase'] ?? null) ? $generatedImageMarkdownUploaderConfig['keyPassphrase'] : null,
            is_int($generatedImageMarkdownUploaderConfig['port'] ?? null) ? $generatedImageMarkdownUploaderConfig['port'] : 22,
        );
        $imageRepository = new ImageRepository($database->sqlite3Database);
        $imgTagExtractor = new ImgTagExtractor($imageRepository, $telegramFileDownloader, $logger);

        $altTextProvider = $container->get(AltTextProvider::class);
        $processingResultExecutor = new ProcessingResultExecutor(
            $messageRepository,
            true,
            new ChannelBeforeMessageSentNotifier($channel, $this->workerId),
            $container->get(\Perk11\Viktor89\Repository\MessageMetadataRepository::class),
            $logger,
        );
        $personaProcessor = new DynamicListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/persona'],
            PersonaHelper::PERSONA_PREFERENCE,
            $this->telegramBotUsername,
            static function (int $chatId) use ($personaRepository): array {
                $options = [['value' => PersonaHelper::DEFAULT_PERSONA_NAME, 'label' => PersonaHelper::DEFAULT_PERSONA_NAME . ' (без персоны)']];
                foreach ($personaRepository->findAllPersonas() as $persona) {
                    $author = $persona->userName !== '' ? ' (от ' . $persona->userName . ')' : '';
                    $options[] = ['value' => $persona->name, 'label' => $persona->name . $author];
                }

                return $options;
            },
            [PersonaHelper::DEFAULT_PERSONA_NAME],
            $logger,
        );
        $personaAwareSystemPromptReader = new PersonaAwareSystemPromptReader($userPreferenceRepository, $personaRepository, $systemPromptProcessor);
        $assistantFactory = new AssistantFactory(
            $config['assistantModels'],
            $personaAwareSystemPromptReader,
            $responseStartProcessor,
            $editFrequencyProcessor,
            $openAiCompletionStringParser,
            $telegramFileDownloader,
            $altTextProvider,
            $processingResultExecutor,
            $container->get(WebSearchToolFactory::class)->buildFromConfig($config),
            new ImageGeneratorTelegramPhotoToolCallExecutor($automatic1111APiClient, $editAutomatic1111APiClient, $photoResponder, $imgTagExtractor, $logger),
            $container->get(ReactToolCallExecutor::class),
            $container->get(GetUrlContentsToolCallExecutor::class),
            new ListSavedImagesToolCallExecutor($imageRepository),
            $container->get(ListChainImagesToolCallExecutor::class),
           $telegram->getBotId(),
           $draftUpdateCallback,
           $personalityProcessor,
           $container->get(\Perk11\Viktor89\Assistant\Compaction\SqliteCompactionSummaryStore::class),
           $logger,
       );
        $altTextProvider->assistantWithVision = $assistantFactory->getAssistantInstanceByName('vision-for-alt-text');
        $assistantModelProcessor = new DynamicListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/assistantmodel'],
            'assistantmodel',
            $this->telegramBotUsername,
            static function (int $chatId) use ($assistantFactory): array {
                $options = [];
                foreach ($assistantFactory->getSupportedModelsForChat($chatId) as $modelName) {
                    $options[] = ['value' => $modelName, 'label' => $modelName];
                }

                return $options;
            },
            [],
            $logger,
        );

        $sayModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/saymodel', '/voice', '/voicemodel'],
            'saymodel',
            $this->telegramBotUsername,
            array_keys($config['voiceModels']),
            $logger,
        );
        $assistedImageGenerator = new AssistedImageGenerator(
            $automatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $imageModelPreferenceReader,
            $imageModelConfig,
            $logger,
        );
        $editAssistedImageGenerator = new AssistedImageGenerator(
            $editAutomatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $editModelPreferenceReader,
            $editModelConfig,
            $logger,
        );
        $userSelectedAssistant = new UserSelectedAssistant($assistantFactory, $assistantModelProcessor);
        $videoModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/videomodel'],
            'videomodel',
            $this->telegramBotUsername,
            array_keys($config['videoModels']),
            $logger,
        );
        $imv2VideModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/img2videomodel'],
            'img2videomodel',
            $this->telegramBotUsername,
            array_keys($config['img2videoModels']),
            $logger,
        );
        $upscaleModelProcessor = new ListBasedPreferenceByCommandProcessor(
            $userPreferenceRepository,
            ['/upscalemodel'],
            'upscalemodel',
            $this->telegramBotUsername,
            array_keys($config['upscaleModels']),
            $logger,
        );
        $styleProcessor = new UserPreferenceSetByCommandProcessor(
            $userPreferenceRepository,
            ['/style',],
            'style',
            $this->telegramBotUsername,
            $logger,
        );
        $txt2VideoClient = new Txt2VideoClient(
            $stepsProcessor,
            $seedProcessor,
            $framesProcessor,
            $videoModelProcessor,
            $config['videoModels']
        );
        $img2VideoClient = new Img2VideoClient(
            $stepsProcessor,
            $seedProcessor,
            $framesProcessor,
            $imv2VideModelProcessor,
            $config['img2videoModels']
        );
        $upscaleClient = new UpscaleApiClient($stepsProcessor, $seedProcessor, $upscaleModelProcessor, $config['upscaleModels']);
        $videoResponder = $container->get(VideoResponder::class);
        $videoImg2VidProcessor = new VideoImg2VidProcessor($telegramFileDownloader, $img2VideoClient, $videoResponder, $logger);
        $videoProcessor = new VideoProcessor($txt2VideoClient, $videoResponder, $videoImg2VidProcessor, $altTextProvider, $logger);
        $assistedVideoProcessor = new AssistedVideoProcessor(
            $automatic1111APiClient,
            $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
            $videoImg2VidProcessor,
            $img2VideoClient,
            $videoResponder,
            $altTextProvider,
            $telegramFileDownloader,
            current($config['videoFirstFrameImageModels']),
            $logger,
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
                $rateLimitRepository, $this->telegramBotId,
                $rateLimits,
                $logger,
            ),
            $container->get(SaveQuizPollProcessor::class),
            $container->get(JoinQuizProcessor::class),
        ];

        $zoomLevelPreference = new FixedValuePreferenceProvider(2);
        $zoomGenerator = new ZoomApiClient($seedProcessor, $zoomLevelPreference, $config['zoomModels'], $logger);
        $zoomImageTransformProcessor = new ImageTransformProcessor($telegramFileDownloader, $zoomGenerator, $photoResponder, $logger);
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
            $logger,
        );
        $ttsApiClient = new TtsApiClient($config['voiceModels']);
        $voiceResponder = $container->get(VoiceResponder::class);
        $imageGenerateProcessor = new CommandBasedResponderTrigger(
            ['/image'],
            new ImageGenerateProcessor(
                $automatic1111APiClient,
                $photoResponder,
                $telegramFileDownloader,
                $imgTagExtractor,
                $imageModelPreferenceReader,
                $altTextProvider,
                $logger,
            ),
            $logger,
        );
        $imagineGenerateProcessor = new CommandBasedResponderTrigger(
            ['/imagine'],
            new ImageGenerateProcessor(
                $assistedImageGenerator,
                $photoResponder,
                $telegramFileDownloader,
                $imgTagExtractor,
                $imageModelPreferenceReader,
                $altTextProvider,
                $logger,
            ),
            $logger,
        );
        $eProcessor = new CommandBasedResponderTrigger(
            ['/e'],
            new ImageGenerateProcessor(
                $editAutomatic1111APiClient,
                $photoResponder,
                $telegramFileDownloader,
                $imgTagExtractor,
                $editModelPreferenceReader,
                $altTextProvider,
                $logger,
            ),
            $logger,
        );
        $editProcessor = new CommandBasedResponderTrigger(
            ['/edit '],
            new ImageGenerateProcessor(
                $editAssistedImageGenerator,
                $photoResponder,
                $telegramFileDownloader,
                $imgTagExtractor,
                $editModelPreferenceReader,
                $altTextProvider,
                $logger,
            ),
            $logger,
        );
        $videoEProcessor = new CommandBasedResponderTrigger(
            ['/ve'],
            new VideoTxtAndVid2VidProcessor(
                $telegramFileDownloader,
                new TxtAndVid2VideoClient(
                    $stepsProcessor,
                    $seedProcessor,
                    $videoEditModelProcessor,
                    $framesProcessor,
                    $config['videoEditModels'],
                ),
                $videoResponder,
                $logger,
            ),
            $logger,
        );
        $audioImgTxt2VidClient = new AudioImgTxt2VidClient(
            $stepsProcessor,
            $seedProcessor,
            $config['voiceOverModels'],
        );
        $voProcessor = new CommandBasedResponderTrigger(
            ['/vo'],
            new AudioImgTxt2VidProcessor(
                $telegramFileDownloader,
                $audioImgTxt2VidClient,
                $imgTagExtractor,
                $videoResponder,
                $logger,
            ),
            $logger,
        );
        $rmBgClient = new RmBgApiClient($config['rmBgModels'], $logger);
        $rmBgProcessor = new ImageTransformProcessor($telegramFileDownloader, $rmBgClient, $photoResponder, $logger);
        $soundAndPromptToTargetAndResidualApiClient = new SoundAndPromptToTargetAndResidualApiClient($config['soundAndPromptToTargetAndResidualModels']);
        $soundAndPromptToTargetAndResidualProcessor = new SoundAndPromptToTargetAndResidualProcessor($voiceResponder, $telegramFileDownloader, $soundAndPromptToTargetAndResidualApiClient, $logger);
        $talkersCommandProcessor = $container->get(TalkersCommandProcessor::class);
        $vibeCheckProcessor = new VibeCheckProcessor(
            $messageRepository,
            $assistantFactory->getAssistantInstanceByName('vibecheck'),
            $logger,
        );
        $roastProcessor = new RoastProcessor(
            $messageRepository,
            $assistantFactory->getAssistantInstanceByName('roast'),
            $logger,
        );
        $complimentProcessor = new ComplimentProcessor(
            $messageRepository,
            $assistantFactory->getAssistantInstanceByName('compliment'),
            $logger,
        );
        $messageChainProcessors = [
            $container->get(VoiceProcessor::class),
            $clownProcessor,
            new ReactProcessor($clownProcessor, '🤡'),
            new BlockedChatProcessor([
//                                         '-1002398016894',
                                         '-1002076350723',
                                         '6184626947',
                                     ]),
            $imageModelProcessor,
            $imageSizeProcessor,
            new CommandBasedResponderTrigger(
                ['/images', '/saved'],
                new ImageCatalogPdfProcessor($imageRepository, $logger),
                $logger,
            ),
            $editModelProcessor,
            $singModelProcessor,
            $videoModelProcessor,
            $videoEditModelProcessor,
            $imv2VideModelProcessor,
            $upscaleModelProcessor,
            $styleProcessor,
            $assistantModelProcessor,
            $sayModelProcessor,
            $denoisingStrengthProcessor,
            $stepsProcessor,
            $seedProcessor,
            $framesProcessor,
            $durationProcessor,
            $systemPromptProcessor,
            new CommandBasedResponderTrigger(
                ['/addpersona'],
                $container->get(AddPersonaProcessor::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/delpersona'],
                $container->get(DeletePersonaProcessor::class),
                $logger,
            ),
            $responseStartProcessor,
            $editFrequencyProcessor,
            new CommandBasedResponderTrigger(
                ['/card'],
                new PersonalityCardProcessor(
                    $messageRepository,
                    $this->resolvePersonalityCardAssistant($assistantFactory),
                    $automatic1111APiClient,
                    $photoResponder,
                    new PersonalityCardRenderer(),
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/personality'],
                $personalityProcessor,
                $logger,
            ),
            $personaProcessor,
            new CommandBasedResponderTrigger(
                ['/quiz'],
                $container->get(RandomQuizResponder::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/image_all_models', '/image_allmodels'],
                new MultipleModelsImageGenerateProcessor(
                    $processingResultExecutor,
                    $photoResponder,
                    $telegramFileDownloader,
                    $imgTagExtractor,
                    $denoisingStrengthProcessor,
                    $seedProcessor,
                    $imageSizeProcessor,
                    $altTextProvider,
                    $config['imageModels'],
                    null,
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/imagine_all_models', '/imagine_allmodels'],
                new MultipleModelsImageGenerateProcessor(
                    $processingResultExecutor,
                    $photoResponder,
                    $telegramFileDownloader,
                    $imgTagExtractor,
                    $denoisingStrengthProcessor,
                    $seedProcessor,
                    $imageSizeProcessor,
                    $altTextProvider,
                    $config['imageModels'],
                    $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
                    $logger,
                ),
                $logger,
            ),
            $imageGenerateProcessor,
            $imagineGenerateProcessor,
            $editProcessor,
            $eProcessor,
            new CommandBasedResponderTrigger(
                ['/upscale'],
                new ImageTransformProcessor($telegramFileDownloader, $upscaleClient, $photoResponder, $logger),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/downscale'],
                $container->get(DownscaleProcessor::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/zoom'],
                $zoomCommandProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/remix'],
                new RemixProcessor(
                    $telegramFileDownloader,
                    $photoResponder,
                    new ImageRemixer(
                        $assistantFactory->getAssistantInstanceByName('vision-for-remix'),
                        $automatic1111APiClient,
                        $logger,
                    ),
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/restyle'],
                new ImageTransformProcessor($telegramFileDownloader, $restyleGenerator, $photoResponder, $logger),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/video'],
                $videoProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/vid'],
                $assistedVideoProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/start', '/help'],
                $container->get(PrintHelpProcessor::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/preferences', '/settings'],
                $container->get(PrintUserPreferencesResponder::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/say'],
                new TtsProcessor(
                    $ttsApiClient,
                    $voiceResponder,
                    $altTextProvider,
                    $sayModelProcessor,
                    $config['voiceModels'],
                    new Language(),
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/vsay'],
                new VideoSayProcessor(
                    $telegramFileDownloader,
                    $audioImgTxt2VidClient,
                    $imgTagExtractor,
                    $videoResponder,
                    $altTextProvider,
                    $assistantFactory->getAssistantInstanceByName('gemma2-for-imagine'),
                    $ttsApiClient,
                    $sayModelProcessor,
                    $config['voiceModels'],
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/sing'],
                new SingProcessor(
                    new SingApiClient($config['singModels']),
                    $voiceResponder,
                    $durationProcessor,
                    $seedProcessor,
                    $singModelPreferenceReader,
                    $config['singModels'],
                    isset($config['audioSuperResolutionUrl'])
                        ? new AudioSuperResolutionApiClient($config['audioSuperResolutionUrl'])
                        : null,
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/transcribe'],
                $container->get(TranscribeProcessor::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/talkers'],
                 $talkersCommandProcessor,
                 $logger,
             ),
            new CommandBasedResponderTrigger(
                ['/vibecheck'],
                $vibeCheckProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/roast'],
                $roastProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/compliment'],
                $complimentProcessor,
                $logger,
            ),
            $videoEProcessor,
            $voProcessor,
            new CommandBasedResponderTrigger(
                ['/saveas'],
                new SaveAsProcessor($telegramFileDownloader, $imageRepository),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/ratelimits'],
                new RateLimitsCommandProcessor($rateLimitRepository, $rateLimitObjects),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/podcast'],
                new DialogResponder(
                    $assistantFactory->getAssistantInstanceByName('podcast'),
                    $ttsApiClient,
                    $voiceResponder,
                    $config['podcastVoices'],
                    $logger,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/status'],
                new StatusProcessor($channel),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/metadata'],
                new MetadataCommandProcessor(
                    $container->get(\Perk11\Viktor89\Repository\MessageMetadataRepository::class),
                    $personaRepository,
                    $this->telegramBotUsername,
                ),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/file'],
                $container->get(SendAsDocumentProcessor::class),
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/rmbg'],
                $rmBgProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/aextract'],
                $soundAndPromptToTargetAndResidualProcessor,
                $logger,
            ),
            new CommandBasedResponderTrigger(
                ['/assistant'],
                $userSelectedAssistant,
                $logger,
                $telegram->getBotId(),
            ),
            $container->get(WhoAreYouProcessor::class),
            $container->get(HelloProcessor::class),
        ];
        $messageChainProcessorRunner = new MessageChainProcessorRunner($processingResultExecutor, $messageChainProcessors, $logger);
        $engine = new Engine($messageRepository,
                             $historyReader,
                             $preResponseProcessors,
                             $messageChainProcessorRunner,
                             $this->telegramBotUsername,
                             $this->telegramBotId,
                             $userSelectedAssistant,
                             $progressUpdateCallback,
                             $processingResultExecutor,
                             $logger,
        );
        $engine->handleMessage($this->message);
    }
}
