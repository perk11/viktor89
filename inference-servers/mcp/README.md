# Viktor89 inference MCP server

A stdio [Model Context Protocol](https://modelcontextprotocol.io) server written in PHP that exposes the
project's Python inference servers (`inference-servers/*`) as MCP tools.

The server **translates MCP tool calls into HTTP requests** against the configured inference endpoints and
converts the HTTP responses back into MCP `CallToolResult` content. It performs **no inference itself and
spawns no processes** — it is a pure HTTP gateway built on the [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk)
package already in `composer.json`.

## Files

| File | Purpose |
|---|---|
| `server.php` | The MCP server. Loads the config, registers one tool per model, runs over stdio. |
| `mcp-config.json` | Endpoint + model/tool definitions (see below). |

## Supported endpoints

The server speaks the same HTTP contract the rest of the project already uses (see
`src/ImageGeneration/Automatic1111APiClient.php`, `src/VideoGeneration/Txt2VideoClient.php`,
`src/VoiceGeneration/TtsApiClient.php`):

| Endpoint type | HTTP path | Request body | Response body |
|---|---|---|---|
| `txt2img` | `POST /sdapi/v1/txt2img` | `{prompt, negative_prompt, seed, steps, width, height, model, ...}` | `{images: [base64], info: "<json string with infotexts>"}` |
| `img2img` | `POST /sdapi/v1/img2img` | same + `init_images: [base64]` | `{images: [base64], info}` |
| `txt2vid` | `POST /txt2vid` | `{prompt, negative_prompt, seed, steps, num_frames, width, height, cfg_scale, ...}` | `{videos: [base64], info}` |
| `img2vid` | `POST /img2vid` | same + `init_images: [base64]` | `{videos: [base64], info}` |
| `txt2voice` | `POST /txt2voice` | `{prompt, language, speaker_id, source_voice, source_voice_format, speed, ...}` | `{voice_data: base64, info: {...}}` |

A single MCP tool accepts `init_image` (one base64 image); the server wraps it into the `init_images[]`
array the inference servers expect.

## Result format — returned as base64 strings

**Every generated media result is returned base64-encoded.** Each tool result contains the media as proper
MCP-typed content **plus** a `text` note that states the media is a base64 string and gives its MIME type
(and the server's caption/infotext when available):

| Media | MCP content type | Field carrying the base64 | MIME |
|---|---|---|---|
| image | `image` (`ImageContent`) | `data` | `image/png` |
| audio | `audio` (`AudioContent`) | `data` | `audio/ogg` |
| video | `resource` (`EmbeddedResource` → `BlobResourceContents`) | `blob` | `video/mp4` |

Example result for an image tool:

```json
{
  "content": [
    { "type": "image", "data": "iVBORw0KGgo...", "mimeType": "image/png" },
    { "type": "text", "text": "Result returned as a base64-encoded image string (MIME: image/png). Caption: a red cat, steps=20" }
  ],
  "isError": false
}
```

If the inference server returns an `error` field or a non-2xx status, the result is returned with
`isError: true` and the error message as text (per the MCP spec, tool-level errors are reported in the
result, not as a protocol error, so the model can self-correct).

## Configuration (`mcp-config.json`)

The config has three sections:

- `server` — MCP server identity (`name`, `version`, `instructions`).
- `http` — cURL timeouts (`timeoutSeconds`, `connectTimeoutSeconds`).
- `endpoints` — the endpoint catalogue. Each entry defines `method`, `path`, and a `response` shape
  (`media` = `image`/`audio`/`video`, `field` = the JSON key holding the base64 media, `mimeType`,
  `infoField`, `infoIsJsonString`, `captionField`).
- `models` — one entry per MCP tool. Each references an `endpoint`, has a `tool` name, a `url`, an
  optional `model` checkpoint name, `defaults`, the `parameters` to expose, and `required`.

```jsonc
{
  "tool": "generate_image_flux2",
  "endpoint": "txt2img",
  "url": "http://localhost:8136",
  "model": "flux2_dev_fp8",
  "description": "Generate an image from a text prompt using Flux2.",
  "defaults": { "width": 1024, "height": 1024, "steps": 20 },
  "parameters": ["prompt", "negative_prompt", "seed", "steps", "width", "height"],
  "required": ["prompt"]
}
```

**Multiple models are supported**: each entry becomes its own MCP tool, and `tool` is the configurable
tool name exposed to clients. Parameter names reference a built-in registry in `server.php`
(`parameterDefinitions()`); an entry's `parameters` list selects which subset the tool advertises. If
`required` is omitted, sensible defaults are derived from the endpoint type (`prompt` for txt2img/txt2vid,
`prompt`+`init_image` for img2img/img2vid, `prompt`+`language` for txt2voice).

## Running

The server supports **two transports**: stdio (default) and Streamable HTTP.

### stdio (default)

Auto-loads the SDK from `../../vendor/autoload.php` and defaults to `mcp-config.json` next to the
script; pass a config path as the first argument to override:

```bash
php inference-servers/mcp/server.php                      # uses ./mcp-config.json
php inference-servers/mcp/server.php /path/to/config.json # explicit config
```

### HTTP (Streamable HTTP transport)

Start a built-in HTTP server (this file is its own router):

```bash
php inference-servers/mcp/server.php --http                              # 127.0.0.1:8080, ./mcp-config.json
php inference-servers/mcp/server.php --http --bind=0.0.0.0:9000 cfg.json # explicit bind + config
# or run the router directly:
php -S 127.0.0.1:8080 inference-servers/mcp/server.php
```

The HTTP endpoint implements the MCP Streamable HTTP protocol: `POST` JSON-RPC messages to `/`
(or any path). The first request must be `initialize`; the server returns a `Mcp-Session-Id`
response header that must be sent back on subsequent requests (`tools/list`, `tools/call`).
`DELETE` ends a session, `OPTIONS` returns CORS preflight. Because the server is rebuilt per
request, HTTP mode persists sessions to disk via `FileSessionStore` (configure the directory with
`http.sessionDir`; defaults to `sys_get_temp_dir()/viktor89-mcp-sessions`).

Quick check (initialize, then tools/list with the session id):

```bash
SID=$(curl -s -i -X POST http://127.0.0.1:8080/ -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1.0.0"}}}' \
  | grep -i 'Mcp-Session-Id' | awk '{print $2}' | tr -d '\r')
curl -s -X POST http://127.0.0.1:8080/ -H 'Content-Type: application/json' -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

### Wiring it into the bot (stdio)

Add it under `mcpServers` in an assistant's config in `config.json` (see
`src/Assistant/AssistantFactory.php` — `command`/`args` → `StdioTransport`):

```jsonc
"mcpServers": [
  {
    "command": "php",
    "args": ["/home/perk11/LLM/viktor89/inference-servers/mcp/server.php"]
  }
]
```

Each configured tool then becomes available to the assistant as an LLM tool via `McpToolCallExecutor`.

## Testing the server manually (stdio)

Pipe JSON-RPC over stdio (the transport is newline-delimited):

```bash
printf '%s\n' \
'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1.0.0"}}}' \
'{"jsonrpc":"2.0","method":"notifications/initialized"}' \
'{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
| php inference-servers/mcp/server.php
```

Server logs go to stderr; JSON-RPC responses go to stdout.

## Tests

`tests/InferenceMcpServerTest.php` covers the helper logic, the HTTP forwarding/response
translation against a mock inference server, and **both transports**: stdio end-to-end via
`proc_open`, and Streamable HTTP both in-process (`createHttpResponse`) and through a live
`php -S` router. Run with:

```bash
vendor/bin/phpunit tests/InferenceMcpServerTest.php
```
