<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers {@see AbstractOpenAIAPiAssistant::convertMessageChainToAssistantContext()},
 * in particular how photo messages and their captions are turned into the text
 * of an {@see \Perk11\Viktor89\Assistant\AssistantContextMessage}.
 */
#[CoversClass(AbstractOpenAIAPiAssistant::class)]
class AbstractOpenAIAPiAssistantTest extends TestCase
{
    private const int BOT_USER_ID = 12345;

    private RecordingAltTextProvider $altTextProvider;

    /**
     * Regression guard for the bug where a plain text message (no photo) ended
     * up without its text and needlessly consulted the alt-text provider. The
     * user's text must be preserved verbatim and provide() must never run.
     */
    public function testPlainTextMessageKeepsItsTextAndSkipsAltTextProvider(): void
    {
        $assistant = $this->buildAssistant(supportsImages: false);
        $chain = $this->chainWithMessage(messageText: 'hello there');

        $context = $this->convert($assistant, $chain);

        $this->assertCount(1, $context->messages);
        $message = $context->messages[0];
        $this->assertSame('hello there', $message->text);
        $this->assertNull($message->photo);
        $this->assertFalse(
            $this->altTextProvider->wasCalled(),
            'AltTextProvider must not be consulted for a normal text message',
        );
    }

    /**
     * The core behaviour changed by the uncommitted work: for an assistant
     * without vision, a photo's auto-generated description comes first and the
     * user's caption is appended under a [caption] marker (previously the
     * caption was concatenated before the description).
     */
    public function testPhotoWithCaptionForNonVisionAssistantPutsAltTextBeforeCaption(): void
    {
        $assistant = $this->buildAssistant(supportsImages: false, altText: 'a red car');
        $chain = $this->chainWithMessage(messageText: 'look at this', photoFileId: 'file-1');

        $context = $this->convert($assistant, $chain);

        $this->assertSame("a red car\n[caption] look at this", $context->messages[0]->text);
        $this->assertNull($context->messages[0]->photo);
    }

    public function testPhotoWithoutCaptionForNonVisionAssistantUsesAltTextOnly(): void
    {
        $assistant = $this->buildAssistant(supportsImages: false, altText: 'a red car');
        $chain = $this->chainWithMessage(messageText: '', photoFileId: 'file-1');

        $context = $this->convert($assistant, $chain);

        $this->assertSame('a red car', $context->messages[0]->text);
        $this->assertStringNotContainsString('[caption]', $context->messages[0]->text);
    }

    public function testPhotoWithCaptionForVisionAssistantAttachesPhotoAndCaptionText(): void
    {
        $assistant = $this->buildAssistant(supportsImages: true);
        $chain = $this->chainWithMessage(messageText: 'check this', photoFileId: 'file-1');

        $context = $this->convert($assistant, $chain);

        $message = $context->messages[0];
        $this->assertSame(StubPhotoDownloader::PHOTO_BYTES, $message->photo);
        $this->assertSame('check this', $message->text);
        $this->assertFalse(
            $this->altTextProvider->wasCalled(),
            'Vision assistants send the photo directly and must not generate alt text',
        );
    }

    public function testPhotoWithoutCaptionForVisionAssistantAttachesPhotoWithEmptyText(): void
    {
        $assistant = $this->buildAssistant(supportsImages: true);
        $chain = $this->chainWithMessage(messageText: '', photoFileId: 'file-1');

        $context = $this->convert($assistant, $chain);

        $this->assertSame(StubPhotoDownloader::PHOTO_BYTES, $context->messages[0]->photo);
        $this->assertSame('', $context->messages[0]->text);
    }

    /**
     * A text-less message (e.g. a voice note) must still fall back to the
     * alt-text provider, which returns its transcription.
     */
    public function testEmptyTextMessageFallsBackToAltText(): void
    {
        $assistant = $this->buildAssistant(supportsImages: false, altText: 'transcribed voice');
        $chain = $this->chainWithMessage(messageText: '');

        $context = $this->convert($assistant, $chain);

        $this->assertSame('transcribed voice', $context->messages[0]->text);
        $this->assertNull($context->messages[0]->photo);
        $this->assertTrue($this->altTextProvider->wasCalled());
    }

