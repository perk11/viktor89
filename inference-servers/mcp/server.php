<?php

declare(strict_types=1);

/**
 * Viktor89 inference MCP server.
 *
 * A Model Context Protocol server that translates MCP tool calls into HTTP
 * requests against the project's Python inference servers (inference-servers/*)
 * and returns the generated media base64-encoded.
 *
 * It runs no inference itself and spawns no processes: it only forwards JSON to
 * the configured HTTP endpoints and converts the responses back into MCP
 * CallToolResult content (image / audio / embedded blob). Endpoint and tool
 * definitions live in mcp-config.json (one tool per configured model).
 *
 * Transports:
 *   - stdio (default):  php server.php [config]
 *   - HTTP:             php server.php --http [--bind=127.0.0.1:8080] [config]
 *                       (or use this file as a router: php -S host:port server.php)
 *
 * The bootstrap at the bottom only runs when this file is the entry script, so
 * the functions/classes above can be unit-tested by simply requiring this file.
 */

use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Built-in definitions for every parameter any endpoint understands.
 * Tools advertise a subset of these (see mcp-config.json "parameters").
 *
 * @return array<string, array{type: string, description: string, enum?: string[], items?: array<string, mixed>}>
 */
function parameterDefinitions(): array
{
    return [
        'prompt' => ['type' => 'string', 'description' => 'Text prompt describing what to generate.'],
        'negative_prompt' => ['type' => 'string', 'description' => 'Negative prompt: concepts to avoid in the output.'],
        'seed' => ['type' => 'integer', 'description' => 'Random seed. Omit or use 0 for a random seed.'],
        'steps' => ['type' => 'integer', 'description' => 'Number of inference / sampling steps.'],
        'width' => ['type' => 'integer', 'description' => 'Output width in pixels.'],
        'height' => ['type' => 'integer', 'description' => 'Output height in pixels.'],
        'model' => ['type' => 'string', 'description' => 'Model checkpoint name understood by the target server. Overrides the configured default.'],
        'cfg_scale' => ['type' => 'number', 'description' => 'Classifier-free guidance scale.'],
        'guidance_scale' => ['type' => 'number', 'description' => 'Guidance scale (alias of cfg_scale on some servers).'],
        'num_frames' => ['type' => 'integer', 'description' => 'Number of frames to generate (video).'],
        'frames' => ['type' => 'integer', 'description' => 'Number of frames to generate (video; alias used by some servers).'],
        'fps' => ['type' => 'integer', 'description' => 'Output frames per second (video).'],
        'enhance_prompt' => ['type' => 'boolean', 'description' => 'Whether the server should enhance the prompt with an LLM.'],
        'denoising_strength' => ['type' => 'number', 'description' => 'Denoising strength for img2img, 0 to 1.'],
        'loras' => ['type' => 'array', 'description' => 'List of LoRA overrides, e.g. [{"name":"file.safetensors","weight":0.8}].', 'items' => ['type' => 'object']],
        'init_image' => ['type' => 'string', 'description' => 'Base64-encoded source image (no data-URI prefix) for img2img / img2vid. The result media is also returned base64-encoded.'],
        'language' => ['type' => 'string', 'description' => 'ISO language code for TTS, e.g. "en", "ru".'],
        'speaker_id' => ['type' => 'string', 'description' => 'Built-in speaker name for TTS.'],
        'source_voice' => ['type' => 'string', 'description' => 'Base64-encoded reference voice audio for voice cloning.'],
        'source_voice_format' => ['type' => 'string', 'description' => 'Format of source_voice.', 'enum' => ['wav', 'ogg', 'mp3']],
        'source_voice_2' => ['type' => 'string', 'description' => 'Optional second base64-encoded reference voice.'],
        'speed' => ['type' => 'number', 'description' => 'Speech speed multiplier.'],
    ];
}

/**
 * Default required parameters when a model does not declare its own "required".
 *
 * @return string[]
 */
function defaultRequiredParameters(string $endpointType): array
{
    return match ($endpointType) {
        'img2img', 'img2vid' => ['prompt', 'init_image'],
        'txt2voice' => ['prompt', 'language'],
        default => ['prompt'],
    };
}

