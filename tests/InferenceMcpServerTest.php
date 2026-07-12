<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the Viktor89 inference MCP server (inference-servers/mcp/server.php).
 *
 * Covers: pure helper logic, the HTTP forwarding/response translation against a
 * mock inference server, and both transports (stdio via proc_open, Streamable
 * HTTP via createHttpResponse() and a live php -S router).
 */
class InferenceMcpServerTest extends TestCase
{
    private const SERVER_PATH = __DIR__ . '/../inference-servers/mcp/server.php';
    private const CONFIG_PATH = __DIR__ . '/../inference-servers/mcp/mcp-config.example.json';

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var list<resource> */
    private array $processes = [];
    private $mockProc = null;
    private string $mockUrl = '';
    private string $sessionDir = '';
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerOnce();
        $this->tempDir = sys_get_temp_dir() . '/viktor89-mcp-test-' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0o775, true);
        $this->sessionDir = $this->tempDir . '/sessions';
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
        $this->stopMockServer();
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function requireServerOnce(): void
    {
        if (!function_exists('buildMcpServer')) {
            require_once self::SERVER_PATH;
        }
    }

    // ---------------------------------------------------------------------
    // Unit tests: pure helpers
    // ---------------------------------------------------------------------

    public function testParameterDefinitionsContainsCoreParams(): void
    {
        $defs = parameterDefinitions();
        foreach (['prompt', 'negative_prompt', 'seed', 'width', 'height',
                     'cfg_scale', 'num_frames', 'frames', 'init_image', 'language', 'speaker_id',
                     'source_voice', 'source_voice_format', 'speed', 'loras'] as $name) {
            $this->assertArrayHasKey($name, $defs, "Missing parameter definition: $name");
        }
        // 'model' and 'steps' are no longer user-configurable: always use the configured defaults.
        $this->assertArrayNotHasKey('model', $defs);
        $this->assertArrayNotHasKey('steps', $defs);
        $this->assertSame('integer', $defs['seed']['type']);
        $this->assertSame('number', $defs['speed']['type']);
        $this->assertSame('boolean', $defs['enhance_prompt']['type'] ?? null);
        $this->assertSame(['wav', 'ogg', 'mp3'], $defs['source_voice_format']['enum']);
        $this->assertArrayHasKey('items', $defs['loras']);
    }

    public function testDefaultRequiredParameters(): void
    {
        $this->assertSame(['prompt'], defaultRequiredParameters('txt2img'));
        $this->assertSame(['prompt'], defaultRequiredParameters('txt2vid'));
        $this->assertSame(['prompt', 'init_image'], defaultRequiredParameters('img2img'));
        $this->assertSame(['prompt', 'init_image'], defaultRequiredParameters('img2vid'));
        $this->assertSame(['prompt', 'language'], defaultRequiredParameters('txt2voice'));
    }

    public function testBuildInputSchemaForTxt2img(): void
    {
        $tool = [
            'parameters' => ['prompt', 'seed', 'width', 'height'],
        ];
        $schema = buildInputSchema($tool, 'txt2img');
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('prompt', $schema['properties']);
        $this->assertSame('string', $schema['properties']['prompt']['type']);
        $this->assertSame(['prompt'], $schema['required']);
    }

    public function testBuildInputSchemaForImg2imgRequiresInitImageByDefault(): void
    {
        $tool = ['parameters' => ['prompt', 'init_image']];
        $schema = buildInputSchema($tool, 'img2img');
        $this->assertContains('init_image', $schema['required']);
    }

    public function testBuildInputSchemaUsesExplicitRequiredOverride(): void
    {
        $tool = [
            'parameters' => ['prompt', 'source_voice', 'source_voice_format'],
            'required' => ['prompt', 'source_voice', 'source_voice_format'],
        ];
        $schema = buildInputSchema($tool, 'txt2voice');
        $this->assertSame(['prompt', 'source_voice', 'source_voice_format'], $schema['required']);
        $this->assertSame(['wav', 'ogg', 'mp3'], $schema['properties']['source_voice_format']['enum']);
    }

    public function testBuildInputSchemaAppliesSizeConstraints(): void
    {
        $tool = [
            'parameters' => ['prompt', 'width', 'height'],
            'sizeConstraints' => [
                'width' => ['min' => 256, 'max' => 2048, 'multipleOf' => 16],
                'height' => ['min' => 256, 'max' => 2048, 'multipleOf' => 16],
            ],
        ];
        $schema = buildInputSchema($tool, 'txt2img');
        $this->assertSame(256, $schema['properties']['width']['minimum']);
        $this->assertSame(2048, $schema['properties']['width']['maximum']);
        $this->assertSame(16, $schema['properties']['width']['multipleOf']);
        $this->assertSame(256, $schema['properties']['height']['minimum']);
    }

    public function testBuildInputSchemaOmitsSizeConstraintsWhenAbsent(): void
    {
        $tool = ['parameters' => ['prompt', 'width', 'height']];
        $schema = buildInputSchema($tool, 'txt2img');
        $this->assertArrayNotHasKey('minimum', $schema['properties']['width']);
        $this->assertArrayNotHasKey('maximum', $schema['properties']['height']);
    }

    public function testValidateSizeConstraintsReturnsNullWhenValid(): void
    {
        $tool = ['sizeConstraints' => [
            'width' => ['min' => 256, 'max' => 2048, 'multipleOf' => 16],
            'height' => ['min' => 256, 'max' => 2048, 'multipleOf' => 16],
        ]];
        $this->assertNull(validateSizeConstraints($tool, ['width' => 1024, 'height' => 1024]));
    }

    public function testValidateSizeConstraintsRejectsTooSmall(): void
    {
        $tool = ['sizeConstraints' => [
            'width' => ['min' => 256, 'max' => 2048, 'multipleOf' => 16],
            'height' => ['min' => 256, 'max' => 2048, 'multipleOf' => 16],
        ]];
        $err = validateSizeConstraints($tool, ['width' => 128, 'height' => 1024]);
        $this->assertNotNull($err);
        $this->assertStringContainsString('at least 256px', $err);
    }

    public function testValidateSizeConstraintsRejectsTooLarge(): void
    {
        $tool = ['sizeConstraints' => [
            'width' => ['min' => 128, 'max' => 1024, 'multipleOf' => 8],
            'height' => ['min' => 128, 'max' => 1024, 'multipleOf' => 8],
        ]];
        $err = validateSizeConstraints($tool, ['width' => 1024, 'height' => 2048]);
        $this->assertNotNull($err);
        $this->assertStringContainsString('at most 1024px', $err);
    }

    public function testValidateSizeConstraintsRejectsNonMultiple(): void
    {
        $tool = ['sizeConstraints' => [
            'width' => ['min' => 64, 'max' => 1280, 'multipleOf' => 64],
            'height' => ['min' => 64, 'max' => 1280, 'multipleOf' => 64],
        ]];
        $err = validateSizeConstraints($tool, ['width' => 768, 'height' => 500]);
        $this->assertNotNull($err);
        $this->assertStringContainsString('multiple of 64', $err);
    }

    public function testValidateSizeConstraintsSkipsWhenNoConstraints(): void
    {
        $this->assertNull(validateSizeConstraints([], ['width' => 99, 'height' => 99]));
        $this->assertNull(validateSizeConstraints(['sizeConstraints' => []], ['width' => 99]));
    }

    public function testExecuteInferenceRejectsInvalidSizeBeforeRequest(): void
    {
        [$tool, $endpoint] = $this->toolAndEndpoint('generate_video_model_a', $this->ensureMockServer());
        // LTX-2 requires multiples of 64; 500 is not a valid height.
        $result = executeInference($tool, $endpoint, ['prompt' => 'x', 'width' => 768, 'height' => 500], $this->httpClient());
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('multiple of 64', $result->content[0]->text);
    }

    public function testConfigAdvertisesNoStepsOrModelParameter(): void
    {
        $config = json_decode((string) file_get_contents(self::CONFIG_PATH), true, 512, JSON_THROW_ON_ERROR);
        foreach ($config['models'] as $model) {
            $params = $model['parameters'] ?? [];
            $this->assertNotContains('steps', $params, "Tool {$model['tool']} advertises 'steps'");
            $this->assertNotContains('model', $params, "Tool {$model['tool']} advertises 'model'");
        }
    }

    public function testConfigImageModelsHaveSizeConstraints(): void
    {
        $config = json_decode((string) file_get_contents(self::CONFIG_PATH), true, 512, JSON_THROW_ON_ERROR);
        foreach ($config['models'] as $model) {
            $params = $model['parameters'] ?? [];
            if (!in_array('width', $params, true) && !in_array('height', $params, true)) {
                continue;
            }
            $this->assertArrayHasKey('sizeConstraints', $model, "Tool {$model['tool']} exposes width/height but has no sizeConstraints");
        }
    }

    public function testBuildToolDescriptionMentionsBase64AndMime(): void
    {
        $endpoint = ['response' => ['media' => 'image', 'mimeType' => 'image/png']];
        $tool = ['description' => 'Generate an image.'];
        $desc = buildToolDescription($tool, $endpoint);
        $this->assertStringContainsString('base64-encoded', $desc);
        $this->assertStringContainsString('image/png', $desc);
        $this->assertStringContainsString('image content', $desc);
    }

    public function testBuildToolDescriptionForVideoAndAudio(): void
    {
        $video = buildToolDescription(['description' => 'v'], ['response' => ['media' => 'video', 'mimeType' => 'video/mp4']]);
        $this->assertStringContainsString('embedded resource', $video);
        $this->assertStringContainsString('video/mp4', $video);

        $audio = buildToolDescription(['description' => 'a'], ['response' => ['media' => 'audio', 'mimeType' => 'audio/ogg']]);
        $this->assertStringContainsString('audio content', $audio);
        $this->assertStringContainsString('audio/ogg', $audio);
    }

    public function testExtractCaptionFromJsonStringInfo(): void
    {
        $shape = ['infoField' => 'info', 'infoIsJsonString' => true, 'captionField' => 'infotexts'];
        $decoded = ['info' => json_encode(['infotexts' => ['a red cat, steps=20']])];
        $this->assertSame('a red cat, steps=20', extractCaption($shape, $decoded));
    }

    public function testExtractCaptionFromVoiceObjectInfo(): void
    {
        $shape = ['infoField' => 'info', 'infoIsJsonString' => false, 'captionField' => null];
        $decoded = ['info' => ['prompt' => 'hello world', 'model' => 'xtts']];
        $this->assertSame('hello world', extractCaption($shape, $decoded));
    }

    public function testExtractCaptionReturnsNullWhenMissing(): void
    {
        $shape = ['infoField' => 'info', 'infoIsJsonString' => true, 'captionField' => 'infotexts'];
        $this->assertNull(extractCaption($shape, ['info' => null]));
        $this->assertNull(extractCaption(['infoField' => null], ['info' => 'x']));
    }

    public function testBuildSuccessResultForImageReturnsImageContentAndBase64Note(): void
    {
        $tool = ['tool' => 'generate_image_model_a'];
        $shape = ['media' => 'image', 'mimeType' => 'image/png'];
        $result = buildSuccessResult($tool, $shape, self::PNG_BASE64, 'a red cat');
        $this->assertFalse($result->isError);
        $this->assertCount(2, $result->content);
        $image = $result->content[0];
        $this->assertSame('image', $image->type);
        $this->assertSame(self::PNG_BASE64, $image->data);
        $this->assertSame('image/png', $image->mimeType);
        $this->assertStringContainsString('base64-encoded image string', $result->content[1]->text);
        $this->assertStringContainsString('a red cat', $result->content[1]->text);
    }

    public function testBuildSuccessResultForAudioReturnsAudioContent(): void
    {
        $shape = ['media' => 'audio', 'mimeType' => 'audio/ogg'];
        $result = buildSuccessResult(['tool' => 't'], $shape, 'AAAA', null);
        $this->assertSame('audio', $result->content[0]->type);
        $this->assertSame('audio/ogg', $result->content[0]->mimeType);
        $this->assertStringContainsString('base64-encoded audio string', $result->content[1]->text);
    }

    public function testBuildSuccessResultForVideoReturnsEmbeddedBlob(): void
    {
        $shape = ['media' => 'video', 'mimeType' => 'video/mp4'];
        $result = buildSuccessResult(['tool' => 'generate_video_model_a'], $shape, 'AAAA', 'a vid');
        $resource = $result->content[0];
        $this->assertSame('resource', $resource->type);
        $this->assertSame('video/mp4', $resource->resource->mimeType);
        $this->assertSame('AAAA', $resource->resource->blob);
        $this->assertStringContainsString('resource://generate_video_model_a/output', $resource->resource->uri);
        $this->assertStringContainsString('base64-encoded video string', $result->content[1]->text);
    }

    public function testLoadConfigThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        loadConfig('/nonexistent/mcp-config-' . bin2hex(random_bytes(4)) . '.json');
    }

    public function testParseServerArgs(): void
    {
        $this->assertSame([null, false, '127.0.0.1:8080'], parseServerArgs([]));
        $this->assertSame(['/path/c.json', true, '127.0.0.1:8080'], parseServerArgs(['--http', '/path/c.json']));
        $this->assertSame(['/c.json', true, '0.0.0.0:9000'], parseServerArgs(['--http', '--bind=0.0.0.0:9000', '/c.json']));
        $this->assertSame([null, true, '127.0.0.1:9090'], parseServerArgs(['--http', '--port=9090']));
    }

    // ---------------------------------------------------------------------
    // Integration: executeInference() against a mock inference server
    // ---------------------------------------------------------------------

    public function testExecuteInferenceImage(): void
    {
        [$tool, $endpoint] = $this->toolAndEndpoint('generate_image_model_a', $this->ensureMockServer());
        $result = executeInference($tool, $endpoint, ['prompt' => 'a red cat'], $this->httpClient());
        $this->assertFalse($result->isError);
        $this->assertSame('image', $result->content[0]->type);
        $this->assertSame(self::PNG_BASE64, $result->content[0]->data);
        $this->assertStringContainsString('base64-encoded image', $result->content[1]->text);
    }

    public function testExecuteInferenceVideo(): void
    {
        [$tool, $endpoint] = $this->toolAndEndpoint('generate_video_model_a', $this->ensureMockServer());
        $result = executeInference($tool, $endpoint, ['prompt' => 'a running cat'], $this->httpClient());
        $this->assertFalse($result->isError);
        $this->assertSame('resource', $result->content[0]->type);
        $this->assertSame('video/mp4', $result->content[0]->resource->mimeType);
    }

    public function testExecuteInferenceVoice(): void
    {
        [$tool, $endpoint] = $this->toolAndEndpoint('generate_voice_model_a', $this->ensureMockServer());
        $result = executeInference($tool, $endpoint, ['prompt' => 'hello', 'language' => 'en'], $this->httpClient());
        $this->assertFalse($result->isError);
        $this->assertSame('audio', $result->content[0]->type);
        $this->assertSame('audio/ogg', $result->content[0]->mimeType);
        $this->assertStringContainsString('hello', $result->content[1]->text);
    }

    public function testExecuteInferenceErrorReturnsIsError(): void
    {
        [$tool, $endpoint] = $this->toolAndEndpoint('generate_image_model_a', $this->ensureMockServer());
        $result = executeInference($tool, $endpoint, ['prompt' => 'FAIL'], $this->httpClient());
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('mock failure requested', $result->content[0]->text);
    }

    public function testExecuteInferenceConnectionError(): void
    {
        // Point at a port with nothing listening.
        [$tool, $endpoint] = $this->toolAndEndpoint('generate_image_model_a', 'http://127.0.0.1:' . $this->freePort());
        $result = executeInference($tool, $endpoint, ['prompt' => 'x'], $this->httpClient());
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('HTTP request to', $result->content[0]->text);
    }

    public function testExecuteInferenceInitImageRemappedToInitImages(): void
    {
        [$tool, $endpoint] = $this->toolAndEndpoint('edit_image_model_a', $this->ensureMockServer());
        $result = executeInference(
            $tool,
            $endpoint,
            ['prompt' => 'make it neon', 'init_image' => 'QkFTRTY0', 'seed' => 7],
            $this->httpClient(),
        );
        $this->assertFalse($result->isError);
        // The mock echoes has_init_image / init_images_count in the caption.
        $note = $result->content[1]->text;
        $this->assertStringContainsString('has_init_image=0', $note);
        $this->assertStringContainsString('init_images_count=1', $note);
    }

    // ---------------------------------------------------------------------
    // Transport: stdio (proc_open)
    // ---------------------------------------------------------------------

    public function testStdioTransportInitializeAndToolsList(): void
    {
        $config = $this->configForUrl($this->ensureMockServer());
        $responses = $this->runStdio($config, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $this->initializeParams()],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()],
        ]);

        $init = $this->findById($responses, 1);
        $this->assertNotNull($init);
        $this->assertSame('viktor89-inference', $init['result']['serverInfo']['name']);

        // The SDK paginates tools/list (50 per page); follow the cursor to collect all.
        $toolNames = [];
        $cursor = null;
        $listId = 2;
        do {
            $params = $cursor !== null ? ['cursor' => $cursor] : new \stdClass();
            $paged = $this->runStdio($config, [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $this->initializeParams()],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => $listId, 'method' => 'tools/list', 'params' => $params],
            ]);
            $page = $this->findById($paged, $listId);
            $this->assertNotNull($page, "tools/list page with cursor={$cursor}");
            foreach (array_column($page['result']['tools'], 'name') as $name) {
                $toolNames[] = $name;
            }
            $cursor = $page['result']['nextCursor'] ?? null;
            $listId++;
        } while ($cursor !== null);

        $this->assertContains('generate_image_model_a', $toolNames);
        $this->assertContains('generate_voice_model_a', $toolNames);
    }

    public function testStdioTransportToolCallReturnsImage(): void
    {
        $config = $this->configForUrl($this->ensureMockServer());
        $responses = $this->runStdio($config, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $this->initializeParams()],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'generate_image_model_a',
                'arguments' => ['prompt' => 'a red cat'],
            ]],
        ]);

        $call = $this->findById($responses, 2);
        $this->assertNotNull($call);
        $this->assertFalse($call['result']['isError']);
        $types = array_column($call['result']['content'], 'type');
        $this->assertContains('image', $types);
        $this->assertContains('text', $types);
        $this->assertSame(self::PNG_BASE64, $call['result']['content'][0]['data']);
    }

    // ---------------------------------------------------------------------
    // Transport: Streamable HTTP (in-process via createHttpResponse)
    // ---------------------------------------------------------------------

    public function testHttpTransportInitializeListAndCall(): void
    {
        $config = $this->configForUrl($this->ensureMockServer(), $this->sessionDir);
        $logger = new NullLogger();

        // 1) initialize -> must return a session id header
        $initResp = createHttpResponse($config, $this->postRequest($this->initializeRequest()), $logger);
        $this->assertSame(200, $initResp->getStatusCode());
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId, 'initialize must return a Mcp-Session-Id header');

        // 2) tools/list with the session id
        $listResp = createHttpResponse($config, $this->postRequest(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()],
            $sessionId,
        ), $logger);
        $this->assertSame(200, $listResp->getStatusCode());
        $listBody = json_decode((string) $listResp->getBody(), true);
        $this->assertGreaterThan(0, count($listBody['result']['tools'] ?? []));

        // 3) tools/call with the session id -> image content
        $callResp = createHttpResponse($config, $this->postRequest(
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'generate_image_model_a',
                'arguments' => ['prompt' => 'a red cat'],
            ]],
            $sessionId,
        ), $logger);
        $this->assertSame(200, $callResp->getStatusCode());
        $callBody = json_decode((string) $callResp->getBody(), true);
        $this->assertFalse($callBody['result']['isError']);
        $this->assertSame('image', $callBody['result']['content'][0]['type']);
        $this->assertSame(self::PNG_BASE64, $callBody['result']['content'][0]['data']);
    }

    public function testHttpRequiresSessionForNonInitializeRequests(): void
    {
        $config = $this->configForUrl($this->ensureMockServer(), $this->sessionDir);
        $resp = createHttpResponse($config, $this->postRequest(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass()],
        ), new NullLogger());
        $this->assertSame(400, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        $this->assertSame(-32600, $body['error']['code']);
        $this->assertStringContainsString('session id is REQUIRED', $body['error']['message']);
    }

    public function testHttpOptionsRequestReturns204(): void
    {
        $config = $this->configForUrl($this->ensureMockServer(), $this->sessionDir);
        $request = new \GuzzleHttp\Psr7\ServerRequest('OPTIONS', '/mcp');
        $resp = createHttpResponse($config, $request, new NullLogger());
        $this->assertSame(204, $resp->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // Transport: live php -S HTTP server (router entry + SAPI emission)
    // ---------------------------------------------------------------------

    public function testLiveHttpServerServesRequests(): void
    {
        $mockUrl = $this->ensureMockServer();
        $config = $this->configForUrl($mockUrl, $this->sessionDir);
        $cfgPath = $this->writeConfigFile($config);
        $port = $this->freePort();
        $routerLog = $this->tempDir . '/router.log';

        $env = getenv() ?: [];
        $env['MCP_CONFIG_PATH'] = $cfgPath;
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['file', $routerLog, 'w'],
            2 => ['file', $routerLog, 'a'],
        ];
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", self::SERVER_PATH], $desc, $pipes, null, $env);
        $this->processes[] = $proc;
        fclose($pipes[0]);

        if (!$this->waitForPort("127.0.0.1:{$port}", 5)) {
            $this->markTestSkipped("Could not start live HTTP server on port $port");
        }

        $base = "http://127.0.0.1:{$port}/mcp";

        [$initStatus, $initBody, $initHeaders] = $this->httpPostLive($base, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($this->initializeRequest(), JSON_THROW_ON_ERROR));
        $this->assertSame(200, $initStatus);
        $sessionId = $initHeaders['Mcp-Session-Id'] ?? '';
        $this->assertNotEmpty($sessionId, 'initialize must return a Mcp-Session-Id header');

        [$listStatus, $listBody, $listHeaders] = $this->httpPostLive($base, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Mcp-Session-Id' => $sessionId,
        ], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()], JSON_THROW_ON_ERROR));
        $this->assertSame(200, $listStatus);
        $list = json_decode($listBody, true);
        $this->assertGreaterThan(0, count($list['result']['tools'] ?? []));
    }

    /**
     * Minimal HTTP POST using the stream wrapper (avoids pulling in Guzzle at runtime).
     *
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function httpPostLive(string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => 5.0,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);

        // PHP 8.4+ deprecated the auto-local $http_response_header in favor of
        // http_get_last_response_headers(); use it when available.
        if (function_exists('http_get_last_response_headers')) {
            $rawHeaders = http_get_last_response_headers() ?? [];
            if (function_exists('http_clear_last_response_headers')) {
                http_clear_last_response_headers();
            }
        } else {
            $rawHeaders = $http_response_header ?? [];
        }

        $status = 0;
        $responseHeaders = [];
        if (is_array($rawHeaders)) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $rawHeaders[0] ?? '', $m)) {
                $status = (int) $m[1];
            }
            foreach ($rawHeaders as $line) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
                }
            }
        }

        return [$status, is_string($response) ? $response : '', $responseHeaders];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} */
    private function toolAndEndpoint(string $toolName, string $url): array
    {
        $config = $this->configForUrl($url);
        foreach ($config['models'] as $model) {
            if (($model['tool'] ?? null) === $toolName) {
                $model['url'] = $url;
                $endpoint = $config['endpoints'][$model['endpoint']];

                return [$model, $endpoint];
            }
        }
        self::fail("Tool '{$toolName}' not found in config");
    }

    /** @return array<string, mixed> */
    private function configForUrl(string $url, ?string $sessionDir = null): array
    {
        $config = json_decode((string) file_get_contents(self::CONFIG_PATH), true, 512, JSON_THROW_ON_ERROR);
        foreach ($config['models'] as &$model) {
            $model['url'] = $url;
        }
        unset($model);
        $config['http']['timeoutSeconds'] = 5;
        $config['http']['connectTimeoutSeconds'] = 3;
        if ($sessionDir !== null) {
            $config['http']['sessionDir'] = $sessionDir;
        }

        return $config;
    }

    private function writeConfigFile(array $config): string
    {
        $path = $this->tempDir . '/mcp-config.json';
        file_put_contents($path, json_encode($config, JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function httpClient(): \InferenceHttpClient
    {
        return new \InferenceHttpClient(5, 3);
    }

    private function initializeParams(): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
        ];
    }

    private function initializeRequest(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $this->initializeParams()];
    }

    private function postRequest(array $payload, ?string $sessionId = null): \GuzzleHttp\Psr7\ServerRequest
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($sessionId !== null) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        return new \GuzzleHttp\Psr7\ServerRequest('POST', '/mcp', $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Run the server over stdio, send the given JSON-RPC messages, return decoded responses.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function runStdio(array $config, array $messages): array
    {
        $cfgPath = $this->writeConfigFile($config);
        $stderrLog = $this->tempDir . '/stdio-stderr.log';
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrLog, 'w'],
        ];
        $proc = proc_open([PHP_BINARY, self::SERVER_PATH, $cfgPath], $desc, $pipes);
        $this->processes[] = $proc;

        $input = '';
        foreach ($messages as $msg) {
            $input .= json_encode($msg, JSON_UNESCAPED_SLASHES) . "\n";
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $out = '';
        $deadline = microtime(true) + 10;
        stream_set_blocking($pipes[1], false);
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                if (!proc_get_status($proc)['running']) {
                    // drain any remaining buffered output
                    $tail = stream_get_contents($pipes[1]);
                    if (is_string($tail)) {
                        $out .= $tail;
                    }
                    break;
                }
                usleep(50000);
                continue;
            }
            $out .= $chunk;
        }
        fclose($pipes[1]);

        return $this->parseJsonLines($out);
    }

    /** @return array<int, array<string, mixed>> */
    private function parseJsonLines(string $out): array
    {
        $results = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $results[] = $decoded;
            }
        }

        return $results;
    }

    /** @param array<int, array<string, mixed>> $responses */
    private function findById(array $responses, int|string $id): ?array
    {
        foreach ($responses as $resp) {
            if (($resp['id'] ?? null) === $id) {
                return $resp;
            }
        }

        return null;
    }

    private function ensureMockServer(): string
    {
        if ($this->mockProc !== null) {
            return $this->mockUrl;
        }
        $port = $this->freePort();
        $router = $this->tempDir . '/mock-inference.php';
        file_put_contents($router, $this->mockRouterCode());
        $log = $this->tempDir . '/mock.log';
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'a'],
        ];
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $desc, $pipes);
        $this->processes[] = $proc;
        fclose($pipes[0]);

        if (!$this->waitForPort("127.0.0.1:{$port}", 5)) {
            $this->markTestSkipped("Could not start mock inference server on port $port");
        }
        $this->mockProc = $proc;
        $this->mockUrl = "http://127.0.0.1:{$port}";

        return $this->mockUrl;
    }

    private function stopMockServer(): void
    {
        // Actual process termination is handled via $this->processes in tearDown.
        $this->mockProc = null;
    }

    private function freePort(int $base = 9000): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return $base + random_int(0, 999);
        }
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
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

    private function mockRouterCode(): string
    {
        return <<<'PHP'
<?php
header('Content-Type: application/json');
$body = file_get_contents('php://input');
$parsed = json_decode($body, true);
$path = $_SERVER['REQUEST_URI'] ?? '/';
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

if (($parsed['prompt'] ?? '') === 'FAIL') {
    echo json_encode(['error' => 'mock failure requested']);
    return;
}

$hasInitImage = is_array($parsed) && array_key_exists('init_image', $parsed) ? 1 : 0;
$initImagesCount = is_array($parsed) ? count($parsed['init_images'] ?? []) : 0;
$meta = "has_init_image={$hasInitImage} init_images_count={$initImagesCount}";

if (str_contains($path, 'txt2img') || str_contains($path, 'img2img')) {
    echo json_encode(['images' => [$png], 'parameters' => [], 'info' => json_encode(['infotexts' => ['CAPIMG ' . $meta]])]);
} elseif (str_contains($path, 'txt2vid') || str_contains($path, 'img2vid')) {
    echo json_encode(['videos' => ['AAAA'], 'parameters' => [], 'info' => json_encode(['infotexts' => ['CAPVID ' . $meta]])]);
} elseif (str_contains($path, 'txt2voice')) {
    echo json_encode(['voice_data' => 'AAAA', 'info' => ['prompt' => $parsed['prompt'] ?? '', 'model' => 'mock']]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'unknown path ' . $path]);
}
PHP;
    }
}
