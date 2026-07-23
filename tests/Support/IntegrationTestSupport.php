<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test\Support;

use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Pipeline\Queue;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Shared infrastructure for integration tests: a recording Telegram client
 * (mocks every Telegram API call by overriding Request's HTTP client) plus
 * small helpers for wiring up the worker<->main IPC channel and a stub LLM.
 *
 * Loaded via require_once from individual integration test files because the
 * project has no test autoload configured.
 */

/** Telegram bot id used across the integration tests. */
const TELEGRAM_TEST_BOT_ID = 123456789;

/**
 * Records every Telegram API call made through Longman's Request static facade
 * into {@see $telegramTransactions}, in order, and answers with canned
 * successful responses (overridable per-call via {@see $telegramResponseOverride}).
 */
trait TelegramRecordingTrait
{
    /** @var array<int, array{request: RequestInterface, response: ResponseInterface}> */
    protected array $telegramTransactions = [];

    /**
     * Optional override applied to every request. Signature:
     *   fn(string $action, array $form): (?array)
     * Returning a payload array replaces the default response body.
     */
    protected ?\Closure $telegramResponseOverride = null;

    protected function installRecordingTelegramClient(): void
    {
        $this->telegramTransactions = [];
        $_ENV['TELEGRAM_BOT_USERNAME'] = 'testbot';

        $stack = HandlerStack::create(function (RequestInterface $request): PromiseInterface {
            return new \GuzzleHttp\Promise\FulfilledPromise($this->buildTelegramResponse($request));
        });
        $stack->push(Middleware::history($this->telegramTransactions));

        // The Telegram constructor initialises Request (and a default client);
        // we then replace the client with our recording one.
        new Telegram(TELEGRAM_TEST_BOT_ID . ':AAAAAAAAAAAAAAAA', 'testbot');
        Request::setClient(new Client(['handler' => $stack]));
    }

    protected function buildTelegramResponse(RequestInterface $request): ResponseInterface
    {
        $action = self::extractActionFromRequest($request);
        parse_str((string) $request->getBody(), $form);
        $chatId = (int) ($form['chat_id'] ?? 0);

        if ($this->telegramResponseOverride !== null) {
            $override = ($this->telegramResponseOverride)($action, $form);
            if ($override !== null) {
                return self::jsonResponse($override);
            }
        }

        if (in_array($action, ['sendMessage', 'sendRichMessage', 'editMessageText'], true)) {
            return self::jsonResponse([
                'ok' => true,
                'result' => [
                    'message_id' => 42,
                    'date' => time(),
                    'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => 'Tester'],
                    'from' => ['id' => TELEGRAM_TEST_BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
                    'text' => $form['text'] ?? '',
                ],
            ]);
        }

        if ($action === 'getMe') {
            return self::jsonResponse([
                'ok' => true,
                'result' => ['id' => TELEGRAM_TEST_BOT_ID, 'is_bot' => true, 'first_name' => 'TestBot', 'username' => 'testbot'],
            ]);
        }

        if ($action === 'getChatMember') {
            // The bot is treated as an administrator by default so admin-gated
            // ephemeral sends work in tests; override per-call to test the non-admin path.
            return self::jsonResponse([
                'ok' => true,
                'result' => ['user' => ['id' => (int) ($form['user_id'] ?? 0), 'is_bot' => true], 'status' => 'administrator'],
            ]);
        }

        return self::jsonResponse(['ok' => true, 'result' => true]);
    }

    /**
     * @return list<array{action: string, chatId: int, form: array<string, mixed>, text: ?string, draftId: ?int}>
     */
    protected function recordedCalls(): array
    {
        return array_map(function (array $transaction): array {
            $request = $transaction['request'];
            parse_str((string) $request->getBody(), $form);

            return [
                'action' => self::extractActionFromRequest($request),
                'chatId' => (int) ($form['chat_id'] ?? 0),
                'form' => $form,
                'text' => $form['text'] ?? null,
                'draftId' => isset($form['draft_id']) ? (int) $form['draft_id'] : null,
            ];
        }, $this->telegramTransactions);
    }

    /** @return list<string> */
    protected function recordedActions(): array
    {
        return array_map(
            static fn(array $transaction): string => self::extractActionFromRequest($transaction['request']),
            $this->telegramTransactions,
        );
    }

    /**
     * Action names recorded after the final message send/edit. Used to prove
     * that no typing notification or draft can follow the actual message.
     *
     * @return list<string>
     */
    protected function recordedActionsAfterLastMessage(): array
    {
        $calls = $this->recordedCalls();
        $messageActions = ['sendMessage', 'sendRichMessage', 'editMessageText'];
        $lastMessageIndex = -1;
        foreach ($calls as $index => $call) {
            if (in_array($call['action'], $messageActions, true)) {
                $lastMessageIndex = $index;
            }
        }

        return array_map(
            static fn(array $call): string => $call['action'],
            $lastMessageIndex === -1 ? [] : array_slice($calls, $lastMessageIndex + 1),
        );
    }

    private static function extractActionFromRequest(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        return substr($path, (int) strrpos($path, '/') + 1);
    }

    private static function jsonResponse(array $payload): ResponseInterface
    {
        return new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}

/**
 * Message repository substitute for integration tests: avoids both the SQLite
 * side effects of the real MessageRepository (which opens a SQLite connection in
 * its constructor) and the PHPUnit notices produced when mocking a class with
 * readonly / typed properties that are never initialised in a test double.
 */
class NullMessageRepository extends \Perk11\Viktor89\Repository\MessageRepository
{
    public function __construct()
    {
        // Deliberately skip parent::__construct() so no SQLite file is opened.
    }

    public function logInternalMessage(InternalMessage $message): void
    {
        // no-op
    }

    public function logMessage(\Longman\TelegramBot\Entities\Message $message): void
    {
        // no-op
    }
}

/** Stub {@see \Perk11\Viktor89\TelegramFileDownloader} that needs no dependencies. */
class NullTelegramFileDownloader extends \Perk11\Viktor89\TelegramFileDownloader
{
    public function __construct()
    {
    }
}

/** Stub {@see \Perk11\Viktor89\Assistant\AltTextProvider} that needs no dependencies. */
class NullAltTextProvider extends \Perk11\Viktor89\Assistant\AltTextProvider
{
    public function __construct()
    {
    }
}

/**
 * Simple in-process bidirectional {@see Channel} built from two Amp queues,
 * used to connect a fake worker coroutine to the real RunningTaskTracker.
 */
class InMemoryChannel implements Channel
{
    /** @var list<\Closure():void> */
    private array $onCloseCallbacks = [];
    private bool $closed = false;

    public function __construct(
        private readonly Queue $send,
        private readonly \Amp\Pipeline\ConcurrentIterator $receive,
    ) {
    }

    public function send(mixed $data): void
    {
        if ($this->closed) {
            throw new ChannelException('Channel has been closed');
        }
        $this->send->push($data);
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if (!$this->receive->continue($cancellation)) {
            throw new ChannelException('Channel has been closed');
        }

        return $this->receive->getValue();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (!$this->send->isComplete()) {
            $this->send->complete();
        }
        foreach ($this->onCloseCallbacks as $callback) {
            $callback();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onCloseCallbacks[] = $onClose;
    }
}

/**
 * Minimal concrete assistant. The LLM call is replaced by a caller-supplied
 * behaviour closure that drives the real stream function with arbitrary chunks
 * and delays. Everything else (typing setup, draft throttling, edit-stream
 * logic, response shaping) is inherited unchanged.
 */
class StubStreamingAssistant extends AbstractOpenAIAPiAssistant
{
    /** Speed up draft updates so tests do not take seconds per chunk. */
    protected static float $draftFrequencySeconds = 0.02;

    private \Closure $behavior;

    /**
     * @param \Closure(callable(string):void): string $behavior Receives the stream
     *        function and returns the final completion content.
     */
    public function __construct(
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        UserPreferenceReaderInterface $editFrequencyProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        AltTextProvider $altTextProvider,
        \Closure $behavior,
    ) {
        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $editFrequencyProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            TELEGRAM_TEST_BOT_ID,
            'http://localhost',
            logger: new \Psr\Log\NullLogger(),
        );
        $this->behavior = $behavior;
    }

    public function getCompletionBasedOnContext(
        AssistantContext $assistantContext,
        $streamFunction = null,
        ?MessageChain $messageChain = null,
        ?ProgressUpdateCallback $progressUpdateCallback = null,
    ): CompletionResponse {
        return new CompletionResponse(($this->behavior)($streamFunction));
    }
}

/**
 * Small helpers for assembling the IPC plumbing and stub dependencies used by
 * the integration tests.
 */
final class IntegrationTestDsl
{
    /** @return array{0: Channel, 1: Channel} [workerChannel, mainChannel] */
    public static function createChannelPair(): array
    {
        $workerToMain = new Queue();
        $mainToWorker = new Queue();

        return [
            new InMemoryChannel($workerToMain, $mainToWorker->iterate()),
            new InMemoryChannel($mainToWorker, $workerToMain->iterate()),
        ];
    }

    public static function makeExecution(Channel $mainChannel): Execution
    {
        $task = new class implements Task {
            public function run(Channel $channel, Cancellation $cancellation): mixed
            {
                return null;
            }
        };

        return new Execution($task, $mainChannel, Future::complete());
    }

    public static function buildIncomingMessageChain(int $chatId, string $text = 'Hello, please respond.'): MessageChain
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->type = 'text';
        $message->userId = 999;
        $message->userName = 'Tester';
        $message->chatId = $chatId;
        $message->date = time();
        $message->messageText = $text;

        return new MessageChain([$message]);
    }

    public static function typingAction(int $chatId): ChatAction
    {
        return new ChatAction($chatId, ChatActionEnum::typing);
    }

    public static function stubPreferenceReader(?string $value): UserPreferenceReaderInterface
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
}