/**
 * Build the JSON Schema inputSchema for a tool from its declared parameters.
 *
 * @param array<string, mixed> $tool
 *
 * @return array{type: string, properties: array<string, mixed>, required: string[]}
 */
function buildInputSchema(array $tool, string $endpointType): array
{
    $defs = parameterDefinitions();
    $properties = [];

    foreach ($tool['parameters'] ?? [] as $param) {
        if (is_array($param)) {
            $name = $param['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $def = $defs[$name] ?? [];
            $prop = [
                'type' => $param['type'] ?? $def['type'] ?? 'string',
                'description' => $param['description'] ?? $def['description'] ?? '',
            ];
            if (isset($param['enum'])) {
                $prop['enum'] = $param['enum'];
            } elseif (isset($def['enum'])) {
                $prop['enum'] = $def['enum'];
            }
            if (isset($param['items'])) {
                $prop['items'] = $param['items'];
            } elseif (isset($def['items'])) {
                $prop['items'] = $def['items'];
            }
        } else {
            $name = $param;
            if (!is_string($name) || !isset($defs[$name])) {
                continue;
            }
            $def = $defs[$name];
            $prop = ['type' => $def['type'], 'description' => $def['description']];
            if (isset($def['enum'])) {
                $prop['enum'] = $def['enum'];
            }
            if (isset($def['items'])) {
                $prop['items'] = $def['items'];
            }
        }
        $properties[$name] = $prop;
    }

    $required = $tool['required'] ?? defaultRequiredParameters($endpointType);

    return [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
    ];
}

/**
 * Build the human-readable tool description, always noting the base64 result.
 *
 * @param array<string, mixed> $tool
 * @param array<string, mixed> $endpoint
 */
function buildToolDescription(array $tool, array $endpoint): string
{
    $media = $endpoint['response']['media'] ?? 'media';
    $mime = $endpoint['response']['mimeType'] ?? 'application/octet-stream';
    $description = $tool['description'] ?? '';

    $formatNote = match ($media) {
        'image' => 'the image is provided as MCP image content (base64 PNG).',
        'audio' => 'the audio is provided as MCP audio content (base64 data).',
        'video' => 'the video is provided as an embedded resource (base64 MP4).',
        default => 'the media is provided as a base64 string.',
    };

    return trim($description) . ' Returns the generated ' . $media
        . ' as a base64-encoded string (MIME: ' . $mime . '); ' . $formatNote
        . ' A text note in the result restates that the media is a base64 string and includes the server caption when available.';
}

/**
 * @param array<string, mixed> $tool
 * @param array<string, mixed> $endpoint
 */
function makeToolHandler(array $tool, array $endpoint, InferenceHttpClient $httpClient): Closure
{
    return function (
        ?string $prompt = null,
        ?string $negative_prompt = null,
        ?int $seed = null,
        ?int $steps = null,
        ?int $width = null,
        ?int $height = null,
        ?string $model = null,
        ?float $cfg_scale = null,
        ?float $guidance_scale = null,
        ?int $num_frames = null,
        ?int $frames = null,
        ?int $fps = null,
        ?bool $enhance_prompt = null,
        ?float $denoising_strength = null,
        ?array $loras = null,
        ?string $init_image = null,
        ?string $language = null,
        ?string $speaker_id = null,
        ?string $source_voice = null,
        ?string $source_voice_format = null,
        ?string $source_voice_2 = null,
        ?float $speed = null
    ) use ($tool, $endpoint, $httpClient): CallToolResult {
        $args = array_filter([
            'prompt' => $prompt,
            'negative_prompt' => $negative_prompt,
            'seed' => $seed,
            'steps' => $steps,
            'width' => $width,
            'height' => $height,
            'model' => $model,
            'cfg_scale' => $cfg_scale,
            'guidance_scale' => $guidance_scale,
            'num_frames' => $num_frames,
            'frames' => $frames,
            'fps' => $fps,
            'enhance_prompt' => $enhance_prompt,
            'denoising_strength' => $denoising_strength,
            'loras' => $loras,
            'init_image' => $init_image,
            'language' => $language,
            'speaker_id' => $speaker_id,
            'source_voice' => $source_voice,
            'source_voice_format' => $source_voice_format,
            'source_voice_2' => $source_voice_2,
            'speed' => $speed,
        ], static fn ($v) => $v !== null);

        return executeInference($tool, $endpoint, $args, $httpClient);
    };
}

/**
 * Translate the tool call into an HTTP request and convert the response.
 *
 * @param array<string, mixed> $tool
 * @param array<string, mixed> $endpoint
 * @param array<string, mixed> $args
 */
function executeInference(array $tool, array $endpoint, array $args, InferenceHttpClient $httpClient): CallToolResult
{
    $body = $tool['defaults'] ?? [];
    if (isset($tool['model']) && is_string($tool['model'])) {
        $body['model'] = $tool['model'];
    }

    // MCP exposes a single base64 image; the inference servers expect init_images[].
    if (isset($args['init_image'])) {
        $body['init_images'] = [$args['init_image']];
        unset($args['init_image']);
    }

    $body = array_merge($body, $args);

    $path = $endpoint['path'];
    [$status, $responseBody, $error] = $httpClient->post($tool['url'], $path, $body);

    if ($error !== '') {
        return CallToolResult::error([
            new TextContent(sprintf('HTTP request to %s%s failed: %s', $tool['url'], $path, $error)),
        ]);
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return CallToolResult::error([
            new TextContent(sprintf(
                'Inference server returned a non-JSON response (HTTP %d): %s',
                $status,
                substr((string) $responseBody, 0, 500),
            )),
        ]);
    }

    if (isset($decoded['error'])) {
        $errMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return CallToolResult::error([
            new TextContent(sprintf('Inference server error (HTTP %d): %s', $status, $errMsg)),
        ]);
    }

    if ($status >= 400) {
        return CallToolResult::error([
            new TextContent(sprintf('Inference server returned HTTP %d: %s', $status, substr((string) $responseBody, 0, 500))),
        ]);
    }

    $responseShape = $endpoint['response'];
    $mediaField = $responseShape['field'];
    $media = $decoded[$mediaField] ?? null;

    // images/videos are arrays of base64 strings; voice_data is a single base64 string.
    $base64 = null;
    if (is_array($media)) {
        $base64 = $media[0] ?? null;
    } elseif (is_string($media)) {
        $base64 = $media;
    }

    if (!is_string($base64) || $base64 === '') {
        return CallToolResult::error([
            new TextContent(sprintf("Inference server response did not contain media in field '%s' (HTTP %d).", $mediaField, $status)),
        ]);
    }

    $caption = extractCaption($responseShape, $decoded);

    return buildSuccessResult($tool, $responseShape, $base64, $caption);
}

/**
 * @param array<string, mixed> $responseShape
 * @param array<string, mixed> $decoded
 */
function extractCaption(array $responseShape, array $decoded): ?string
{
    $infoField = $responseShape['infoField'] ?? null;
    if ($infoField === null) {
        return null;
    }
    $infoRaw = $decoded[$infoField] ?? null;

    if ($responseShape['infoIsJsonString'] ?? false) {
        if (!is_string($infoRaw)) {
            return null;
        }
        $infoArr = json_decode($infoRaw, true);
        $captionField = $responseShape['captionField'] ?? null;
        if (is_array($infoArr) && $captionField !== null && isset($infoArr[$captionField])) {
            $caption = $infoArr[$captionField];
            if (is_array($caption)) {
                $caption = $caption[0] ?? null;
            }

            return is_string($caption) ? $caption : null;
        }

        return null;
    }

    // info is a plain object (e.g. txt2voice: {prompt, model, ...}).
    if (is_array($infoRaw)) {
        $prompt = $infoRaw['prompt'] ?? null;

        return is_string($prompt) ? $prompt : null;
    }

    return null;
}

/**
 * @param array<string, mixed> $tool
 * @param array<string, mixed> $responseShape
 */
function buildSuccessResult(array $tool, array $responseShape, string $base64, ?string $caption): CallToolResult
{
    $media = $responseShape['media'] ?? 'media';
    $mime = $responseShape['mimeType'] ?? 'application/octet-stream';

    $contents = [];
    switch ($media) {
        case 'image':
            $contents[] = new ImageContent($base64, $mime);
            break;
        case 'audio':
            $contents[] = new AudioContent($base64, $mime);
            break;
        case 'video':
            $contents[] = EmbeddedResource::fromBlob(
                'resource://' . ($tool['tool'] ?? 'inference') . '/output',
                $base64,
                $mime,
            );
            break;
        default:
            $contents[] = new TextContent($base64);
    }

    $note = sprintf('Result returned as a base64-encoded %s string (MIME: %s).', $media, $mime);
    if ($caption !== null && $caption !== '') {
        $note .= ' Caption: ' . $caption;
    }
    $contents[] = new TextContent($note);

    return CallToolResult::success($contents);
}

final class InferenceHttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 600,
        private readonly int $connectTimeoutSeconds = 30,
    ) {
    }

    /**
     * POST a JSON body to an inference server.
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: string, 2: string} [http_status, response_body, curl_error]
     */
    public function post(string $url, string $path, array $body): array
    {
        $fullUrl = rtrim($url, '/') . '/' . ltrim($path, '/');
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $error = '';
        if ($response === false) {
            $error = curl_error($ch);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return [$status, is_string($response) ? $response : '', $error];
    }
}

