# Viktor89 AGENTS.md

This file provides guidance for AI coding agents working on the Viktor89 Telegram bot.

## Project Overview

Viktor89 is a PHP/Telegram bot that routes messages to processors and delegates heavy work to Python inference servers. The main entry point is `viktor89.php`. Configuration is loaded from `config.json` which uses jsonc (and deprecated parameters from`.env`).

## Architecture

```
src/                         # PHP application
inference-servers/           # Python inference services
train/                       # Model training (out of scope)
```

### PHP Application (`src/`)

The bot uses an async event loop (`viktor89.php`) built on Amp:
- Polls Telegram via `getUpdates` using `EventLoop::repeat()`
- Dispatches each message to a parallel `ProcessMessageTask` worker via `Amp\Parallel\Worker\workerPool()`
- Runs background tasks on timers: chat summaries, kick queue cleanup, patches monitor

Message flow:
1. `Engine::handleMessage()` receives a Telegram `Message`
2. `Engine` runs all `PreResponseProcessor` instances; if one returns a string, that string is sent as a reply
3. If no pre-response processor handles it, a `MessageChain` is built from the message and history
4. `MessageChainProcessorRunner::run()` executes the chain
5. If the chain is not handled, the `fallBackResponder` generates a response

Key interfaces:
- `PreResponseProcessor` — handles messages before the chain is built. Returns `false` to continue, `string` to respond, or `null` to skip responding. **Deprecated** in favor of `MessageChainProcessor`.
- `MessageChainProcessor` — processes a full `MessageChain`. Returns `ProcessingResult`.
- `TelegramInternalMessageResponderInterface` — fallback responders that take a raw Telegram message.

Key classes:
- `Engine` — central message dispatcher
- `MessageChain` — ordered list of `InternalMessage` objects representing a conversation
- `InternalMessage` — normalized message representation
- `MessageChainProcessorRunner` — runs processors and handles abort/max-length logic
- `ProcessingResultExecutor` — sends `ProcessingResult` back to Telegram
- `Database` — SQLite-backed storage for messages, preferences, queues (stored in `data/`)

### Inference Servers (`inference-servers/`)

Each subdirectory is a standalone Python service exposing an HTTP API. Common utilities are in `inference-servers/util/`.

Typical server pattern:
- `main.py` starts a FastAPI/Flask/HTTP server
- Accepts JSON requests with prompt, image, audio, or video data
- Returns generated media or text
- May depend on ComfyUI workflows (`.json` files) or model checkpoints

Examples:
- `image-generic-comfy/` — generic image generation via ComfyUI, supports many models
- `sd35/`, `flux/`, `cogvideo/`, `mochi/` — model-specific servers

## How to Create Commands

Commands are implemented as `PreResponseProcessor` or `MessageChainProcessor` classes.

### Using PreResponseProcessor (legacy)

1. Create a class in `src/` implementing `PreResponseProcessor`
2. Register it in the processor list passed to `Engine`
3. Return `false` to let other processors run, a `string` to send a reply, or `null` to stop processing without a reply

### Using MessageChainProcessor (recommended)

1. Create a class in `src/` implementing `MessageChainProcessor`
2. Implement `processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult`
3. Return a `ProcessingResult` with the response message and a handled flag
4. The runner will stop processing if `$abortProcessing` is `true`

Prefer returning results from `processMessageChain()` over sending messages directly.

Example structure:
```php
class MyCommand implements MessageChainProcessor
{
    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        // ... do work ...
        $response = InternalMessage::asResponseTo($lastMessage, "Hello");
        return new ProcessingResult($response, true);
    }
}
```

## How to Create Inference Servers

1. Create a new subdirectory under `inference-servers/`
2. Add a `main.py` that starts an HTTP server (FastAPI recommended)
3. Define request/response schemas
4. If using ComfyUI, add workflow `.json` files
5. Document the server's API in a `README.md` in the same directory
6. Update the corresponding PHP client in `src/` to call the new endpoint

Server conventions:
- Accept JSON payloads
- Return JSON responses
- Use HTTP endpoint that can already consumed by the PHP bot
- Handle errors gracefully with appropriate HTTP status codes

## Running the Bot

Do not attempt to run the bot directly.

## Code Style

- PHP 8.5+ features are used (readonly, enums where applicable)
- PSR-4 autoloading: `Perk11\Viktor89\` maps to `src/`
- Use `InternalMessage` for all message construction
- Use `Database` for persistence; never direct SQL outside of it

## Important Notes

- The README is outdated; do not rely on it for setup or architecture guidance.
- Required PHP extensions: `sqlite3`, `curl`, `gd`, `dom`, `ssh2`.
- The bot uses `longman/telegram-bot`, both `orhanerday/open-ai` and `openai-php/client` for OpenAI, `amphp/amp` + `amphp/parallel` for async workers, and `mcp/sdk` for MCP tool support.
- In non-command messages, the bot only responds if the message mentions `@botusername` or is a reply to the bot's own message.
- Multiple commands can be chained in one message (newline-separated); `MessageChainProcessorRunner` splits and processes each command separately.
- Run tests with vendor/bin/phpunit
