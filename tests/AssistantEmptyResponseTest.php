<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant;
use Perk11\Viktor89\IPC\EchoUpdateCallback;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\NullAltTextProvider;
use Perk11\Viktor89\Test\Support\NullTelegramFileDownloader;
use Perk11\Viktor89\Test\Support\StubStreamingAssistant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(AbstractOpenAIAPiAssistant::class)]
class AssistantEmptyResponseTest extends TestCase
{
    /**
     * Regression for the bug where the model produced no text (only tool calls
     * whose output was already delivered to the user, or an empty completion)
     * yet the bot still tried to send an empty message, which Telegram rejects
     * with "message text is empty". It must emit nothing instead.
     */
    public function testEmptyCompletionProducesNoResponse(): void
    {
        $assistant = $this->buildAssistant(static fn () => '');

        $result = $assistant->processMessageChain(
            IntegrationTestDsl::buildIncomingMessageChain(-100300),
            new EchoUpdateCallback(),
        );

        $this->assertNull($result->response, 'No message must be produced when the completion is empty');
        $this->assertTrue(
            $result->abortProcessing,
            'Processing must still abort so later processors do not also reply',
        );
    }

    /**
     * Whitespace-only output must be treated the same as truly empty output.
     */
    public function testWhitespaceOnlyCompletionProducesNoResponse(): void
    {
        $assistant = $this->buildAssistant(static fn () => "  \n\t ");

        $result = $assistant->processMessageChain(
            IntegrationTestDsl::buildIncomingMessageChain(-100300),
            new EchoUpdateCallback(),
        );

        $this->assertNull($result->response, 'Whitespace-only completion must not be sent as an empty message');
    }

    /**
     * Regression guard: a normal completion is still delivered as a response,
     * so the empty-output guard does not swallow legitimate replies.
     */
    public function testNonEmptyCompletionProducesAResponse(): void
    {
        $assistant = $this->buildAssistant(static fn () => 'Here is a real answer.');

        $result = $assistant->processMessageChain(
            IntegrationTestDsl::buildIncomingMessageChain(-100300),
            new EchoUpdateCallback(),
        );

        $this->assertNotNull($result->response, 'A normal completion must still produce a message');
        $this->assertStringContainsString('Here is a real answer.', $result->response->messageText);
    }

    private function buildAssistant(\Closure $behavior): StubStreamingAssistant
    {
        return new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader('You are a helpful test assistant.'),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new NullTelegramFileDownloader(),
            new NullAltTextProvider(),
            $behavior,
        );
    }
}
