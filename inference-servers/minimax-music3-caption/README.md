# minimax-music3-caption

HTTP caption writer for [MiniMax Music 3](https://github.com/MiniMax-AI/MiniMax-Music3).
It runs the [pi coding agent](https://github.com/earendil-works/pi-coding-agent) inside a
Docker container with the [`music-caption-rewriter` agent
skill](https://github.com/MiniMax-AI/MiniMax-Music3/tree/main/skills) installed, and
rewrites a short music description (+ optional tagged lyrics) into the detailed
**Music 3.0 Structured Caption** the skill produces (Global Metadata, Vocal Details,
Arrangement). The caption is meant to be passed to ComfyUI running MiniMax Music 3 as
one of the `songModels` — as the generation instructions, alongside the original
lyrics.

Unlike the other inference servers this one performs no inference itself: it is a thin
HTTP wrapper around pi. The skill is a pure text library (a genre router, family
indexes and 1 000 caption templates) that the agent walks with `read`-style tools;
every caption request therefore runs a fresh, ephemeral pi session.

## Model configuration

Pi runs with **exactly one model available**, configured through environment
variables — only these values are passed to the container, the bot's full
`config.json` is never mounted. The values usually come from the bot config's
`minimaxMusic3CaptionWriter` section (see below) and are forwarded as `-e` flags:

| Variable | Required | Default | Meaning |
|---|---|---|---|
| `CAPTION_MODEL_URL` | yes | — | pi provider **baseUrl**. For local OpenAI-compatible servers include the `/v1` suffix (llama.cpp, Ollama, vLLM, LM Studio, ...); for hosted APIs use their OpenAI-compatible root as documented, e.g. `https://api.z.ai/api/coding/paas/v4` (no extra suffix). |
| `CAPTION_MODEL_ID` | yes | — | Model id as served by that url. |
| `CAPTION_MODEL_API_KEY` | no | `none` | API key for providers that check auth; forwarded as the `Authorization: Bearer` header (or the provider's native auth). Unnecessary for keyless local servers. |
| `CAPTION_MODEL_API` | no | `openai-completions` | pi API type (`openai-completions`, `anthropic-messages`, `openai-responses`, `google-generative-ai`, ...). OpenAI-compatible endpoints (including the z.ai coding endpoint) need no override. |
| `CAPTION_MODEL_REASONING` | no | `false` | `1`/`true`/`yes` when the model supports extended thinking. |
| `CAPTION_MODEL_CONTEXT_WINDOW` | no | `128000` | Context window in tokens. |
| `CAPTION_MODEL_MAX_TOKENS` | no | `8192` | Max output tokens. |
| `CAPTION_MODEL_COMPAT` | no | see below | JSON object of pi provider compat flags, merged over `{"supportsDeveloperRole": false, "supportsReasoningEffort": false}`. |

At startup the server renders these into `/root/.pi/agent/models.json` + `settings.json`
inside the container, so pi knows only this provider and model (and no other
credentials exist in the container). Following the skill's multi-step workflow
(routing → index cards → template reads → synthesis) needs a capable
instruction-following model; a small chat model will produce weak captions.

The corresponding section of the bot's main `config.json` (gitignored) documents which
model the deployment uses:

```jsonc
"minimaxMusic3CaptionWriter": {
  "url": "http://localhost:7070/v1",
  "model": "gemma-4-31B-it-UD-Q6_K_XL",
  // "apiKey": "..." — only for providers that check auth
}
```

When starting the container, forward it, e.g.

```bash
section=$(jq '.minimaxMusic3CaptionWriter' config.json)
docker run --rm --network host \
  -e CAPTION_MODEL_URL="$(jq -r .url <<<"$section")" \
  -e CAPTION_MODEL_ID="$(jq -r .model <<<"$section")" \
  [-e CAPTION_MODEL_API_KEY="$(jq -r '.apiKey // empty' <<<"$section")"] \
  viktor89-minimax-music3-caption
```

## Build & run

```bash
docker build -t viktor89-minimax-music3-caption inference-servers/minimax-music3-caption

# Hosted OpenAI-compatible endpoint (z.ai GLM coding plan) — verified end to end
# with glm-5.3; note the url needs no trailing /v1 and reasoning is supported.
# Plain bridge networking is enough for a public endpoint:
docker run --rm -p 8242:8242 \
  -e CAPTION_MODEL_URL=https://api.z.ai/api/coding/paas/v4 \
  -e CAPTION_MODEL_API_KEY=<your-key> \
  -e CAPTION_MODEL_ID=glm-5.3 \
  -e CAPTION_MODEL_REASONING=true \
  viktor89-minimax-music3-caption

# Local OpenAI-compatible server (llama.cpp, Ollama, vLLM, ...): the url points at
# localhost, so run with host networking (otherwise use an url the container can
# reach, e.g. http://host.docker.internal:7070/v1 or the host's LAN address).
docker run --rm --network host \
  -e CAPTION_MODEL_URL=http://localhost:7070/v1 \
  -e CAPTION_MODEL_ID=gemma-4-31B-it-UD-Q6_K_XL \
  viktor89-minimax-music3-caption
```

> **`--network host` + DNS caveat:** host-network containers bypass Docker's embedded
> DNS and query the nameservers of their `/etc/resolv.conf` directly. On hosts whose
> own resolv.conf is a localhost stub (systemd-resolved) Docker may fall back to public
> resolvers — which many ISP/router networks refuse (`ECONNREFUSED` on port 53,
> visible as `EAI_AGAIN`). If that happens, prefer bridge networking (it works even
> for local LLMs via `host.docker.internal`), or pass a resolver that is actually
> reachable: `--dns <router-ip>` (`ip route | grep default`). `GET /debug` shows the
> resolver config and a step-by-step probe.

Defaults: port `8242` (override with `-e PORT=...`), pi skill run timeout `600 s`
(extend the CMD with `--timeout <seconds>`, or `--pi-bin <path>`).

The skill checkout is pinned to a fixed MiniMax-Music3 commit for reproducible builds:

```bash
docker build --build-arg MINIMAX_MUSIC3_REF=<commit-or-branch-or-tag> \
  -t viktor89-minimax-music3-caption inference-servers/minimax-music3-caption
```

## API

### `GET /health`

```json
{ "ok": true, "model": "gemma-4-31B-it-UD-Q6_K_XL", "provider": "caption-model", "skill": "music-caption-rewriter" }
```

No probing happens here — the endpoint is never contacted on the happy path or at
startup; diagnostics run only when a request fails (see below).

### `GET /debug`

On-demand, fresh, step-by-step probe of the configured model endpoint from inside
this container, independent of pi: DNS (getaddrinfo + direct DNS query + a control
domain), TCP, TLS/HTTP, and a real `chat/completions` call. The same diagnostics are
attached to every 502 response, probed only when that failure occurs and cached for
60 s (marked `"cached": true`) so repeated failures don't re-probe on every request.
Use it whenever captions fail with connection errors:

```bash
curl -s http://localhost:8242/debug | jq
```

The `dns` step distinguishes the failure modes: `EAI_AGAIN`/`ENOTFOUND` with a failing
control domain means DNS is broken in the container as a whole (start it with
`--dns 1.1.1.1`, or fix the host resolver when using `--network host`); a failing
target hostname with a working control domain points at a typo in
`CAPTION_MODEL_URL` or transient upstream flakiness. The `resolver` block shows which
nameservers the container uses (e.g. Docker's embedded `127.0.0.11` vs the host's
`systemd-resolved` stub, which is only reachable with host networking).

Setting `-e CAPTION_DEBUG=1` additionally dumps the raw pi output of every failed run
to `<pi workdir>/pi-last.out.jsonl` / `pi-last.err.log` (path is logged); the startup
log prints the pi and node versions.

### `POST /txt_lyrics2caption`

Request body:

| Field | Required | Meaning |
|---|---|---|
| `text` (alias `caption`) | yes | Natural-language music description. |
| `lyrics` | no | Lyrics with bracketed section tags (`[Verse]`, `[Chorus]`, ...). Never reproduced; only the tags act as directives. |
| `constraints` | no | Length, format, exclusions or creative direction for the caption. |

The caption request is queued — one pi run per container at a time — and typically takes
tens of seconds to a few minutes depending on the model.

```bash
curl -s http://localhost:8242/txt_lyrics2caption \
  -H 'Content-Type: application/json' \
  -d '{
        "text": "A warm acoustic pop song with intimate female vocals, fingerpicked guitar, and a gradual emotional build into a wide final chorus.",
        "lyrics": "[Verse]\n...\n\n[Chorus]\n..."
      }' | jq -r .caption
```

Response:

```json
{
  "caption": "### Global Metadata\n... \n\n### Vocal Details\n...\n\n### Arrangement\n...",
  "info": { "model": "gemma-4-31B-it-UD-Q6_K_XL", "durationMs": 41230 }
}
```

Errors are returned as `{"error": "..."}` with `400` (bad request), `413` (body too
large), `502` (pi/model failure) or `504` (skill run exceeded the timeout).

## How a request runs

1. `main.js` builds a prompt asking the agent to use the `music-caption-rewriter` skill
   (reading its `SKILL.md` at its absolute path first) with the given
   caption/lyrics/constraints.
2. It spawns an ephemeral pi session:

   ```
   pi --mode json --no-session --no-extensions --no-context-files \
      --tools read,grep,find,ls \
      --provider caption-model --model <model> "<prompt>"
   ```

   Tools are limited to the read-only file tools the skill needs (the skill itself
   mandates text-only reasoning: no scripts, no network), `--no-session` keeps the
   container stateless, and the JSON event stream on stdout makes the final caption
   extraction reliable: the server takes the text of the last assistant `message_end`
   event.
3. The caption is returned as JSON. No sessions, drafts or credentials are persisted;
   the only state in the container is the pi config rendered from the
   `CAPTION_MODEL_*` env vars at startup (pi runs with a fresh scratch dir as cwd, and
   its stdin is closed since no piped prompt is used).

Note: pi is installed from npm at image build time; rebuild the image (or
`docker exec <container> npm install -g @earendil-works/pi-coding-agent && docker restart <container>`)
to pick up pi updates.
