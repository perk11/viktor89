<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\VideoGeneration\Txt2VideoClient;
use Perk11\Viktor89\VideoGeneration\VideoApiResponse;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;
use Perk11\Viktor89\VideoGeneration\VideoImg2VidProcessor;
use Perk11\Viktor89\VideoGeneration\VideoProcessor;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessor;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessorFactory;
use Perk11\Viktor89\VideoGeneration\VideoResponder;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(VideoProcessor::class)]
class VideoProcessorTest extends TestCase
{
    use TelegramRecordingTrait;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }
    public function testReplyPhotoWithNoPreprocessorGeneratesImg2VidWithRepliedPhotoAsFirstFrame(): void
    {
        [$processor, $spies] = $this->buildProcessorWithSpies(
            img2VideoModelPreference: $this->preferenceReturning('plain-i2v'),
            img2videoModelsConfig: ['plain-i2v' => ['url' => 'http://x']],
            telegramFileDownloader: $this->downloaderReturning('photo-bytes'),
        );

        $result = $processor->processMessageChain($this->chainWithReplyPhoto('a dog on a beach'), $this->progressCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertSame('photo-bytes', $spies->img2vidImage);
        $this->assertSame('a dog on a beach', $spies->img2vidPrompt);
        $this->assertNull($spies->txt2vidPrompt);
    }

    public function testReplyPhotoRunsPreprocessorWhenSelectedImg2VidModelDeclaresIt(): void
    {
        $preprocessor = $this->preprocessorThatPrefixes('[MINIMAX] ');
        [$processor, $spies] = $this->buildProcessorWithSpies(
            img2VideoModelPreference: $this->preferenceReturning('minimax-h3-preprocessed'),
            img2videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            preprocessorFactory: $this->factoryReturning('minimax-h3', $preprocessor),
            telegramFileDownloader: $this->downloaderReturning('photo-bytes'),
        );

        $processor->processMessageChain($this->chainWithReplyPhoto('a dog on a beach'), $this->progressCallback());

        $this->assertSame('photo-bytes', $spies->img2vidImage);
        $this->assertSame('[MINIMAX] a dog on a beach', $spies->img2vidPrompt);
    }

    public function testFramesPreferenceIsConvertedToDurationSeconds(): void
    {
        $capturedDuration = null;
        $preprocessor = $this->createMock(VideoPromptPreprocessor::class);
        $preprocessor->method('preprocess')->willReturnCallback(
            function (VideoGenerationPrompt $input) use (&$capturedDuration): string {
                $capturedDuration = $input->durationSeconds;

                return $input->userPrompt;
            },
        );

        [$processor] = $this->buildProcessorWithSpies(
            img2VideoModelPreference: $this->preferenceReturning('minimax-h3-preprocessed'),
            img2videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            preprocessorFactory: $this->factoryReturning('minimax-h3', $preprocessor),
            framesPreference: $this->preferenceReturning('480'),
            telegramFileDownloader: $this->downloaderReturning('photo-bytes'),
        );

        $processor->processMessageChain($this->chainWithReplyPhoto('a dog'), $this->progressCallback());

        // 480 frames / 24 fps = 20 seconds (not the default 15).
        $this->assertSame(20, $capturedDuration);
    }

    public function testPreprocessorFailureFallsBackToRawPrompt(): void
    {
        $preprocessor = $this->createMock(VideoPromptPreprocessor::class);
        $preprocessor->method('preprocess')->willThrowException(new \RuntimeException('llm down'));
        [$processor, $spies] = $this->buildProcessorWithSpies(
            img2VideoModelPreference: $this->preferenceReturning('minimax-h3-preprocessed'),
            img2videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            preprocessorFactory: $this->factoryReturning('minimax-h3', $preprocessor),
            telegramFileDownloader: $this->downloaderReturning('photo-bytes'),
        );

        $processor->processMessageChain($this->chainWithReplyPhoto('a dog'), $this->progressCallback());

        $this->assertSame('a dog', $spies->img2vidPrompt);
    }

    public function testNoPhotoGeneratesTxt2Vid(): void
    {
        [$processor, $spies] = $this->buildProcessorWithSpies();

        $processor->processMessageChain($this->singleMessageChain('a cat playing piano'), $this->progressCallback());

        $this->assertSame('a cat playing piano', $spies->txt2vidPrompt);
        $this->assertNull($spies->img2vidPrompt);
    }

    public function testPreprocessedTxt2VidShowsOriginalPromptAsCaptionAndRecordsRewrite(): void
    {
        // The preprocessor rewrites the idea, but the caption shown under the
        // video must be the original user prompt; the rewrite is recorded.
        $preprocessor = $this->preprocessorThatPrefixes('[MINIMAX] ');
        $captured = (object) ['caption' => null, 'processed' => null];
        $responder = $this->createMock(VideoResponder::class);
        $responder->method('sendVideo')->willReturnCallback(
            function ($message, $video, $caption, $processedPrompt = null) use ($captured): void {
                $captured->caption = $caption;
                $captured->processed = $processedPrompt;
            },
        );

        [$processor] = $this->buildProcessorWithSpies(
            videoModelPreference: $this->preferenceReturning('minimax-h3-preprocessed'),
            videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            preprocessorFactory: $this->factoryReturning('minimax-h3', $preprocessor),
            videoResponder: $responder,
        );

        $processor->processMessageChain($this->singleMessageChain('a dog on a beach'), $this->progressCallback());

        $this->assertSame('a dog on a beach', $captured->caption);
        $this->assertSame('[MINIMAX] a dog on a beach', $captured->processed);
    }

    public function testTxt2VidWithoutPreprocessorRecordsNullProcessedPrompt(): void
    {
        // No preprocessor: caption is the original prompt and nothing is recorded
        // as the processed prompt (no rewrite happened).
        $captured = (object) ['caption' => null, 'processed' => 'sentinel'];
        $responder = $this->createMock(VideoResponder::class);
        $responder->method('sendVideo')->willReturnCallback(
            function ($message, $video, $caption, $processedPrompt = null) use ($captured): void {
                $captured->caption = $caption;
                $captured->processed = $processedPrompt;
            },
        );

        [$processor] = $this->buildProcessorWithSpies(videoResponder: $responder);

        $processor->processMessageChain($this->singleMessageChain('a cat playing piano'), $this->progressCallback());

        $this->assertSame('a cat playing piano', $captured->caption);
        $this->assertNull($captured->processed);
    }

    public function testTxt2VidCaptionIncludesModelAndForwardsItAsMetadata(): void
    {
        // When the client reports the model it used, /video prepends it to the
        // caption (so the model is visible like image infotexts) and forwards it
        // to VideoResponder for metadata recording.
        $captured = (object) ['caption' => null, 'model' => null];
        $responder = $this->createMock(VideoResponder::class);
        $responder->method('sendVideo')->willReturnCallback(
            function ($message, $video, $caption, $processedPrompt = null, $model = null) use ($captured): void {
                $captured->caption = $caption;
                $captured->model = $model;
            },
        );

        [$processor] = $this->buildProcessorWithSpies(
            videoResponder: $responder,
            txt2VidResponseModelName: 'cogvideox',
        );

        $processor->processMessageChain($this->singleMessageChain('a cat playing piano'), $this->progressCallback());

        $this->assertSame("cogvideox\na cat playing piano", $captured->caption);
        $this->assertSame('cogvideox', $captured->model);
    }

    public function testPreprocessedImg2VidPassesOriginalCaptionAndRewriteThrough(): void
    {
        // The img2vid path must forward the original-prompt caption and the
        // rewritten prompt just like the txt2vid path does.
        $preprocessor = $this->preprocessorThatPrefixes('[MINIMAX] ');
        $captured = (object) ['caption' => null, 'processed' => null];
        $img2Processor = $this->createMock(VideoImg2VidProcessor::class);
        $img2Processor->method('respondWithImg2VidResult')->willReturnCallback(
            function ($command, $image, $prompt, $cb, $model = null, $caption = null, $processed = null) use ($captured): void {
                $captured->caption = $caption;
                $captured->processed = $processed;
            },
        );

        [$processor] = $this->buildProcessorWithSpies(
            img2VideoModelPreference: $this->preferenceReturning('minimax-h3-preprocessed'),
            img2videoModelsConfig: ['minimax-h3-preprocessed' => ['preprocessor' => 'minimax-h3']],
            preprocessorFactory: $this->factoryReturning('minimax-h3', $preprocessor),
            telegramFileDownloader: $this->downloaderReturning('photo-bytes'),
            videoImg2VidProcessor: $img2Processor,
        );

        $processor->processMessageChain($this->chainWithReplyPhoto('a dog on a beach'), $this->progressCallback());

        $this->assertSame('a dog on a beach', $captured->caption);
        $this->assertSame('[MINIMAX] a dog on a beach', $captured->processed);
    }

    public function testFirstFrameTagUsesSavedImageAsFirstFrame(): void
    {
        // With no replied photo, the <fframe> image becomes the first frame.
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $this->extractorWithSavedImage('ff', 'ff-bytes'),
        );

        $processor->processMessageChain(
            $this->singleMessageChain('<fframe>ff</fframe> animate'),
            $this->progressCallback(),
        );

        $this->assertSame('ff-bytes', $spies->img2vidImage);
        $this->assertSame('animate', $spies->img2vidPrompt);
        $this->assertNull($spies->txt2vidPrompt);
    }

    public function testFirstFrameTagAlongsideReplyPhotoIsRejectedOnNonReferenceModel(): void
    {
        // A replied photo is always a reference, so combining it with an
        // explicit first frame leaves a reference the model cannot consume.
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $this->extractorWithSavedImage('ff', 'ff-bytes'),
            telegramFileDownloader: $this->downloaderReturning('reply-bytes'),
        );

        $result = $processor->processMessageChain(
            $this->chainWithReplyPhotoAndCommand('<fframe>ff</fframe> animate'),
            $this->progressCallback(),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertNull($spies->img2vidPrompt);
    }

    public function testSingleReferenceTagFallsBackToFirstFrameOnNonReferenceModel(): void
    {
        // A lone <img> reference on a model that cannot consume references is
        // promoted to the first frame, just like a bare replied photo.
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $this->extractorWithSavedImage('ref', 'ref-bytes'),
        );

        $processor->processMessageChain(
            $this->singleMessageChain('<img>ref</img> transform'),
            $this->progressCallback(),
        );

        $this->assertSame('ref-bytes', $spies->img2vidImage);
        $this->assertSame('transform', $spies->img2vidPrompt);
    }

    public function testMultipleReferencesOnNonReferenceModelAreRejected(): void
    {
        $extractor = $this->createMock(ImgTagExtractor::class);
        $extractor->method('extractImageAndFrameTags')->willReturn(new VideoGenerationPrompt(
            'mix',
            referenceImages: ['ref-a', 'ref-b'],
        ));
        [$processor, $spies] = $this->buildProcessorWithSpies(imgTagExtractor: $extractor);

        $result = $processor->processMessageChain($this->singleMessageChain('<img>a</img> <img>b</img> mix'), $this->progressCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertNotEmpty($result->response->messageText);
        $this->assertNull($spies->img2vidPrompt);
        $this->assertNull($spies->txt2vidPrompt);
    }

    public function testReferenceAlongsideExplicitFirstFrameOnNonReferenceModelIsRejected(): void
    {
        $extractor = $this->createMock(ImgTagExtractor::class);
        $extractor->method('extractImageAndFrameTags')->willReturn(new VideoGenerationPrompt(
            'mix',
            firstFrame: 'ff-bytes',
            referenceImages: ['ref-bytes'],
        ));
        [$processor, $spies] = $this->buildProcessorWithSpies(imgTagExtractor: $extractor);

        $result = $processor->processMessageChain($this->singleMessageChain('<fframe>ff</fframe> <img>ref</img> mix'), $this->progressCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertNull($spies->img2vidPrompt);
    }

    public function testUnknownSavedImageInTagRespondsWithNotFoundMessage(): void
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn(null);
        $extractor = new ImgTagExtractor($repo, logger: new NullLogger());
        [$processor, $spies] = $this->buildProcessorWithSpies(imgTagExtractor: $extractor);

        $result = $processor->processMessageChain($this->singleMessageChain('<fframe>missing</fframe> go'), $this->progressCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsString('missing', $result->response->messageText);
        $this->assertNull($spies->img2vidPrompt);
        $this->assertNull($spies->txt2vidPrompt);
    }

    public function testReferenceOnModelThatSupportsReferencesIsPassedThrough(): void
    {
        // Forward-looking: when a model declares supportsReferences, a reference
        // image is not collapsed to a first frame and the request is accepted.
        $extractor = $this->createMock(ImgTagExtractor::class);
        $extractor->method('extractImageAndFrameTags')->willReturn(new VideoGenerationPrompt(
            'transform',
            referenceImages: ['ref-bytes'],
        ));
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $extractor,
            img2videoModelsConfig: ['ref-capable' => ['supportsReferences' => true]],
            img2VideoModelPreference: $this->preferenceReturning('ref-capable'),
        );

        $result = $processor->processMessageChain($this->singleMessageChain('<img>ref</img> transform'), $this->progressCallback());

        // No first frame -> text-to-video path; the reference is retained on the
        // prompt for a future reference-aware client (today's clients ignore it).
        $this->assertTrue($result->abortProcessing);
        $this->assertSame('transform', $spies->txt2vidPrompt);
        $this->assertNull($spies->img2vidPrompt);
    }

    public function testLastFrameTagFallsBackToFirstLastFrameCapableModel(): void
    {
        // The selected model does not support last frames, so /video falls back
        // to the first configured model that does (mirroring the image-edit
        // fallback) and routes the request through img2vid with it.
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $this->extractorWithSavedImage('lf', 'lf-bytes'),
            img2VideoModelPreference: $this->preferenceReturning('plain-i2v'),
            img2videoModelsConfig: [
                'plain-i2v' => ['url' => 'http://x'],
                'lf-model' => ['supportsLastFrame' => true],
            ],
        );

        $processor->processMessageChain(
            $this->singleMessageChain('<lframe>lf</lframe> converge'),
            $this->progressCallback(),
        );

        $this->assertSame('lf-bytes', $spies->img2vidImage);
        $this->assertSame('lf-model', $spies->img2vidModel);
        $this->assertSame('converge', $spies->img2vidPrompt);
        $this->assertNull($spies->txt2vidPrompt);
    }

    public function testLastFrameTagUsesSelectedModelWhenItSupportsLastFrame(): void
    {
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $this->extractorWithSavedImage('lf', 'lf-bytes'),
            img2VideoModelPreference: $this->preferenceReturning('lf-capable'),
            img2videoModelsConfig: [
                'lf-capable' => ['supportsLastFrame' => true],
            ],
        );

        $processor->processMessageChain(
            $this->singleMessageChain('<lframe>lf</lframe> converge'),
            $this->progressCallback(),
        );

        $this->assertSame('lf-capable', $spies->img2vidModel);
    }

    public function testLastFrameTagErrorsWhenNoModelSupportsIt(): void
    {
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $this->extractorWithSavedImage('lf', 'lf-bytes'),
            img2videoModelsConfig: ['plain-i2v' => ['url' => 'http://x']],
        );

        $result = $processor->processMessageChain(
            $this->singleMessageChain('<lframe>lf</lframe> converge'),
            $this->progressCallback(),
        );

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertNotEmpty($result->response->messageText);
        $this->assertNull($spies->img2vidPrompt);
        $this->assertNull($spies->txt2vidPrompt);
    }

    public function testFirstAndLastFrameUsesLastFrameCapableModel(): void
    {
        // Both anchors present: still a last-frame task, so the last-frame model
        // fallback applies and the first frame drives the img2vid call.
        $extractor = $this->createMock(ImgTagExtractor::class);
        $extractor->method('extractImageAndFrameTags')->willReturn(new VideoGenerationPrompt(
            'morph',
            firstFrame: 'ff-bytes',
            lastFrame: 'lf-bytes',
        ));
        [$processor, $spies] = $this->buildProcessorWithSpies(
            imgTagExtractor: $extractor,
            img2VideoModelPreference: $this->preferenceReturning('plain-i2v'),
            img2videoModelsConfig: [
                'plain-i2v' => ['url' => 'http://x'],
                'lf-model' => ['supportsLastFrame' => true],
            ],
        );

        $processor->processMessageChain(
            $this->singleMessageChain('<fframe>ff</fframe> <lframe>lf</lframe> morph'),
            $this->progressCallback(),
        );

        $this->assertSame('ff-bytes', $spies->img2vidImage);
        $this->assertSame('lf-model', $spies->img2vidModel);
    }

    /**
     * @return array{0: VideoProcessor, 1: \stdClass}
     */
    private function buildProcessorWithSpies(
        ?UserPreferenceReaderInterface $img2VideoModelPreference = null,
        array $img2videoModelsConfig = [],
        ?UserPreferenceReaderInterface $videoModelPreference = null,
        array $videoModelsConfig = [],
        ?TelegramFileDownloader $telegramFileDownloader = null,
        ?VideoPromptPreprocessorFactory $preprocessorFactory = null,
        ?ImgTagExtractor $imgTagExtractor = null,
        ?UserPreferenceReaderInterface $framesPreference = null,
        ?VideoResponder $videoResponder = null,
        ?VideoImg2VidProcessor $videoImg2VidProcessor = null,
        ?string $txt2VidResponseModelName = null,
    ): array {
        $spies = (object) [
            'img2vidImage' => null,
            'img2vidPrompt' => null,
            'img2vidModel' => null,
            'txt2vidPrompt' => null,
        ];

        $videoImg2VidProcessor ??= (function () use ($spies): VideoImg2VidProcessor {
            $mock = $this->createMock(VideoImg2VidProcessor::class);
            $mock->method('respondWithImg2VidResult')
                ->willReturnCallback(function ($command, string $image, string $prompt, $cb, ?string $modelName = null) use ($spies): void {
                    $spies->img2vidImage = $image;
                    $spies->img2vidPrompt = $prompt;
                    $spies->img2vidModel = $modelName;
                });

            return $mock;
        })();

        $txt2VideoClient = $this->createMock(Txt2VideoClient::class);
        $txt2VideoClient->method('generateByPromptTxt2Vid')
            ->willReturnCallback(function (string $prompt) use ($spies, $txt2VidResponseModelName): VideoApiResponse {
                $spies->txt2vidPrompt = $prompt;

                $response = new VideoApiResponse([base64_encode('mp4-bytes')], ['infotexts' => ['info']]);
                $response->modelName = $txt2VidResponseModelName;

                return $response;
            });

        $videoResponder ??= $this->createMock(VideoResponder::class);

        $processor = new VideoProcessor(
            $txt2VideoClient,
            $videoResponder,
            $videoImg2VidProcessor,
            $this->createMock(AltTextProvider::class),
            $telegramFileDownloader ?? $this->createMock(TelegramFileDownloader::class),
            $preprocessorFactory ?? $this->createMock(VideoPromptPreprocessorFactory::class),
            $videoModelPreference ?? $this->preferenceReturning(null),
            $videoModelsConfig,
            $img2VideoModelPreference ?? $this->preferenceReturning(null),
            $img2videoModelsConfig,
            $framesPreference ?? $this->preferenceReturning(null),
            $imgTagExtractor ?? $this->realExtractorWithStubRepo(),
            new NullLogger(),
        );

        return [$processor, $spies];
    }

    private function realExtractorWithStubRepo(): ImgTagExtractor
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturn('saved-bytes');

        return new ImgTagExtractor($repo, logger: new NullLogger());
    }

    private function extractorWithSavedImage(string $name, string $bytes): ImgTagExtractor
    {
        $repo = $this->createStub(ImageRepository::class);
        $repo->method('retrieve')->willReturnMap([[$name, $bytes]]);

        return new ImgTagExtractor($repo, logger: new NullLogger());
    }

    private function preprocessorThatPrefixes(string $prefix): VideoPromptPreprocessor
    {
        $preprocessor = $this->createMock(VideoPromptPreprocessor::class);
        $preprocessor->method('preprocess')
            ->willReturnCallback(function (VideoGenerationPrompt $input) use ($prefix): string {
                return $prefix . $input->userPrompt;
            });

        return $preprocessor;
    }

    private function factoryReturning(string $key, VideoPromptPreprocessor $preprocessor): VideoPromptPreprocessorFactory
    {
        $factory = $this->createMock(VideoPromptPreprocessorFactory::class);
        $factory->method('createForModelPreference')->willReturnCallback(
            static function ($preference, array $config, int $userId) use ($key, $preprocessor): ?VideoPromptPreprocessor {
                $modelName = $preference->getCurrentPreferenceValue($userId);
                $entry = ($modelName !== null && isset($config[$modelName])) ? $config[$modelName] : (current($config) ?: []);

                return ($entry['preprocessor'] ?? null) === $key ? $preprocessor : null;
            },
        );

        return $factory;
    }

    private function preferenceReturning(?string $value): UserPreferenceReaderInterface
    {
        $preference = $this->createMock(UserPreferenceReaderInterface::class);
        $preference->method('getCurrentPreferenceValue')->willReturn($value);

        return $preference;
    }

    private function downloaderReturning(string $bytes): TelegramFileDownloader
    {
        $downloader = $this->createMock(TelegramFileDownloader::class);
        $downloader->method('downloadPhotoFromInternalMessage')->willReturn($bytes);

        return $downloader;
    }

    private function progressCallback(): ProgressUpdateCallback
    {
        return $this->createMock(ProgressUpdateCallback::class);
    }

    private function singleMessageChain(string $text): MessageChain
    {
        return new MessageChain([$this->message($text)]);
    }

    private function chainWithReplyPhoto(string $commandText): MessageChain
    {
        return $this->chainWithReplyPhotoAndCommand($commandText);
    }

    private function chainWithReplyPhotoAndCommand(string $commandText): MessageChain
    {
        $previous = new InternalMessage();
        $previous->id = 9;
        $previous->chatId = -100;
        $previous->userId = 5;
        $previous->photoFileId = 'photo-file-id';

        return new MessageChain([$previous, $this->message($commandText)]);
    }

    private function message(string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100;
        $message->userId = 5;
        $message->messageText = $text;

        return $message;
    }
}
