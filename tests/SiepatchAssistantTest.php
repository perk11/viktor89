<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\SiepatchAssistant;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\TelegramInternalMessageResponderInterface;
use Perk11\Viktor89\TelegramResponderInterface;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Assistant\AltTextProvider;

#[CoversClass(SiepatchAssistant::class)]
class SiepatchAssistantTest extends TestCase
{
    private const string BOT_USERNAME = 'TestBot';

    protected function setUp(): void
    {
        // applyAuthorExtraction() reads the bot username from the environment.
        $_ENV['TELEGRAM_BOT_USERNAME'] = self::BOT_USERNAME;
    }

    public function testImplementsAssistantInterface(): void
    {
        $reflection = new \ReflectionClass(SiepatchAssistant::class);
        $this->assertTrue($reflection->implementsInterface(AssistantInterface::class));
    }

    public function testImplementsAbortableStreamingResponseGenerator(): void
    {
        $reflection = new \ReflectionClass(SiepatchAssistant::class);
        $this->assertTrue($reflection->implementsInterface(AbortableStreamingResponseGenerator::class));
    }

    public function testDoesNotUseDeprecatedResponderInterfaces(): void
    {
        $reflection = new \ReflectionClass(SiepatchAssistant::class);
        $this->assertFalse($reflection->implementsInterface(TelegramInternalMessageResponderInterface::class));
        $this->assertFalse($reflection->implementsInterface(TelegramResponderInterface::class));
    }

    public function testProcessMessageChainIsNotAbstract(): void
    {
        $reflection = new \ReflectionClass(SiepatchAssistant::class);
        $this->assertFalse($reflection->getMethod('processMessageChain')->isAbstract());
    }

    public function testGetCompletionOptionsMatchesSiepatchLegacyParameters(): void
    {
        $assistant = $this->buildAssistant();
        $options = $this->callProtected($assistant, 'getCompletionOptions', ['hello']);

        // The legacy Siepatch responder prefixes the prompt with two newlines.
        $this->assertSame("\n\n" . 'hello', $options['prompt']);
        $this->assertSame(0.6, $options['temperature']);
        $this->assertFalse($options['cache_prompt']);
        $this->assertSame(1.18, $options['repeat_penalty']);
        $this->assertSame(4096, $options['repeat_last_n']);
        $this->assertTrue($options['penalize_nl']);
        $this->assertSame(40, $options['top_k']);
        $this->assertSame(0.95, $options['top_p']);
        $this->assertSame(0.1, $options['min_p']);
        $this->assertSame(1, $options['tfs_z']);
        $this->assertSame(0, $options['frequency_penalty']);
        $this->assertSame(0, $options['presence_penalty']);
        $this->assertTrue($options['stream']);
        $this->assertSame(['<human>', '<bot>'], $options['stop']);
    }

    public function testFormatInternalMessageForContextProducesBotFormat(): void
    {
        $assistant = $this->buildAssistant();
        $message = $this->internalMessage('Ivan Petrov', 'hi there');

        $formatted = $this->callProtected($assistant, 'formatInternalMessageForContext', [$message]);

        $this->assertSame("<bot>: [Ivan_Petrov] hi there\n", $formatted);
    }

    public function testGenerateContextAppendsPersonalityAndResponseStart(): void
    {
        $assistant = $this->buildAssistant();
        $previous = [$this->internalMessage('Ivan', 'hello')];
        $incoming = $this->internalMessage('Anna', 'how are you?');

        $context = $this->callProtected($assistant, 'generateContext', [
            $previous, $incoming, 'Pirate', 'Arr', false,
        ]);

        $this->assertSame("<bot>: [Ivan] hello\n<bot>: [Anna] how are you?\n<bot>: [Pirate] Arr", $context);
    }

    public function testGenerateContextWithoutPersonalityOrResponseStartLeavesOpenAuthorTag(): void
    {
        $assistant = $this->buildAssistant();
        $incoming = $this->internalMessage('Anna', 'hi');

        $context = $this->callProtected($assistant, 'generateContext', [
            [], $incoming, null, null, false,
        ]);

        $this->assertSame("<bot>: [Anna] hi\n<bot>: [", $context);
    }

    public function testGenerateContextInContinueModeDoesNotAppendIncomingMessage(): void
    {
        $assistant = $this->buildAssistant();
        $previous = [$this->internalMessage('Anna', 'continue this')];

        $context = $this->callProtected($assistant, 'generateContext', [
            $previous, $this->internalMessage('Anna', '/continue'), 'Pirate', null, true,
        ]);

        $this->assertSame('<bot>: [Anna] continue this', $context);
    }

    public function testResponseEndingWithClosingBracketNeedsRegeneration(): void
    {
        $assistant = $this->buildAssistant();
        $this->assertTrue(
            $this->callProtected($assistant, 'doesResponseNeedTobeRegenerated', ['[Someone]', 'prompt'])
        );
    }

    public function testRepeatedResponseNeedsRegeneration(): void
    {
        $assistant = $this->buildAssistant();
        // Response body ("hello") is contained verbatim in the prompt.
        $this->assertTrue(
            $this->callProtected($assistant, 'doesResponseNeedTobeRegenerated', [
                '[Ivan] hello', '<bot>: [Ivan] hello',
            ])
        );
    }

    public function testShortRefusalResponseNeedsRegeneration(): void
    {
        $assistant = $this->buildAssistant();
        $this->assertTrue(
            $this->callProtected($assistant, 'doesResponseNeedTobeRegenerated', ['[Ivan] я не умею', 'prompt'])
        );
        $this->assertTrue(
            $this->callProtected($assistant, 'doesResponseNeedTobeRegenerated', ['[Ivan] я не могу', 'prompt'])
        );
        $this->assertTrue(
            $this->callProtected($assistant, 'doesResponseNeedTobeRegenerated', ['[Ivan] я не знаю', 'prompt'])
        );
    }

