<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Integration tests for the message-sending paths that talk to Telegram through
 * Longman's Request: the MarkdownV2 send fallback chain and the parts of
 * ProcessingResultExecutor that are not exercised by the streaming tests
 * (reactions, and stripping replies in private chats when disabled).
 */
#[CoversClass(InternalMessage::class)]
#[CoversClass(ProcessingResultExecutor::class)]
class MessageSendingIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testMarkdownV2SendFallsBackThroughMarkdownThenDefault(): void
    {
        // Fail MarkdownV2 and plain markdown; succeed for anything else.
        $this->telegramResponseOverride = static function (string $action, array $form): ?array {
            if ($action !== 'sendMessage') {
                return null;
            }
            if (in_array($form['parse_mode'] ?? null, ['MarkdownV2', 'markdown'], true)) {
                return ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"];
            }

            return null;
        };

        $message = new InternalMessage();
        $message->chatId = 123;
        $message->messageText = 'Some **bold** text';
        $message->parseMode = 'MarkdownV2';

        ob_start();
        try {
            $response = $message->send();
        } finally {
            ob_end_clean();
        }

        $this->assertTrue($response->isOk(), 'The final Default-mode attempt must succeed');
        $this->assertNotNull($message->id, 'The message id must be populated from the successful send');

        $sendCalls = array_values(array_filter(
            $this->recordedCalls(),
            static fn (array $call): bool => $call['action'] === 'sendMessage',
        ));
        $this->assertCount(3, $sendCalls, 'All three parse modes must be attempted in order');

        $attemptedParseModes = array_map(
            static fn (array $call) => $call['form']['parse_mode'] ?? null,
            $sendCalls,
        );
        $this->assertSame(
            ['MarkdownV2', 'markdown', null],
            $attemptedParseModes,
            'The fallback must go MarkdownV2 -> markdown -> Default',
        );
    }

    public function testReactionIsSentToTelegram(): void
    {
        $messageToReactTo = new InternalMessage();
        $messageToReactTo->id = 77;
        $messageToReactTo->chatId = 88;
        $messageToReactTo->userName = 'Tester';

        $result = new ProcessingResult(null, true, '👍', $messageToReactTo);

        ob_start();
        try {
            (new ProcessingResultExecutor(new \Perk11\Viktor89\Test\Support\NullMessageRepository()))->execute($result);
        } finally {
            ob_end_clean();
        }

        $reactionCalls = array_values(array_filter(
            $this->recordedCalls(),
            static fn (array $call): bool => $call['action'] === 'setMessageReaction',
        ));

        $this->assertCount(1, $reactionCalls, 'Exactly one reaction request must be sent');
        $this->assertSame(88, $reactionCalls[0]['chatId']);
        $this->assertSame(77, (int) ($reactionCalls[0]['form']['message_id'] ?? 0));
        $this->assertStringContainsString('emoji', $reactionCalls[0]['form']['reaction'] ?? '');
    }

    public function testReplyIsStrippedInPrivateChatWhenRepliesAreDisabled(): void
    {
        $original = new InternalMessage();
        $original->id = 10;
        $original->chatId = 555; // private chat (positive id)

        $this->executeWithReplySetting($original, repliesInPMs: false);

        $sendCalls = $this->sendMessageCalls();
        $this->assertCount(1, $sendCalls);
        $this->assertArrayNotHasKey(
            'reply_parameters',
            $sendCalls[0]['form'],
            'The reply must be dropped for private chats when replies are disabled',
        );
    }

    public function testReplyIsKeptInGroupChatEvenWhenRepliesAreDisabled(): void
    {
        $original = new InternalMessage();
        $original->id = 10;
        $original->chatId = -100555; // group chat (negative id)

        $this->executeWithReplySetting($original, repliesInPMs: false);

        $sendCalls = $this->sendMessageCalls();
        $this->assertCount(1, $sendCalls);
        $this->assertArrayHasKey(
            'reply_parameters',
            $sendCalls[0]['form'],
            'Replies must be preserved in group chats even when PM replies are disabled',
        );
    }

    private function executeWithReplySetting(InternalMessage $original, bool $repliesInPMs): void
    {
        $response = InternalMessage::asResponseTo($original, 'Reply text');
        $response->parseMode = 'Default';
        $result = new ProcessingResult($response, true);

        ob_start();
        try {
            (new ProcessingResultExecutor(new \Perk11\Viktor89\Test\Support\NullMessageRepository(), $repliesInPMs))->execute($result);
        } finally {
            ob_end_clean();
        }
    }

    /** @return list<array<string, mixed>> */
    private function sendMessageCalls(): array
    {
        return array_values(array_filter(
            $this->recordedCalls(),
            static fn (array $call): bool => $call['action'] === 'sendMessage',
        ));
    }
}