final class StderrLogger extends \Psr\Log\AbstractLogger
{
    /**
     * @param mixed $level
     * @param mixed[] $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $line = sprintf('[%s] %s', strtoupper((string) $level), $message);
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        fwrite(\STDERR, $line . "\n");
    }
}

/**
 * Load and parse the MCP config JSON, throwing on missing/invalid files.
 *
 * @return array<string, mixed>
 */
function loadConfig(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("MCP config file not found: {$path}");
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Could not read MCP config file: {$path}");
    }

    try {
        $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Failed to parse MCP config {$path}: {$e->getMessage()}", 0, $e);
    }

    if (!is_array($config)) {
        throw new RuntimeException("MCP config {$path} must be a JSON object.");
    }

    return $config;
}

/**
 * Build the configured MCP Server (one tool per model).
 *
 * @param array<string, mixed> $config
 */
function buildMcpServer(array $config, ?LoggerInterface $logger = null, ?InferenceHttpClient $httpClient = null, ?SessionStoreInterface $sessionStore = null): Server
{
    $logger ??= new StderrLogger();
    if ($httpClient === null) {
        $httpClient = new InferenceHttpClient(
            $config['http']['timeoutSeconds'] ?? 600,
            $config['http']['connectTimeoutSeconds'] ?? 30,
        );
    }

    $builder = Server::builder()
        ->setServerInfo(
            $config['server']['name'] ?? 'viktor89-inference',
            $config['server']['version'] ?? '1.0.0',
        )
        ->setInstructions($config['server']['instructions'] ?? null)
        ->setLogger($logger);

    if ($sessionStore !== null) {
        $builder->setSession($sessionStore);
    }

    foreach ($config['models'] ?? [] as $modelConfig) {
        if (!isset($modelConfig['tool'], $modelConfig['endpoint'])) {
            $logger->warning("Skipping model without 'tool'/'endpoint': " . json_encode($modelConfig));

            continue;
        }
        $endpointConfig = $config['endpoints'][$modelConfig['endpoint']] ?? null;
        if ($endpointConfig === null) {
            $logger->warning("Unknown endpoint '{$modelConfig['endpoint']}' for tool '{$modelConfig['tool']}'");

            continue;
        }

        $inputSchema = buildInputSchema($modelConfig, $modelConfig['endpoint']);
        $description = buildToolDescription($modelConfig, $endpointConfig);
        $handler = makeToolHandler($modelConfig, $endpointConfig, $httpClient);

        $builder->addTool(
            $handler,
            $modelConfig['tool'],
            $modelConfig['title'] ?? null,
            $description,
            null,
            $inputSchema,
        );

        $logger->info("Registered tool '{$modelConfig['tool']}' -> {$modelConfig['endpoint']} ({$modelConfig['url']})");
    }

    return $builder->build();
}

