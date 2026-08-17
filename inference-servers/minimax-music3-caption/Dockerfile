# MiniMax-Music3 caption writer inference server.
#
# Runs the pi coding agent (https://github.com/earendil-works/pi-coding-agent) inside
# this container with the music-caption-rewriter agent skill from
# https://github.com/MiniMax-AI/MiniMax-Music3 installed, and exposes an HTTP API that
# rewrites a music description (+ optional tagged lyrics) into a Music 3.0 structured
# caption. The caption is meant to be passed to ComfyUI running MiniMax Music 3 as one
# of the songModels. See README.md for the API contract and configuration.
#
# The single model pi uses is configured entirely through environment variables (full
# list in README.md); only those values enter the container — the bot's full
# config.json is never mounted or exposed to pi:
#
#   CAPTION_MODEL_URL        (required) pi provider baseUrl, include the /v1 suffix
#                            for OpenAI-compatible servers (llama.cpp, Ollama, vLLM, ...)
#   CAPTION_MODEL_ID         (required) model id as served by that url
#   CAPTION_MODEL_API_KEY    (optional) API key, only for providers that check auth
#   CAPTION_MODEL_API        (optional) pi API type, default openai-completions
#   CAPTION_MODEL_REASONING  (optional) 1/true/yes when the model supports thinking
#   CAPTION_MODEL_CONTEXT_WINDOW / CAPTION_MODEL_MAX_TOKENS  (optional) token limits
#   CAPTION_MODEL_COMPAT     (optional) JSON object of pi provider compat flags
#
# Build:
#   docker build -t viktor89-minimax-music3-caption inference-servers/minimax-music3-caption
#
# Run (CAPTION_MODEL_URL usually points at localhost, so host networking is the
# simplest way to reach it from inside the container):
#   docker run --rm --network host \
#     -e CAPTION_MODEL_URL=http://localhost:7070/v1 \
#     -e CAPTION_MODEL_ID=gemma-4-31B-it-UD-Q6_K_XL \
#     [-e CAPTION_MODEL_API_KEY=...] \
#     viktor89-minimax-music3-caption
FROM node:24-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive \
    NODE_ENV=production

# bash + ripgrep for pi's built-in tools, git for the skill checkout below,
# ca-certificates for HTTPS model providers.
RUN apt-get update \
    && apt-get install -y --no-install-recommends bash ca-certificates git ripgrep \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g --ignore-scripts @earendil-works/pi-coding-agent

# Install the music-caption-rewriter skill into pi's global skills dir so pi discovers
# it automatically. Pinned to a commit for reproducible builds; bump MINIMAX_MUSIC3_REF
# to pick up skill updates.
ARG MINIMAX_MUSIC3_REF=945655064d59b98004dd70002e7eb5c8c6e11373
RUN git clone https://github.com/MiniMax-AI/MiniMax-Music3.git /tmp/MiniMax-Music3 \
    && git -C /tmp/MiniMax-Music3 checkout "${MINIMAX_MUSIC3_REF}" \
    && mkdir -p /root/.pi/agent/skills \
    && cp -r /tmp/MiniMax-Music3/skills/music-caption-rewriter /root/.pi/agent/skills/ \
    && rm -rf /tmp/MiniMax-Music3

WORKDIR /app
COPY main.js .

EXPOSE 8242

# PORT (default 8242) can be overridden with -e.
CMD ["sh", "-c", "node main.js --host 0.0.0.0 --port ${PORT:-8242}"]