    // --- helpers ---

    private function buildAssistant(bool $supportsImages, ?string $altText = null): AbstractOpenAIAPiAssistant
    {
        $this->altTextProvider = new RecordingAltTextProvider($altText);

        return new ConvertContextTestAssistant(
            new NullPreferenceReader(),
            new NullPreferenceReader(),
            new NullPreferenceReader(),
            new StubPhotoDownloader(),
            $this->altTextProvider,
            self::BOT_USER_ID,
            supportsImages: $supportsImages,
        );
    }

    private function chainWithMessage(string $messageText, ?string $photoFileId = null): MessageChain
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->type = 'text';
        $message->userId = 999;
        $message->userName = 'Tester';
        $message->chatId = 1;
        $message->date = time();
        $message->messageText = $messageText;
        $message->photoFileId = $photoFileId;

        return new MessageChain([$message]);
    }

    private function convert(AbstractOpenAIAPiAssistant $assistant, MessageChain $chain): AssistantContext
    {
        return (new ReflectionMethod($assistant, 'convertMessageChainToAssistantContext'))
            ->invoke($assistant, $chain, null, null, new NullProgressUpdateCallback());
    }
}

/** Minimal concrete subclass so the protected method under test can be exercised. */
class ConvertContextTestAssistant extends AbstractOpenAIAPiAssistant
{
    public function __construct(
        \Perk11\Viktor89\UserPreferenceReaderInterface $systemPromptProcessor,
        \Perk11\Viktor89\UserPreferenceReaderInterface $responseStartProcessor,
        \Perk11\Viktor89\UserPreferenceReaderInterface $editFrequencyProcessor,
        \Perk11\Viktor89\TelegramFileDownloader $telegramFileDownloader,
        \Perk11\Viktor89\Assistant\AltTextProvider $altTextProvider,
        int $telegramBotUserId,
        bool $supportsImages,
    ) {
        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $editFrequencyProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegramBotUserId,
            'http://localhost',
            supportsImages: $supportsImages,
            logger: new \Psr\Log\NullLogger(),
        );
    }

    public function getCompletionBasedOnContext(
        AssistantContext $assistantContext,
        ?callable $streamFunction = null,
        ?MessageChain $messageChain = null,
        ?ProgressUpdateCallback $progressUpdateCallback = null,
    ): \Perk11\Viktor89\Assistant\CompletionResponse {
        return new \Perk11\Viktor89\Assistant\CompletionResponse('');
    }
}

/** Returns a constant for every photo, without touching Telegram or the filesystem. */
class StubPhotoDownloader extends \Perk11\Viktor89\TelegramFileDownloader
{
    public const string PHOTO_BYTES = '<image-bytes>';

    public function __construct()
    {
    }

    public function downloadPhotoFromInternalMessage(InternalMessage $internalMessage): string
    {
        return self::PHOTO_BYTES;
    }
}

/** Records whether provide() was invoked and returns a fixed value. */
class RecordingAltTextProvider extends \Perk11\Viktor89\Assistant\AltTextProvider
{
    private bool $called = false;

    public function __construct(private readonly ?string $value)
    {
    }

    public function provide(InternalMessage $internalMessage, ProgressUpdateCallback $progressUpdateCallback): ?string
    {
        $this->called = true;

        return $this->value;
    }

    public function wasCalled(): bool
    {
        return $this->called;
    }
}

/** No-op preference reader; the converted context ignores its values here. */
class NullPreferenceReader implements \Perk11\Viktor89\UserPreferenceReaderInterface
{
    public function getCurrentPreferenceValue(int $userId): ?string
    {
        return null;
    }
}

/** No-op callback; convertMessageChainToAssistantContext only forwards it to provide(). */
class NullProgressUpdateCallback implements ProgressUpdateCallback
{
    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
    {
    }

    public function subscribe(callable $subscriber): void
    {
    }
}