/**
 * Run the server over the stdio transport.
 *
 * @param array<string, mixed> $config
 */
function runStdio(array $config, ?LoggerInterface $logger = null): int
{
    $logger ??= new StderrLogger();
    $server = buildMcpServer($config, $logger);
    $transport = new StdioTransport(logger: $logger);

    return (int) $server->run($transport);
}

/**
 * Resolve the session store directory for HTTP mode. Sessions must persist
 * across requests because the Server is rebuilt per request.
 */
function defaultSessionDirectory(array $config): string
{
    if (isset($config['http']['sessionDir']) && is_string($config['http']['sessionDir'])) {
        return $config['http']['sessionDir'];
    }

    $temp = sys_get_temp_dir();
    if (!is_string($temp) || $temp === '') {
        $temp = '/tmp';
    }

    return $temp . '/viktor89-mcp-sessions';
}

/**
 * Handle a single HTTP request with the Streamable HTTP transport and return
 * the PSR-7 response. The Server is rebuilt per request (the SDK transport is
 * request-scoped), so a persistent (file-based) session store is used to keep
 * sessions alive across the initialize -> tools/list -> tools/call flow.
 *
 * @param array<string, mixed> $config
 */
function createHttpResponse(array $config, ServerRequestInterface $request, ?LoggerInterface $logger = null, ?SessionStoreInterface $sessionStore = null): ResponseInterface
{
    $logger ??= new StderrLogger();
    $sessionStore ??= new FileSessionStore(defaultSessionDirectory($config));
    $server = buildMcpServer($config, $logger, null, $sessionStore);
    $factory = new HttpFactory();
    $transport = new StreamableHttpTransport($request, $factory, $factory, [], $logger);

    return $server->run($transport);
}

