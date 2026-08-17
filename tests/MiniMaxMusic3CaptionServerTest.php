<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the MiniMax-Music3 caption writer inference server
 * (inference-servers/minimax-music3-caption/main.js).
 *
 * Starts the real Node server against a mock OpenAI-compatible LLM and a stub
 * music-caption-rewriter skill in an isolated PI_CODING_AGENT_DIR, then exercises
 * the HTTP API end to end — including the real pi runs behind
 * POST /txt_lyrics2caption (the mock LLM drives pi through one read tool call so
 * the skill workflow is actually executed) and the error paths.
 *
 * Requires node and pi on PATH; skips otherwise.
 */
class MiniMaxMusic3CaptionServerTest extends TestCase
{
    private const SERVER_PATH = __DIR__ . '/../inference-servers/minimax-music3-caption/main.js';
    private const STUB_SKILL_MARKER = 'STUB MUSIC-CAPTION-REWRITER SKILL FOR INTEGRATION TEST';
    private const EXPECTED_CAPTION =
        "### Global Metadata\nIntegration-test caption: dark synthwave, 100-110 BPM, analog warmth.\n\n" .
        "### Vocal Details\nNone (instrumental).\n\n### Arrangement\nIntro -> Drop -> Outro.";

    /** @var list<resource> */
    private array $processes = [];
    private string $tempDir = '';
    private ?int $captionServerPort = null;
    private string $captionServerLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['node' => 'node --version', 'pi' => 'pi --version'] as $bin => $versionCommand) {
            exec($versionCommand . ' 2>/dev/null', $output, $exitCode);
            if ($exitCode !== 0) {
                $this->markTestSkipped("$bin is not available on PATH");
            }
        }
        $this->tempDir = sys_get_temp_dir() . '/viktor89-minimax-caption-test-' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
        $this->processes = [];
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Happy path: health, rendered pi config, caption generation via pi
    // ---------------------------------------------------------------------

    public function testHealthEndpointAndRenderedPiConfig(): void
    {
        $port = $this->ensureCaptionServer();

        [$status, $body] = $this->httpRequest("GET", "http://127.0.0.1:{$port}/health", null, 10);
        $this->assertSame(200, $status, $body);
        $health = $this->decodeJson($body);
        $this->assertTrue($health['ok']);
        $this->assertSame('mock-caption-model', $health['model']);
        $this->assertSame('caption-model', $health['provider']);
        $this->assertSame('music-caption-rewriter', $health['skill']);

        // The server must have rendered pi's config so that exactly one provider
        // with exactly one model exists, and it is the default.
        $models = $this->decodeJson((string) file_get_contents($this->tempDir . '/pi-agent/models.json'));
        $this->assertSame(['caption-model'], array_keys($models['providers']));
        $this->assertSame(['mock-caption-model'], array_column($models['providers']['caption-model']['models'], 'id'));
        // No API key configured -> placeholder, but still present so pi considers
        // the model authenticated for keyless local servers.
        $this->assertSame('none', $models['providers']['caption-model']['apiKey']);
        $settings = $this->decodeJson((string) file_get_contents($this->tempDir . '/pi-agent/settings.json'));
        $this->assertSame('caption-model', $settings['defaultProvider']);
        $this->assertSame('mock-caption-model', $settings['defaultModel']);
    }

    public function testCaptionRequestRunsSkillThroughPi(): void
    {
        $port = $this->ensureCaptionServer();

        $startedAt = microtime(true);
        [$status, $body] = $this->httpRequest(
            'POST',
            "http://127.0.0.1:{$port}/txt_lyrics2caption",
            json_encode([
                'text' => 'A dark synthwave track with pulsing arpeggios',
                'lyrics' => "[Verse]\nstreet lights\n\n[Chorus]\nneon rain",
                'constraints' => 'keep it under 200 words',
            ], JSON_THROW_ON_ERROR),
            120,
        );
        $this->assertSame(200, $status, $body . $this->serverLogTail());

        $response = $this->decodeJson($body);
        $this->assertSame(self::EXPECTED_CAPTION, $response['caption']);
        $this->assertSame('mock-caption-model', $response['info']['model']);
        $this->assertGreaterThan(0, $response['info']['durationMs']);
        $this->assertLessThan(120, microtime(true) - $startedAt);

        // The prompt pi received must carry the input text, lyrics, constraints and
        // the skill entry point (phase-first = first LLM call of the pi session).
        $first = $this->decodeJson((string) file_get_contents($this->tempDir . '/llm-records/phase-first.json'))['body'];
        $firstText = $this->messageText($first);
        $this->assertStringContainsString('A dark synthwave track with pulsing arpeggios', $firstText);
        $this->assertStringContainsString('[Verse]', $firstText);
        $this->assertStringContainsString('keep it under 200 words', $firstText);
        $this->assertStringContainsString('music-caption-rewriter/SKILL.md', $firstText);
        $this->assertFalse($this->hasMessageWithRole($first, 'tool'));

        // The final LLM call must contain the read tool result with the skill
        // content, proving pi actually executed the skill's read step.
        $final = $this->decodeJson((string) file_get_contents($this->tempDir . '/llm-records/phase-final.json'))['body'];
        $this->assertTrue($this->hasMessageWithRole($final, 'tool'));
        $this->assertStringContainsString(self::STUB_SKILL_MARKER, $this->messageText($final));
    }

    public function testCaptionRequestAcceptsCaptionFieldAlias(): void
    {
        $port = $this->ensureCaptionServer();

        [$status, $body] = $this->httpRequest(
            'POST',
            "http://127.0.0.1:{$port}/txt_lyrics2caption",
            json_encode(['caption' => 'sad piano ballad'], JSON_THROW_ON_ERROR),
            120,
        );
        $this->assertSame(200, $status, $body . $this->serverLogTail());
        $response = $this->decodeJson($body);
        $this->assertSame(self::EXPECTED_CAPTION, $response['caption']);

        $firstText = $this->messageText($this->decodeJson((string) file_get_contents($this->tempDir . '/llm-records/phase-first.json'))['body']);
        $this->assertStringContainsString('sad piano ballad', $firstText);
    }

    public function testApiKeyEnvIsForwardedToModelRequests(): void
    {
        // Own mock LLM + own pi dir so the shared server's records are not clobbered.
        $llmPort = $this->freePort();
        $recordDir = $this->tempDir . '/llm-records-apikey';
        $this->startNodeProcess($this->mockLlmCode(), [$llmPort, $this->skillPath(), $recordDir]);
        $piDir = $this->tempDir . '/pi-agent-apikey';
        @mkdir($piDir . '/skills', 0o775, true);

        $port = $this->startServer(
            "http://127.0.0.1:{$llmPort}/v1",
            piDir: $piDir,
            extraEnv: ['CAPTION_MODEL_API_KEY' => 'test-secret-123'],
        );

        [$status, $body] = $this->httpRequest(
            'POST',
            "http://127.0.0.1:{$port}/txt_lyrics2caption",
            json_encode(['text' => 'any description'], JSON_THROW_ON_ERROR),
            120,
        );
        $this->assertSame(200, $status, $body);

        // The key must land in pi's rendered provider config ...
        $models = $this->decodeJson((string) file_get_contents($piDir . '/models.json'));
        $this->assertSame('test-secret-123', $models['providers']['caption-model']['apiKey']);
        // ... and in the Authorization header of the actual LLM request.
        $first = $this->decodeJson((string) file_get_contents($recordDir . '/phase-first.json'));
        $this->assertSame('Bearer test-secret-123', $first['headers']['authorization'] ?? null);
    }

    // ---------------------------------------------------------------------
    // HTTP error handling
    // ---------------------------------------------------------------------

    public function testMissingTextReturns400(): void
    {
        $port = $this->ensureCaptionServer();
        [$status, $body] = $this->httpRequest(
            'POST',
            "http://127.0.0.1:{$port}/txt_lyrics2caption",
            json_encode(['lyrics' => 'x'], JSON_THROW_ON_ERROR),
            10,
        );
        $this->assertSame(400, $status);
        $this->assertStringContainsString('text', $this->decodeJson($body)['error']);
    }

    public function testInvalidJsonReturns400(): void
    {
        $port = $this->ensureCaptionServer();
        [$status, $body] = $this->httpRequest('POST', "http://127.0.0.1:{$port}/txt_lyrics2caption", 'not json', 10);
        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $this->decodeJson($body));
    }

    public function testUnknownRouteReturns404(): void
    {
        $port = $this->ensureCaptionServer();
        [$status] = $this->httpRequest('GET', "http://127.0.0.1:{$port}/nope", null, 10);
        $this->assertSame(404, $status);
    }

    // ---------------------------------------------------------------------
    // pi / model failure paths
    // ---------------------------------------------------------------------

    public function testUnreachableModelReturns502(): void
    {
        // A port that was free a moment ago and nothing is listening on: the LLM
        // endpoint will be unreachable for pi.
        $deadPort = $this->freePort();
        $port = $this->startServer("http://127.0.0.1:{$deadPort}/v1", timeoutS: 60);

        [$status, $body] = $this->httpRequest(
            'POST',
            "http://127.0.0.1:{$port}/txt_lyrics2caption",
            json_encode(['text' => 'test'], JSON_THROW_ON_ERROR),
            90,
        );
        $this->assertSame(502, $status, $body);
        $this->assertNotSame('', (string) $this->decodeJson($body)['error']);
    }

    public function testHangingModelTimesOutWith504(): void
    {
        $llmPort = $this->freePort();
        $this->startNodeProcess($this->hangingLlmCode(), [$llmPort]);
        if (!$this->waitForPort("127.0.0.1:{$llmPort}", 5)) {
            $this->markTestSkipped("Could not start hanging mock LLM on port $llmPort");
        }

        $port = $this->startServer("http://127.0.0.1:{$llmPort}/v1", timeoutS: 5);

        [$status, $body] = $this->httpRequest(
            'POST',
            "http://127.0.0.1:{$port}/txt_lyrics2caption",
            json_encode(['text' => 'test'], JSON_THROW_ON_ERROR),
            45,
        );
        $this->assertSame(504, $status, $body);
        $this->assertStringContainsString('timed out', $this->decodeJson($body)['error']);
    }

    public function testMissingModelEnvExitsNonZero(): void
    {
        $log = $this->tempDir . '/server-missing-env.log';
        $env = array_filter(
            getenv() ?: [],
            static fn (string $name): bool => !str_starts_with($name, 'CAPTION_MODEL_'),
            ARRAY_FILTER_USE_KEY,
        );
        $proc = proc_open(
            ['node', self::SERVER_PATH, '--host', '127.0.0.1', '--port', (string) $this->freePort()],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $env,
        );
        $this->processes[] = $proc;

        $deadline = microtime(true) + 15;
        $status = ['running' => true];
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        $this->assertFalse($status['running'], 'server should exit when CAPTION_MODEL_* env is missing');
        $this->assertNotSame(0, $status['exitcode']);
        $this->assertStringContainsString('CAPTION_MODEL_URL', (string) file_get_contents($log));
    }

    // ---------------------------------------------------------------------
    // Helpers: server + mock LLM startup
    // ---------------------------------------------------------------------

    /** Path of the stub skill inside the shared pi dir; writes it on first use. */
    private function skillPath(): string
    {
        $skillPath = $this->tempDir . '/pi-agent/skills/music-caption-rewriter/SKILL.md';
        if (!is_file($skillPath)) {
            @mkdir(dirname($skillPath), 0o775, true);
            $skillMarker = self::STUB_SKILL_MARKER;
            file_put_contents($skillPath, <<<EOT
                ---
                name: music-caption-rewriter
                description: Turn a brief music description and optional tagged lyrics into a structured caption.
                ---

                # Music Caption Rewriter

                {$skillMarker}
                Read the genre router, pick references, then write the caption.
                EOT);
        }

        return $skillPath;
    }

    /**
     * Lazily starts one shared server instance (mock LLM + stub skill) for the
     * happy-path and HTTP-error tests; returns its port.
     */
    private function ensureCaptionServer(): int
    {
        if ($this->captionServerPort !== null) {
            return $this->captionServerPort;
        }

        $llmPort = $this->freePort();
        $this->startNodeProcess($this->mockLlmCode(), [$llmPort, $this->skillPath(), $this->tempDir . '/llm-records']);

        $this->captionServerPort = $this->startServer(
            "http://127.0.0.1:{$llmPort}/v1",
            piDir: $this->tempDir . '/pi-agent',
        );
        $this->captionServerLog = $this->tempDir . "/server-{$this->captionServerPort}.log";

        return $this->captionServerPort;
    }

    /**
     * Starts main.js against the given model url (CAPTION_MODEL_* env vars),
     * with a per-instance log file. @return int the port the server listens on
     *
     * @param array<string, string> $extraEnv
     */
    private function startServer(string $modelUrl, int $timeoutS = 600, ?string $piDir = null, array $extraEnv = []): int
    {
        $piDir ??= $this->tempDir . '/pi-agent';
        @mkdir($piDir . '/skills', 0o775, true);
        $port = $this->freePort();
        $log = $this->tempDir . "/server-{$port}.log";
        $env = getenv() ?: [];
        $env['PI_CODING_AGENT_DIR'] = $piDir;
        $env['CAPTION_MODEL_URL'] = $modelUrl;
        $env['CAPTION_MODEL_ID'] = 'mock-caption-model';
        foreach ($extraEnv as $name => $value) {
            $env[$name] = $value;
        }
        $proc = proc_open(
            [
                'node', self::SERVER_PATH,
                '--host', '127.0.0.1',
                '--port', (string) $port,
                '--timeout', (string) $timeoutS,
            ],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $env,
        );
        $this->processes[] = $proc;
        if (!$this->waitForPort("127.0.0.1:{$port}", 10)) {
            $this->fail('server did not start; log: ' . substr((string) file_get_contents($log), -2000));
        }

        return $port;
    }

    /** Starts a Node script written to the temp dir, passing $args after the script path. */
    private function startNodeProcess(string $code, array $args): void
    {
        static $counter = 0;
        $script = $this->tempDir . '/mock-' . (++$counter) . '.js';
        file_put_contents($script, $code);
        $log = $script . '.log';
        $proc = proc_open(
            ['node', $script, ...array_map(strval(...), $args)],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        $this->processes[] = $proc;
    }

    /**
     * Mock OpenAI-compatible chat completions server. Drives pi through exactly one
     * tool call (read of the stub SKILL.md) before answering with the fixed caption,
     * recording the first and final request payloads (with headers) for assertions.
     */
    private function mockLlmCode(): string
    {
        return <<<'EOT'
            const http = require('http');
            const fs = require('fs');
            const port = parseInt(process.argv[2], 10);
            const skillPath = process.argv[3];
            const recordDir = process.argv[4];
            fs.mkdirSync(recordDir, { recursive: true });
            const CAPTION = '### Global Metadata\nIntegration-test caption: dark synthwave, 100-110 BPM, analog warmth.\n\n### Vocal Details\nNone (instrumental).\n\n### Arrangement\nIntro -> Drop -> Outro.';

            http.createServer((req, res) => {
                let body = '';
                req.on('data', (c) => (body += c));
                req.on('end', () => {
                    if (req.url !== '/v1/chat/completions') { res.writeHead(404); res.end('{}'); return; }
                    const payload = JSON.parse(body);
                    const hasToolResult = payload.messages.some((m) => m.role === 'tool');
                    fs.writeFileSync(recordDir + '/' + (hasToolResult ? 'phase-final.json' : 'phase-first.json'),
                        JSON.stringify({ headers: req.headers, body: payload }));

                    let toolCalls = null;
                    let text = null;
                    if (!hasToolResult) {
                        toolCalls = [{
                            id: 'call_1',
                            type: 'function',
                            function: { name: 'read', arguments: JSON.stringify({ path: skillPath, limit: 40 }) },
                        }];
                    } else {
                        text = CAPTION;
                    }

                    if (!payload.stream) {
                        const message = toolCalls
                            ? { role: 'assistant', content: null, tool_calls: toolCalls }
                            : { role: 'assistant', content: text };
                        res.writeHead(200, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({ choices: [{ message, finish_reason: toolCalls ? 'tool_calls' : 'stop' }] }));
                        return;
                    }
                    res.writeHead(200, { 'Content-Type': 'text/event-stream' });
                    const send = (obj) => res.write('data: ' + JSON.stringify(obj) + '\n\n');
                    send({ choices: [{ delta: { role: 'assistant' } }] });
                    if (toolCalls) {
                        for (const tc of toolCalls) {
                            send({ choices: [{ delta: { tool_calls: [{ index: 0, id: tc.id, type: 'function', function: tc.function }] } }] });
                        }
                        send({ choices: [{ delta: {}, finish_reason: 'tool_calls' }] });
                    } else {
                        send({ choices: [{ delta: { content: text } }] });
                        send({ choices: [{ delta: {}, finish_reason: 'stop' }], usage: { prompt_tokens: 10, completion_tokens: 10 } });
                    }
                    res.write('data: [DONE]\n\n');
                    res.end();
                });
            }).listen(port, '127.0.0.1');
            EOT;
    }

    /** Mock LLM that accepts requests and never responds, to trigger the pi run timeout. */
    private function hangingLlmCode(): string
    {
        return <<<'EOT'
            const http = require('http');
            // hanging by design: integration test for the timeout path
            http.createServer(() => {}).listen(parseInt(process.argv[2], 10), '127.0.0.1');
            EOT;
    }

    // ---------------------------------------------------------------------
    // Helpers: HTTP, ports, misc
    // ---------------------------------------------------------------------

    /**
     * Minimal HTTP request using the stream wrapper (avoids pulling in Guzzle at runtime).
     *
     * @return array{0: int, 1: string}
     */
    private function httpRequest(string $method, string $url, ?string $body, float $timeoutSec): array
    {
        $headerLines = ['Content-Type: application/json'];
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $rawHeaders[0] ?? '', $m)) {
            $status = (int) $m[1];
        }

        return [$status, is_string($response) ? $response : ''];
    }

    /** Concatenated text of every message in a recorded LLM request payload (string
     *  content, text parts, tool results). @param array<string, mixed> $payload */
    private function messageText(array $payload): string
    {
        $text = '';
        foreach ($payload['messages'] ?? [] as $message) {
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $text .= "\n" . $content;
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $text .= "\n" . $part['text'];
                    }
                }
            }
        }

        return $text;
    }

    /** @param array<string, mixed> $payload */
    private function hasMessageWithRole(array $payload, string $role): bool
    {
        foreach ($payload['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) === $role) {
                return true;
            }
        }

        return false;
    }

    private function waitForPort(string $addr, float $timeoutSec): bool
    {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $parts = explode(':', $addr);
            $fp = @fsockopen($parts[0], (int) $parts[1], $errno, $errstr, 0.5);
            if ($fp !== false) {
                fclose($fp);

                return true;
            }
            usleep(100000);
        }

        return false;
    }

    private function freePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return 9000 + random_int(0, 999);
        }
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function serverLogTail(): string
    {
        if ($this->captionServerLog === '' || !is_file($this->captionServerLog)) {
            return '';
        }

        return "\n--- server log tail ---\n" . substr((string) file_get_contents($this->captionServerLog), -2000);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
