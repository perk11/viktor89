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
| `mcp-config.example.json` | Endpoint + model/tool definitions (see below). Copy to `mcp-config.json` and edit. |

## Supported endpoints

The server speaks the same HTTP contract the rest of the project already uses (see
`src/ImageGeneration/Automatic1111APiClient.php`, `src/VideoGeneration/Txt2VideoClient.php`,
`src/VoiceGeneration/TtsApiClient.php`):

| Endpoint type | HTTP path | Request body | Response body |
|---|---|---|---|
| `txt2img` | `POST /sdapi/v1/txt2img` | `{prompt, negative_prompt, seed, width, height, ...}` | `{images: [base64], info: "<json string with infotexts>"}` |
| `img2img` | `POST /sdapi/v1/img2img` | same + `init_images: [base64]` | `{images: [base64], info}` |
| `txt2vid` | `POST /txt2vid` | `{prompt, negative_prompt, seed, steps, num_frames, width, height, cfg_scale, ...}` | `{videos: [base64], info}` |
| `img2vid` | `POST /img2vid` | same + `init_images: [base64]` | `{videos: [base64], info}` |
| `audio_txt2vid` | `POST /audio_txt2vid` | `{prompt, seed, model, init_audios?: [base64]}` (reference-capable models, no image) | `{videos: [base64], info}` |
| `audio_img_txt2vid` | `POST /audio_img_txt2vid` | same + `init_images: [base64]` (reference-capable models, with image) | `{videos: [base64], info}` |
| `txt2voice` | `POST /txt2voice` | `{prompt, language, speaker_id, source_voice, source_voice_format, speed, ...}` | `{voice_data: base64, info: {...}}` |

`audio_txt2vid` / `audio_img_txt2vid` are served by the `audio-img-txt2vid-generic-comfy`
server and back the MiniMax-H3 `ref2vid` model (text-to-video uses `audio_txt2vid`;
image/audio references use `audio_img_txt2vid`). Both return the same `{videos, info}`
shape as `txt2vid`.

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

## Configuration (`mcp-config.example.json` → `mcp-config.json`)

Copy the example and edit it for your setup (the real `mcp-config.json` is gitignored):

```bash
cp inference-servers/mcp/mcp-config.example.json inference-servers/mcp/mcp-config.json
```

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
  "tool": "generate_image_flux2_dev_fp8",
  "endpoint": "txt2img",
  "url": "http://localhost:8136",
  "model": "flux2_dev_fp8",
  "description": "Generate an image from a text prompt using Flux2.",
  "defaults": { "width": 1024, "height": 1024, "steps": 20 },
  "sizeConstraints": {
    "width":  { "min": 256, "max": 2048, "multipleOf": 16 },
    "height": { "min": 256, "max": 2048, "multipleOf": 16 }
  },
  "parameters": ["prompt", "negative_prompt", "seed", "width", "height"],
  "required": ["prompt"]
}
```

**Multiple models are supported**: each entry becomes its own MCP tool, and `tool` is the configurable
tool name exposed to clients. Parameter names reference a built-in registry in `server.php`
(`parameterDefinitions()`); an entry's `parameters` list selects which subset the tool advertises. If
`required` is omitted, sensible defaults are derived from the endpoint type (`prompt` for txt2img/txt2vid,
`prompt`+`init_image` for img2img/img2vid, `prompt`+`language` for txt2voice).

**`model` and `steps` are never user-configurable.** They are always taken from the tool's `defaults` /
`model` fields so the configured checkpoint and sampling settings are used verbatim. A tool's
`parameters` list must not include them (and the built-in registry no longer defines them).

**Width / height validation.** Tools that expose `width`/`height` declare a `sizeConstraints` object with
per-dimension `min`, `max`, and `multipleOf`. The constraints are injected into the tool's JSON Schema
(as `minimum`, `maximum`, `multipleOf`, validated by the SDK's schema validator) and also checked in
`executeInference()` before the HTTP request is made, so an out-of-range value is rejected with a clear
error message rather than producing a broken image. Constraints are model-specific (e.g. SD 1.5:
128–1024 multiple-of-8; LTX-2 video: 256–1280 multiple-of-64; Flux/wan2.2 image: up to 2048/2880
multiple-of-16).

**Prompt preprocessing (`preprocessor` + `llm`).** A video tool may declare a
`preprocessor` key. When set to `minimax-h3`, the user's prompt is first rewritten
into the structured MiniMax-H3 video-prompt format — by reusing the project's
`MiniMaxH3VideoPromptPreprocessor` (`src/VideoGeneration/VideoPromptPreprocessor/`)
over a small OpenAI-compatible LLM — and only the rewritten prompt is forwarded
to the inference server. This lets a client such as Claude Code get
MiniMax-H3-quality prompts without the bot's full dependency container.

The preprocessor needs an LLM, configured via a top-level `llm` block:

```jsonc
{
  "llm": {
    "url": "https://api.z.ai/api/coding/paas/v4",
    "model": "glm-5.2",
    "supportsImages": false        // optional; the bot's rewrite model is text-only
    // "apiKey": "..."             // optional; else read from Z_AI_API_KEY / VIKTOR89_MCP_LLM_API_KEY
  }
}
```

`apiKey` is resolved as: `llm.apiKey` → `Z_AI_API_KEY` env → `VIKTOR89_MCP_LLM_API_KEY`
env. If no key is available, the image tools still work; only the video tool's
prompt-rewrite step (and therefore `video_gen_tool`) fails with a clear error. The
preprocessor supports MiniMax-H3 **text-to-video** only; image-conditioned video is
not wired through the standalone server.

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

### Wiring it into Claude Code

A dedicated, minimal config (`mcp-config-claude.json`) exposes exactly three tools
for use from Claude Code:

| Tool | Model | Endpoint |
|---|---|---|
| `image_gen_tool` | `ideogram4_fast` | `txt2img` @ `http://localhost:8227` |
| `image_edit_tool` | `flux2_dev_fp8-turbo-8-steps` (Flux2 dev turbo) | `img2img` @ `http://localhost:8149` (`init_image` = base64 source) |
| `video_gen_tool` | `minimax-h3-ref2vid` | `audio_txt2vid` @ `http://localhost:8240` (prompt rewritten via `preprocessor: minimax-h3`) |

Claude Code connects to the server over **HTTP**, not stdio. Run the server on the
host that owns the inference servers (so it can reach them on `localhost`), binding
to all interfaces so it is reachable from the Claude Code container as
`host.docker.internal`:

```bash
Z_AI_API_KEY=… php inference-servers/mcp/server.php --http --bind=0.0.0.0:8080 \
    inference-servers/mcp/mcp-config-claude.json
```

`Z_AI_API_KEY` lives in the server process's environment (it is not passed through
`.mcp.json` for an HTTP server) and is required only for `video_gen_tool`'s prompt
rewrite. The project `.mcp.json` (gitignored — copy from `.mcp.json.example`) just
points Claude Code at the running server:

```jsonc
{
  "mcpServers": {
    "viktor89-inference": {
      "type": "http",
      "url": "http://host.docker.internal:8080"
    }
  }
}
```

Restart Claude Code (or re-run `/mcp`) after creating `.mcp.json`. The image tools
work without any key; `video_gen_tool` additionally needs `Z_AI_API_KEY` (see
preprocessing above) and the `ideogram4_fast` / `flux2_dev_turbo` /
`minimax-h3-ref2vid` inference servers running on their localhost ports.

> Linux Docker note: `host.docker.internal` resolves automatically on Docker Desktop;
on a Linux host, start the Claude Code container with
`--add-host=host.docker.internal:host-gateway`.

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