/**
 * Emit a PSR-7 response to the SAPI (for php -S router mode).
 */
function emitResponse(ResponseInterface $response): void
{
    if (!headers_sent()) {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
    }
    echo $response->getBody()->__toString();
}

/**
 * @param list<string> $args
 *
 * @return array{0: string|null, 1: bool, 2: string}
 */
function parseServerArgs(array $args): array
{
    $configPath = null;
    $httpMode = false;
    $bind = '127.0.0.1:8080';

    foreach ($args as $arg) {
        if ($arg === '--http') {
            $httpMode = true;
        } elseif (str_starts_with($arg, '--bind=')) {
            $bind = substr($arg, 7);
        } elseif (str_starts_with($arg, '--port=')) {
            $bind = '127.0.0.1:' . substr($arg, 7);
        } elseif (!str_starts_with($arg, '--')) {
            $configPath = $arg;
        }
    }

    return [$configPath, $httpMode, $bind];
}

/**
 * Start a PHP built-in HTTP server using this file as the router.
 */
function startBuiltInHttpServer(string $configPath, string $bind): int
{
    putenv('MCP_CONFIG_PATH=' . $configPath);
    $command = sprintf('php -S %s %s', $bind, escapeshellarg(__FILE__));
    passthru($command, $code);

    return $code;
}

/*
 * Bootstrap — only when this file is the entry script (not when required by tests).
 */
$isEntry = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);
if (!$isEntry) {
    return;
}

if (PHP_SAPI === 'cli-server') {
    // HTTP router mode: php -S host:port server.php
    $configPath = (string) (getenv('MCP_CONFIG_PATH') ?: __DIR__ . '/mcp-config.json');
    try {
        $config = loadConfig($configPath);
    } catch (\Throwable $e) {
        fwrite(\STDERR, $e->getMessage() . "\n");
        http_response_code(500);
        echo $e->getMessage();

        return;
    }
    $request = \GuzzleHttp\Psr7\ServerRequest::fromGlobals();
    emitResponse(createHttpResponse($config, $request));

    return;
}

// CLI mode (stdio by default, --http to start a built-in HTTP server)
$args = $argv;
array_shift($args);
[$configPath, $httpMode, $bind] = parseServerArgs($args);
$configPath ??= __DIR__ . '/mcp-config.json';

try {
    $config = loadConfig($configPath);
} catch (\Throwable $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(1);
}

if ($httpMode) {
    exit(startBuiltInHttpServer($configPath, $bind));
}

exit(runStdio($config));
