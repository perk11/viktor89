# Viktor89 AGENTS.md

Guidance for AI coding agents working on the Viktor89 Telegram bot.

## What it is

Viktor89 is a personal PHP/Telegram bot for group chats and PMs. It supports dozens of AI models for text, image, video, audio and voice generation, transcription, vision (alt-text, remix), web/tool use, chat moderation (join-quiz captcha, rate limiting, kick queue), and periodic chat summaries. Heavy inference is delegated to a fleet of standalone Python HTTP services in `inference-servers/`.

- Entry point: `viktor89.php` (async Amp event loop). Do **not** run the bot yourself.
- Config: `config.json` (jsonc, gitignored) — copy from `config.example.json`. Legacy values still read from `.env` (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `OPENAI_SERVER`, …).
- README.md is outdated; trust this file and the code over it.
- PHP **8.5**. PSR-4: `Perk11\Viktor89\` → `src/`. Required exts: `sqlite3`, `curl`, `gd`, `dom`, `ssh2`.
- Tests: `vendor/bin/phpunit`.

## Tech stack

`longman/telegram-bot` (Telegram API), `amphp/amp` + `amphp/parallel` (async + worker pool), `orhanerday/open-ai` & `openai-php/client` (LLM APIs), `vlucas/phpdotenv`, `mcp/sdk` (MCP tool calling), `monolog/monolog`, `tecnickcom/tcpdf`, `patrickschur/language-detection`.

## Directory layout

```
viktor89.php            # Event loop: polling, worker dispatch, timers
src/                    # PHP application (Perk11\Viktor89\)
  ProcessMessageTask.php  # DI wiring: builds ALL processors/assistants per message
  Engine.php              # Message dispatcher
  Database.php            # SQLite storage (data/), all persistence goes here
  InternalMessage.php     # Normalized message model for the whole pipeline
  MessageChain*.php       # Chain of InternalMessage + processor interface/runner
  ProcessingResult*.php   # Result of a processor; executor sends it to Telegram
  Assistant/              # LLM assistants + Tool/ (incl. MCP, web search, image tools)
  ImageGeneration/        # A1111/Comfy clients, processors, image repo
  VideoGeneration/  VoiceGeneration/  VoiceRecognition/   # media pipelines
  PreResponseProcessor/   # Legacy pre-chain processors (deprecated)
  IPC/                    # Worker↔main messaging: drafts, typing, status, progress
  AbortStreamingResponse/ # Handlers that cut off runaway streamed responses
  JoinQuiz/  Quiz/  RateLimiting/  Util/Telegram/         # moderation + helpers
inference-servers/       # Standalone Python HTTP services (FastAPI/Flask)
  util/                  # Shared: comfy.py (ComfyUI websocket client), image_resize.py