    public function testValidResponseDoesNotNeedRegeneration(): void
    {
        $assistant = $this->buildAssistant();
        $this->assertFalse(
            $this->callProtected($assistant, 'doesResponseNeedTobeRegenerated', [
                '[Ivan] This is a perfectly fine long response that is not in the prompt',
                'unrelated prompt',
            ])
        );
    }

    public function testApplyAuthorExtractionExtractsAuthorAndPrefixesRawText(): void
    {
        $assistant = $this->buildAssistant();
        $message = $this->internalMessage('Whatever', '[Ivan] hello world');

        $this->callProtected($assistant, 'applyAuthorExtraction', [$message]);

        // Faithfully mirrors the legacy Siepatch responder: userName keeps the leading '[',
        // rawMessageText is what actually gets sent, messageText has the author stripped.
        $this->assertSame('[Ivan', $message->userName);
        $this->assertSame('[отвечает [Ivan] hello world', $message->rawMessageText);
        $this->assertSame('hello world', $message->messageText);
    }

    public function testApplyAuthorExtractionWithoutAuthorTagFallsBackToBotUsername(): void
    {
        $assistant = $this->buildAssistant();
        $message = $this->internalMessage('Whatever', 'no author tag at all');

        $this->callProtected($assistant, 'applyAuthorExtraction', [$message]);

        // Mirrors the legacy behaviour: with no ']' present, userName becomes the
        // bot username, the raw text is prefixed and the first two characters are
        // dropped from messageText (mb_substr offset false + 2 === 2).
        $this->assertSame(self::BOT_USERNAME, $message->userName);
        $this->assertSame('[отвечает no author tag at all', $message->rawMessageText);
        $this->assertSame(' author tag at all', $message->messageText);
    }

    public function testReplaceYouTubeLinksSwapsYoutubeUrlForKnownVideo(): void
    {
        $assistant = $this->buildAssistant();
        $videos = $this->knownVideos();

        $result = $this->callProtected($assistant, 'replaceYouTubeLinks', [
            'look at this https://www.youtube.com/watch?v=dQw4w9WgXcQ please',
        ]);

        foreach ($videos as $video) {
            if (str_contains($result, $video)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail("Replaced URL was not one of the known Siepatch videos: $result");
    }

    public function testReplaceYouTubeLinksLeavesPlainMessageUntouched(): void
    {
        $assistant = $this->buildAssistant();

        $result = $this->callProtected($assistant, 'replaceYouTubeLinks', ['just a normal message']);

        $this->assertSame('just a normal message', $result);
    }

    public function testConvertContextToPromptProducesBotFormat(): void
    {
        $assistant = $this->buildAssistant();
        $context = new AssistantContext();
        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;
        $userMessage->text = 'hello';
        $assistantMessage = new AssistantContextMessage();
        $assistantMessage->isUser = false;
        $assistantMessage->text = 'hi';
        $context->messages = [$userMessage, $assistantMessage];
        $context->responseStart = 'pre';

        $prompt = $this->callProtected($assistant, 'convertContextToPrompt', [$context]);

        $this->assertSame("<bot>: [User] hello\n<bot>: [Assistant] hi\n<bot>: [pre", $prompt);
    }

    private function buildAssistant(): SiepatchAssistant
    {
        return new SiepatchAssistant(
            $this->reader(null),
            $this->reader(null),
            $this->reader(null),
            $this->createStub(TelegramFileDownloader::class),
            $this->createStub(AltTextProvider::class),
            123,
            'http://localhost:8079',
            new OpenAiCompletionStringParser(),
            $this->reader(null),
        );
    }

    private function reader(?string $value): UserPreferenceReaderInterface
    {
        return new class($value) implements UserPreferenceReaderInterface {
            public function __construct(private readonly ?string $value)
            {
            }

            public function getCurrentPreferenceValue(int $userId): ?string
            {
                return $this->value;
            }
        };
    }

    private function internalMessage(string $userName, string $messageText): InternalMessage
    {
        $message = new InternalMessage();
        $message->userName = $userName;
        $message->messageText = $messageText;

        return $message;
    }

    /**
     * @return string[]
     */
    private function knownVideos(): array
    {
        return [
            'https://www.youtube.com/watch?v=JdGgys-QQdE',
            'https://www.youtube.com/watch?v=2oe_7IRb_rI',
            'https://www.youtube.com/watch?v=_L0QyGE4nJM',
            'https://www.youtube.com/watch?v=KvHSQkTQpX8',
            'https://www.youtube.com/watch?v=krt2AXyXHHE',
            'https://www.youtube.com/watch?v=WDaNJW_jEBo',
            'https://www.youtube.com/watch?v=8EM5R3VkaWI',
            'https://www.youtube.com/watch?v=HvGsbZ1e2sw',
            'https://www.youtube.com/watch?v=qCljI3cIObU',
            'https://www.youtube.com/watch?v=5P6ADakiwcg',
        ];
    }

    /**
     * @param array<int, mixed> $args
     */
    private function callProtected(SiepatchAssistant $assistant, string $method, array $args): mixed
    {
        // Reflection can invoke non-public members directly since PHP 8.1;
        // setAccessible() is deprecated as of 8.5 and intentionally not called.
        return (new \ReflectionMethod($assistant, $method))->invokeArgs($assistant, $args);
    }
}
