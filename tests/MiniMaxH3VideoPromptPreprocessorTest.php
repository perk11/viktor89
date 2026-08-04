<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\MiniMaxH3\MiniMaxH3VideoPromptPreprocessor;
use Perk11\Viktor89\VideoGeneration\VideoTaskMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MiniMaxH3VideoPromptPreprocessor::class)]
class MiniMaxH3VideoPromptPreprocessorTest extends TestCase
{
    private const string FIRST = 'first-png-bytes';
    private const string LAST = 'last-png-bytes';
    private const string REF1 = 'ref-png-bytes-1';
    private const string REF2 = 'ref-png-bytes-2';
    private const string REF3 = 'ref-png-bytes-3';
    private const string AUDIO = 'audio-bytes';
    private const string REF_AUDIO = 'ref-audio-bytes';

    public function testTextOnlyResolvesToTextToVideoAndNeverReferencesPicturesOrAudio(): void
    {
        $preprocessor = $this->preprocessorReturning('integrated_multimodal_description: [Shot 1] ...', $captured);

        $result = $preprocessor->preprocess(new VideoGenerationPrompt('a cat playing piano'), $this->progressCallback());

        $this->assertSame('integrated_multimodal_description: [Shot 1] ...', $result);
        $this->assertSame(VideoTaskMode::TextToVideo, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('OUTPUT FORMAT (text-to-video)', $system);
        $this->assertStringContainsString('at most 15 seconds', $system);
        $this->assertStringNotContainsString('<Picture 1>', $system);
        $this->assertStringNotContainsString('<Audio 1>', $system);
        // Code knows there is no image -> the style rule is a fact, not a branch.
        $this->assertStringNotContainsString('When a reference image', $system);
        $this->assertStringContainsString("Choose the overall style from the user's text", $system);
        $this->assertNull($captured->messages[0]->photo);
    }

    public function testFirstFrameResolvesToFirstFrameAndAttachesIt(): void
    {
        $preprocessor = $this->preprocessorReturning('i2va prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('continue the scene', firstFrame: self::FIRST), $this->progressCallback());

        $this->assertSame(VideoTaskMode::FirstFrame, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('OUTPUT FORMAT (first-frame video)', $system);
        $this->assertStringContainsString('<Picture 1> (from [Shot 1]) is fully referenced', $system);
        $this->assertStringNotContainsString('<Audio 1>', $system);
        $this->assertStringContainsString('Derive the overall style from the supplied reference image', $system);
        $this->assertSame(self::FIRST, $captured->messages[0]->photo);
    }

    public function testLastFrameResolvesToLastFrameAndConvergesToEnd(): void
    {
        $preprocessor = $this->preprocessorReturning('l2va prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('how the glass broke', lastFrame: self::LAST), $this->progressCallback());

        $this->assertSame(VideoTaskMode::LastFrame, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('OUTPUT FORMAT (last-frame video)', $system);
        $this->assertStringContainsString('<Picture 1> (from [Shot N]) aligns with the S.SS-second mark', $system);
        $this->assertStringContainsString('FINAL frame', $system);
        $this->assertStringNotContainsString('<Audio 1>', $system);
        $this->assertSame(self::LAST, $captured->messages[0]->photo);
    }

    public function testFirstAndLastFrameResolvesToFirstLastFrame(): void
    {
        $preprocessor = $this->preprocessorReturning('fl2va prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('bridge the frames', firstFrame: self::FIRST, lastFrame: self::LAST), $this->progressCallback());

        $this->assertSame(VideoTaskMode::FirstLastFrame, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('OUTPUT FORMAT (first-and-last-frame video)', $system);
        $this->assertStringContainsString('Picture 1 (from Shot 1) aligns with the 0.00-second mark', $system);
        $this->assertStringContainsString('Picture 2 (from Shot N) aligns with the S.SS-second mark', $system);
        $this->assertStringContainsString('SINGLE SHOT', $system);
        $this->assertStringNotContainsString('<Audio 1>', $system);
        $this->assertSame(self::FIRST, $captured->messages[0]->photo);
    }

    public function testReferenceImagesResolveToFullReference(): void
    {
        $preprocessor = $this->preprocessorReturning('full-reference prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('many references', referenceImages: [self::REF1, self::REF2, self::REF3]), $this->progressCallback());

        $this->assertSame(VideoTaskMode::FullReference, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('full-reference', $system);
        $this->assertStringContainsString('<Picture 1>', $system);
        $this->assertStringContainsString('<Picture 2>', $system);
        $this->assertStringContainsString('<Picture 3>', $system);
        $this->assertStringContainsString('[keyframe completion]', $system);
        $this->assertStringNotContainsString('audio reuse', $system);
        $this->assertSame(self::REF1, $captured->messages[0]->photo);
    }

    public function testAudioTrackResolvesToFullReferenceWithAudioReuse(): void
    {
        $preprocessor = $this->preprocessorReturning('full-reference prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('dancing to the beat', audioTrack: self::AUDIO), $this->progressCallback());

        $this->assertSame(VideoTaskMode::FullReference, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('full-reference', $system);
        $this->assertStringContainsString('<Audio 1>', $system);
        $this->assertStringContainsString('fully_copy', $system);
        $this->assertStringContainsString('[audio reuse]', $system);
        $this->assertStringNotContainsString('<Picture 1>', $system);
        $this->assertNull($captured->messages[0]->photo);
    }

    public function testReferenceAudiosResolveToFullReferenceWithAudioReference(): void
    {
        $preprocessor = $this->preprocessorReturning('full-reference prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('match this voice', referenceAudios: [self::REF_AUDIO]), $this->progressCallback());

        $this->assertSame(VideoTaskMode::FullReference, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('full-reference', $system);
        $this->assertStringContainsString('<Audio 1>', $system);
        $this->assertStringContainsString('<Audio 1>: reference -', $system);
        $this->assertStringContainsString('[audio reference]', $system);
        $this->assertNull($captured->messages[0]->photo);
    }

    public function testFirstFrameAndAudioTrackCombineKeyframeAndAudioReuse(): void
    {
        $preprocessor = $this->preprocessorReturning('full-reference prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('sing along', firstFrame: self::FIRST, audioTrack: self::AUDIO), $this->progressCallback());

        $this->assertSame(VideoTaskMode::FullReference, $this->capturedMode($captured));
        $system = $captured->systemPrompt;
        $this->assertStringContainsString('<Picture 1>', $system);
        $this->assertStringContainsString('<Audio 1>', $system);
        $this->assertStringContainsString('[keyframe completion + audio reuse]', $system);
        $this->assertSame(self::FIRST, $captured->messages[0]->photo);
    }

    public function testRespectsCustomDuration(): void
    {
        $preprocessor = $this->preprocessorReturning('prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('idea', durationSeconds: 8), $this->progressCallback());

        $this->assertStringContainsString('at most 8 seconds', $captured->systemPrompt);
    }

    public function testThrowsOnEmptyRewrite(): void
    {
        $preprocessor = $this->preprocessorReturning("   \n  ", $captured);

        $this->expectException(\RuntimeException::class);
        $preprocessor->preprocess(new VideoGenerationPrompt('idea'), $this->progressCallback());
    }

    public function testUserMessageContainsTheIdeaAndOutputOnlyInstruction(): void
    {
        $preprocessor = $this->preprocessorReturning('prompt', $captured);

        $preprocessor->preprocess(new VideoGenerationPrompt('a robot juggling'), $this->progressCallback());

        $userText = $captured->messages[0]->text;
        $this->assertStringContainsString('a robot juggling', $userText);
        $this->assertStringContainsString('Output ONLY', $userText);
    }

    public function testDescribesImageViaAltTextWhenAssistantDoesNotSupportImages(): void
    {
        $altTextProvider = $this->createMock(AltTextProvider::class);
        $altTextProvider->method('generateAltTextForImageString')
            ->willReturn('a fluffy orange tabby cat sitting on a windowsill');

        $assistant = $this->createStub(ContextCompletingAssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')
            ->willReturnCallback(function (AssistantContext $context) use (&$captured): CompletionResponse {
                $captured = $context;

                return new CompletionResponse('i2va prompt');
            });

        $preprocessor = new MiniMaxH3VideoPromptPreprocessor($assistant, false, $altTextProvider, new NullLogger());

        $preprocessor->preprocess(new VideoGenerationPrompt('continue the scene', firstFrame: self::FIRST), $this->progressCallback());

        // Text-only model: the photo is NOT attached ...
        $this->assertNull($captured->messages[0]->photo);
        // ... but the generated description is fed as text instead.
        $userText = $captured->messages[0]->text;
        $this->assertStringContainsString('a fluffy orange tabby cat sitting on a windowsill', $userText);
        $this->assertStringContainsString('cannot see images directly', $userText);
    }

    private function capturedMode(AssistantContext $captured): VideoTaskMode
    {
        // The chosen task is encoded in the system prompt's OUTPUT FORMAT header.
        $header = '';
        foreach (explode("\n", $captured->systemPrompt) as $line) {
            if (str_starts_with($line, 'OUTPUT FORMAT (')) {
                $header = $line;
                break;
            }
        }

        return match (true) {
            str_contains($header, 'text-to-video')              => VideoTaskMode::TextToVideo,
            str_contains($header, 'first-and-last-frame video') => VideoTaskMode::FirstLastFrame,
            str_contains($header, 'first-frame video')          => VideoTaskMode::FirstFrame,
            str_contains($header, 'last-frame video')           => VideoTaskMode::LastFrame,
            str_contains($header, 'full-reference mode')        => VideoTaskMode::FullReference,
            default                                               => throw new \LogicException('No OUTPUT FORMAT header found'),
        };
    }

    private function preprocessorReturning(string $completion, ?AssistantContext &$captured, bool $supportsImages = true): MiniMaxH3VideoPromptPreprocessor
    {
        $assistant = $this->createStub(ContextCompletingAssistantInterface::class);
        $assistant->method('getCompletionBasedOnContext')
            ->willReturnCallback(function (AssistantContext $context) use (&$captured, $completion): CompletionResponse {
                $captured = $context;

                return new CompletionResponse($completion);
            });

        return new MiniMaxH3VideoPromptPreprocessor($assistant, $supportsImages, $this->createStub(AltTextProvider::class), new NullLogger());
    }

    private function progressCallback(): ProgressUpdateCallback
    {
        return $this->createStub(ProgressUpdateCallback::class);
    }
}