train/                   # Model fine-tuning (out of scope)
tests/                   # PHPUnit; flat namespace, mirrors src/
```

## Runtime architecture

`viktor89.php` runs one `Revolt\EventLoop`:

1. `EventLoop::repeat(1, …)` — `getUpdates`, dispatches each `Message` to the Amp worker pool as a `ProcessMessageTask`. Poll answers go to `JoinQuiz\PollResponseProcessor`.
2. `EventLoop::repeat(300, …)` — processes pending items in the kick queue (ban/unban/delete per `findPendingKickQueueItems`).
3. `EventLoop::repeat(300, …)` — `PatchesMonitorTask`.
4. A daily summary loop submits `SummaryTask` workers for configured chats.

**Worker / IPC model:** each message is handled in a separate worker process (`ProcessMessageTask::run`). The worker builds the full object graph in `handle()` (Database, assistants, all processors) on a fresh `Telegram`/`Database` per message. Workers talk to the main process over an Amp `Channel` via `IPC\*` messages:
- `ProgressUpdateCallback` → `EngineProgressUpdateCallback`: worker reports progress → main process shows Telegram "typing"/chat actions (`ChatActionUpdater`) and live **draft** messages (`DraftUpdater`) while a long task runs.
- `MessageAboutToBeSentMessage` + `AckMessage`: a handshake that makes the main process stop emitting drafts/typing for a chat *before* the real message lands, so a draft can never appear after the final reply.
- `RunningTaskTracker` tracks in-flight tasks; `/status` (`StatusProcessor`) reads it.

**Streaming & aborts:** assistants stream tokens; `DraftUpdater` edits the draft as text arrives. `AbortStreamingResponse\*Handler`s (`MaxLengthHandler`, `MaxNewLinesHandler`, `RepetitionAfterAuthorHandler`) stop generation early on length/repetition heuristics; attach via `AbortableStreamingResponseGenerator::addAbortResponseHandler()`.

## Message processing pipeline

`Engine::handleMessage(Message)`:

1. `Database::logMessage()`. Early-out on unsupported types / no sender.
2. Run every `PreResponseProcessor` (legacy, registered in `Engine`'s constructor). Return `false` → continue; `string` → reply with it; `null` → stop, no reply.
3. Build a `MessageChain` from the message + reply history (`HistoryReader`). For a reply, prior messages are pulled from DB.
4. `MessageChainProcessorRunner::run()` iterates registered `MessageChainProcessor`s. Each returns a `ProcessingResult` which `ProcessingResultExecutor` sends immediately (message send/edit, reaction, callback). If `abortProcessing` is true, the runner stops. **Multiple commands in one message are split** by the triggering-command regex and run separately (with a small delay to respect rate limits).
5. If nothing handled it and the message isn't a command: only respond when `@botusername` is mentioned **or** it's a reply to the bot. Otherwise hand off to the `fallBackResponder` (`SiepatchNonInstruct4`, a non-instruct chat responder).

## Adding a command

A command is a `MessageChainProcessor` registered in the `$messageChainProcessors` array in `src/ProcessMessageTask.php::handle()`. For slash commands, wrap it in `PreResponseProcessor\CommandBasedResponderTrigger(['/cmd'], $processor)`. Declare trigger commands via `GetTriggeringCommandsInterface::getTriggeringCommands()` so the runner can split chained commands.

```php
public function processMessageChain(MessageChain $chain, ProgressUpdateCallback $cb): ProcessingResult
{
    $last = $chain->last();
    $resp = InternalMessage::asResponseTo($last, "Hi");
    return new ProcessingResult($resp, abortProcessing: true);
}
```

- Construct responses with `InternalMessage` (never raw Telegram calls).
- Return a `ProcessingResult` instead of sending messages directly; `ProcessingResultExecutor` handles drafts/typing/ack handshake.
- `ProcessingResult(response, abort, reaction, messageToReactTo, callback)` — any combination of reply / reaction / side-effect callback.
- Persist settings via `Database::readUserPreference`/`writeUserPreference`. Prefer `UserPreferenceSetByCommandProcessor`, `NumericPreferenceInRangeByCommandProcessor`, or `ListBasedPreferenceByCommandProcessor` for `/set`-style prefs.
- **Avoid** `PreResponseProcessor` for new code (deprecated) — use `MessageChainProcessor` so the message chain/history is available.

## Assistants, tools & MCP

- `Assistant\AssistantFactory` builds assistants from `config.json` → `assistantModels`. Each entry: `url`, `class` (e.g. `OpenAiChatAssistant`, `ThinkRemovingOpenAIChatAssistant`, `Gemma2Assistant`), `model`, `systemPrompt`, `supportsImages`, `supportsResponseStart`, `selectableByUser`, `abortResponseHandlers`.
- Assistants implement `AssistantInterface` (which extends `MessageChainProcessor`), so `/assistant` (`UserSelectedAssistant`) lets a user pick one per chat.
- LLM tools implement `Assistant\Tool\ToolCallExecutorInterface`. Built-ins: image generation (inline + Telegram photo), web search (Ollama/ZAI/Generic, rate-limited via `WebSearchResponseLimiter`), URL fetch, react, list saved/chain images, SQLite knowledge lookup.
- **MCP** servers (`mcp/sdk`) are exposed as tools via `McpToolCallExecutor($client, $toolName)`.
- Vision: named assistants (`vision-for-alt-text`, `vision-for-remix`, `gemma2-for-imagine`) drive `AltTextProvider`, `RemixProcessor`, `AssistedImageGenerator`/`AssistedVideoProcessor` (LLM writes the prompt, then a media model generates).

## Configuration (`config.json`)

jsonc, gitignored. Model-pick arrays are each `name → {url, …}`; commands like `/imagemodel` switch the active entry per user. Key sections:

| Key | Used by |
|---|---|
| `assistantModels` | `AssistantFactory` (chat/vision assistants) |
| `imageModels`, `imageEditModels`, `restyleModels`, `rmBgModels`, `zoomModels`, `videoFirstFrameImageModels` | image generation / transform pipelines |
| `videoModels`, `img2videoModels`, `videoEditModels`, `voiceOverModels`, `upscaleModels` | video clients |
| `voiceModels`, `singModels`, `soundAndPromptToTargetAndResidualModels`, `podcastVoices` | audio/TTS |
| `imageSizes` | `/imagesize` |
| `whisperCppUrl` | voice transcription |
| `generatedImageMarkdownUploader` | SCP-uploads generated images to a public host (`scpTarget`, `publicUrlPrefix`, SSH key paths, `port`) |
| `ollamaWebSearchApiKey`, `zAiWebSearchApiKey` | web search tools |

## Commands

* Commands are registered in `ProcessMessageTask`
* When adding commands, use CommandBasedResponderTrigger.

## Inference servers (`inference-servers/`)

Each subdirectory is a standalone Python HTTP service: `main.py` (FastAPI/Flask) takes JSON (prompt/image/audio/video, often base64) and returns generated media/text. ComfyUI-based servers ship workflow `.json` and use `util/comfy.py` (websocket client: queue prompt → stream outputs). Examples: `image-generic-comfy/` (many models), `sd35/`, `flux/`, `cogvideo/`, `mochi*/`, `wan2-comfy/`, `rmbg/`, `tts/`, `qwen3-vl/`, `BAGEL/`. Add a server: new subdir → `main.py` → JSON request/response → (optional) workflow JSON + `README.md` → add the matching PHP client under `src/`.

## Conventions & gotchas

- All message construction uses `InternalMessage`; all persistence goes through `Data
- Keep comments minimal — only where intent isn't obvious from code.
- Prefer imports over fully-qualified names.
