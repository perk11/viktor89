#!/usr/bin/env node
// MiniMax-Music3 caption writer inference server.
//
// HTTP wrapper around the pi coding agent (https://github.com/earendil-works/pi-coding-agent)
// running with the music-caption-rewriter agent skill from
// https://github.com/MiniMax-AI/MiniMax-Music3 (installed into pi's global skills dir
// by the Dockerfile). Takes an input text (music description) and optional tagged
// lyrics, runs them through the skill with pi, and returns the final Music 3.0
// structured caption. The caption is meant to be passed to ComfyUI running MiniMax
// Music 3 as one of the songModels.
//
// The single model pi uses is configured through CAPTION_MODEL_* environment variables
// (see loadModelConfig) — only those values are passed to the container, the bot's
// full config.json is never mounted or exposed to pi. At startup this server renders
// them into pi's own models.json/settings.json so pi has exactly that one model
// available.
//
// Zero-dependency Node (node:http + child_process), matching the node:24 base image
// pi requires. See README.md for build/run instructions and the API contract.

'use strict';

const http = require('node:http');
const https = require('node:https');
const dnsPromises = require('node:dns').promises;
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');

const PROVIDER_ID = 'caption-model';
const SKILL_NAME = 'music-caption-rewriter';

// Appended to pi connection failures surfacing as 502; the precise probe results
// (DNS, TCP, TLS/HTTP, chat completions) are attached to the response as
// `diagnostics` and available any time via GET /debug.
const REACHABILITY_HINT = ' (probe results are attached to this response as "diagnostics" and available via GET /debug)';
const DEFAULT_PORT = 8242;
const DEFAULT_TIMEOUT_S = 600;
const MAX_BODY_BYTES = 2 * 1024 * 1024;
const MAX_STDOUT_BYTES = 32 * 1024 * 1024;

function parseArgs(argv) {
    const args = { host: '0.0.0.0', port: DEFAULT_PORT, timeoutS: DEFAULT_TIMEOUT_S, piBin: 'pi' };
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--host') args.host = argv[++i];
        else if (a === '--port') args.port = parseInt(argv[++i], 10);
        else if (a === '--timeout') args.timeoutS = parseInt(argv[++i], 10);
        else if (a === '--pi-bin') args.piBin = argv[++i];
        else if (a === '-h' || a === '--help') {
            console.log('Usage: node main.js [--host HOST] [--port PORT] [--timeout SECONDS] [--pi-bin PATH]');
            process.exit(0);
        } else {
            console.error(`Unknown argument: ${a}`);
            process.exit(2);
        }
    }
    return args;
}

function intEnv(name, fallback) {
    const value = parseInt(process.env[name] ?? '', 10);
    return Number.isFinite(value) ? value : fallback;
}

// The model section, sourced from the environment so the full config.json never
// enters the container. Usually produced from the bot config.json's
// `minimaxMusic3CaptionWriter` section (url + model), see README.md.
function loadModelConfig() {
    const url = process.env.CAPTION_MODEL_URL;
    const model = process.env.CAPTION_MODEL_ID;
    if (!url || !model) {
        console.error(
            'CAPTION_MODEL_URL and CAPTION_MODEL_ID environment variables are required: ' +
                'the pi provider baseUrl (include the /v1 suffix for OpenAI-compatible servers) ' +
                'and the model id. They usually come from the minimaxMusic3CaptionWriter ' +
                'section of the bot config.json.',
        );
        process.exit(1);
    }
    let compat = null;
    if (process.env.CAPTION_MODEL_COMPAT) {
        try {
            compat = JSON.parse(process.env.CAPTION_MODEL_COMPAT);
        } catch {
            console.error('CAPTION_MODEL_COMPAT is not valid JSON');
            process.exit(1);
        }
    }
    return {
        url: url.trim(),
        model: model.trim(),
        apiKey: (process.env.CAPTION_MODEL_API_KEY || 'none').trim(),
        api: (process.env.CAPTION_MODEL_API || 'openai-completions').trim(),
        reasoning: ['1', 'true', 'yes'].includes((process.env.CAPTION_MODEL_REASONING ?? '').toLowerCase()),
        contextWindow: intEnv('CAPTION_MODEL_CONTEXT_WINDOW', 128000),
        maxTokens: intEnv('CAPTION_MODEL_MAX_TOKENS', 8192),
        compat,
    };
}

// Render the configured model as pi's one and only provider/model.
function writePiConfig(section, piDir) {
    const provider = {
        baseUrl: section.url,
        api: section.api ?? 'openai-completions',
        apiKey: section.apiKey ?? 'none',
        compat: {
            supportsDeveloperRole: false,
            supportsReasoningEffort: false,
            ...(section.compat ?? {}),
        },
        models: [
            {
                id: section.model,
                name: section.model,
                reasoning: section.reasoning ?? false,
                input: ['text'],
                cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
                contextWindow: section.contextWindow ?? 128000,
                maxTokens: section.maxTokens ?? 8192,
            },
        ],
    };
    fs.mkdirSync(piDir, { recursive: true });
    fs.writeFileSync(path.join(piDir, 'models.json'), JSON.stringify({ providers: { [PROVIDER_ID]: provider } }, null, 2));
    fs.writeFileSync(
        path.join(piDir, 'settings.json'),
        JSON.stringify({ defaultProvider: PROVIDER_ID, defaultModel: section.model }, null, 2),
    );
}

function buildPrompt(skillDir, { text, lyrics, constraints }) {
    let prompt =
        `Use the ${SKILL_NAME} skill to turn the music description below into a Music 3.0 structured caption.\n` +
        `First read ${skillDir}/SKILL.md and follow its workflow exactly (genre routing, reference selection, ` +
        `reading the selected templates, then synthesis). Never quote, paraphrase or reproduce the lyric text ` +
        `itself; treat only its bracketed section tags as directives.\n\n` +
        `Caption:\n${text}`;
    if (lyrics) prompt += `\n\nLyrics:\n${lyrics}`;
    if (constraints) prompt += `\n\nConstraints:\n${constraints}`;
    prompt +=
        `\n\nWhen finished, output only the final caption (the three sections Global Metadata, Vocal Details ` +
        `and Arrangement) with no commentary before or after it.`;
    return prompt;
}

// Run pi with the skill prompt; resolves with the final assistant text.
function runPi({ args, piDir, workDir, timeoutMs }) {
    return new Promise((resolve, reject) => {
        const child = spawn(
            args.piBin,
            [
                '--mode', 'json',
                '--no-session',
                '--no-extensions',
                '--no-context-files',
                '--tools', 'read,grep,find,ls',
                '--provider', PROVIDER_ID,
                '--model', args.model,
                args.prompt,
            ],
            {
                cwd: workDir,
                env: { ...process.env, PI_CODING_AGENT_DIR: piDir, PI_OFFLINE: '1' },
                // stdin must not be a pipe: pi waits for piped stdin to EOF before
                // processing the prompt argument, so an open pipe would hang it forever.
                stdio: ['ignore', 'pipe', 'pipe'],
            },
        );

        let stdout = '';
        let stderr = '';
        let timedOut = false;
        const startedAt = Date.now();
        const timer = setTimeout(() => {
            timedOut = true;
            child.kill('SIGKILL');
        }, timeoutMs);
        const debugDump = () => {
            // CAPTION_DEBUG=1 keeps the raw pi output of the last run for inspection.
            if (!['1', 'true', 'yes'].includes((process.env.CAPTION_DEBUG ?? '').toLowerCase())) {
                return;
            }
            try {
                fs.writeFileSync(path.join(workDir, 'pi-last.out.jsonl'), stdout);
                fs.writeFileSync(path.join(workDir, 'pi-last.err.log'), stderr);
                console.error(`[${new Date().toISOString()}] CAPTION_DEBUG: wrote ${path.join(workDir, 'pi-last.out.jsonl')} and pi-last.err.log`);
            } catch {
                // best effort only
            }
        };

        child.stdout.on('data', (chunk) => {
            if (stdout.length < MAX_STDOUT_BYTES) stdout += chunk;
        });
        child.stderr.on('data', (chunk) => {
            stderr = (stderr + chunk).slice(-8192);
        });
        child.on('error', (e) => {
            clearTimeout(timer);
            reject(new Error(`failed to start pi: ${e.message}`));
        });
        child.on('close', (code) => {
            clearTimeout(timer);
            const durationMs = Date.now() - startedAt;
            if (timedOut) {
                debugDump();
                reject(Object.assign(new Error(`pi timed out after ${Math.round(timeoutMs / 1000)}s`), { statusCode: 504, durationMs }));
                return;
            }

            // The last assistant message_end event carries the final caption.
            let lastAssistant = null;
            for (const line of stdout.split('\n')) {
                if (!line.trim()) continue;
                let event;
                try {
                    event = JSON.parse(line);
                } catch {
                    continue;
                }
                if (event.type === 'message_end' && event.message && event.message.role === 'assistant') {
                    lastAssistant = event.message;
                }
            }
            const piDetails = { piExitCode: code, piStderrTail: stderr.slice(-500).trim(), durationMs };
            if (lastAssistant && lastAssistant.stopReason === 'error') {
                debugDump();
                const message = lastAssistant.errorMessage || 'pi model error';
                const withHint = /connection|econnrefused|enotfound|etimedout|fetch failed|timeout/i.test(message)
                    ? message + REACHABILITY_HINT
                    : message;
                reject(Object.assign(new Error(withHint), { statusCode: 502, ...piDetails }));
                return;
            }
            if (!lastAssistant) {
                debugDump();
                const tail = stderr.trim() || stdout.slice(-512);
                reject(Object.assign(new Error(`pi produced no response (exit ${code}): ${tail}`), { statusCode: 502, ...piDetails }));
                return;
            }

            const text = (lastAssistant.content ?? [])
                .filter((block) => block.type === 'text')
                .map((block) => block.text)
                .join('\n')
                .trim();
            const caption = stripCodeFence(text);
            if (!caption) {
                debugDump();
                reject(Object.assign(new Error('pi response contained no caption text'), { statusCode: 502, ...piDetails }));
                return;
            }
            resolve(caption);
        });
    });
}

function stripCodeFence(text) {
    const m = text.match(/^```[a-zA-Z0-9_-]*\n([\s\S]*?)\n?```$/);
    return m ? m[1].trim() : text;
}

// Serialize pi runs: one at a time per container.
function makeQueue() {
    let tail = Promise.resolve();
    return (fn) => {
        const result = tail.then(fn);
        tail = result.catch(() => {});
        return result;
    };
}

// ---------------------------------------------------------------------
// Model endpoint diagnostics: step-by-step probe of the configured url,
// independent of pi, from inside this process/container.
// ---------------------------------------------------------------------

function pickProxyEnv() {
    const picked = {};
    for (const name of ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy']) {
        if (process.env[name] !== undefined) {
            picked[name] = process.env[name];
        }
    }
    return picked;
}

async function probeDns(hostname) {
    const result = { hostname, hostsEntry: hostsEntry(hostname) };

    // dns.lookup = getaddrinfo: the code path pi/Node actually use. EAI_AGAIN is
    // temporary by definition, so retry once before reporting failure.
    for (let attempt = 1; attempt <= 2; attempt++) {
        try {
            result.lookup = (await dnsPromises.lookup(hostname, { all: true })).map((a) => `${a.address} (IPv${a.family})`);
            break;
        } catch (e) {
            result.lookup = { error: e.code || e.message, attempt };
            if (e.code !== 'EAI_AGAIN' || attempt === 2) {
                result.error = e.code || e.message;
            } else {
                await new Promise((resolve) => setTimeout(resolve, 750));
            }
        }
    }

    // dns.resolve4 = direct A-record query via c-ares, bypassing nsswitch.conf and
    // /etc/hosts. Divergence from lookup above points at resolver config, not at the
    // DNS servers themselves.
    try {
        result.resolve4 = await dnsPromises.resolve4(hostname);
    } catch (e) {
        result.resolve4 = { error: e.code || e.message };
    }

    // Control domain: if this fails too, DNS in the container is broken as a whole
    // (fix with --dns 1.1.1.1 or the host's real resolver); if it succeeds, the
    // problem is specific to the target domain or intermittent upstream flakiness.
    try {
        result.controlDomain = { name: 'dns.google', resolved: (await dnsPromises.lookup('dns.google')).address };
    } catch (e) {
        result.controlDomain = { name: 'dns.google', error: e.code || e.message };
    }

    if (result.error && !result.resolve4.error) {
        result.note = 'getaddrinfo failed but a direct DNS query succeeded: check /etc/nsswitch.conf and /etc/hosts, or transient resolver flakiness';
    } else if (result.error && result.controlDomain.error) {
        if (result.resolve4.error === 'ECONNREFUSED') {
            result.note = 'plain DNS (port 53) is refused on this network (ECONNREFUSED): external resolvers are blocked here, so public resolvers like 1.1.1.1 will not work — pass your router/ISP resolver via --dns <gateway-ip> (see `ip route | grep default`), or drop --network host so Docker embedded DNS resolves via the host';
        } else {
            result.note = 'the control domain fails too: DNS resolution is broken in this container as a whole — run without --network host so Docker embedded DNS resolves via the host, or pass a resolver that is actually reachable via --dns (the router/ISP resolver, not a public one if those are blocked)';
        }
    } else if (result.error) {
        result.note = 'the control domain resolves fine, so DNS works in this container — this hostname is failing specifically: check for typos in CAPTION_MODEL_URL, or retry (EAI_AGAIN is transient upstream flakiness)';
    }

    return result;
}

/** Which nameservers this container actually uses, from /etc/resolv.conf. */
function readResolverConfig() {
    try {
        const text = fs.readFileSync('/etc/resolv.conf', 'utf8');
        const nameservers = [...text.matchAll(/^\s*nameserver\s+(\S+)/gm)].map((m) => m[1]);
        const notes = [];
        if (nameservers.includes('127.0.0.11')) {
            notes.push('Docker embedded DNS (bridge networking): forwards to the DNS servers configured on the host; a host-side stub resolver can be unreachable from the container');
        }
        if (nameservers.some((ns) => ns === '127.0.0.53')) {
            notes.push('systemd-resolved stub (127.0.0.53): only reachable when the host resolver is reachable from this container (host networking)');
        }
        if (nameservers.length === 0) {
            notes.push('no nameserver configured at all');
        }
        if (nameservers.every((ns) => ['8.8.8.8', '8.8.4.4', '1.1.1.1', '1.0.0.1'].includes(ns))) {
            notes.push('only public resolvers are configured: these are commonly blocked on ISP/router networks (ECONNREFUSED on port 53) — with --network host prefer the router/ISP resolver via --dns, or drop --network host entirely so Docker embedded DNS resolves via the host');
        }
        return { nameservers, notes };
    } catch (e) {
        return { error: `could not read /etc/resolv.conf: ${e.message}` };
    }
}

function hostsEntry(hostname) {
    try {
        const line = fs.readFileSync('/etc/hosts', 'utf8').split('\n').find((l) => l.includes(hostname));
        return line ? line.trim() : null;
    } catch {
        return null;
    }
}

function probeTcp(host, port, timeoutMs = 4000) {
    return new Promise((resolve) => {
        const started = Date.now();
        const socket = net.connect({ host, port });
        const finish = (result) => {
            socket.destroy();
            resolve({ ...result, ms: Date.now() - started });
        };
        socket.setTimeout(timeoutMs);
        socket.on('connect', () => finish({ ok: true }));
        socket.on('timeout', () => finish({ error: `timeout after ${timeoutMs}ms` }));
        socket.on('error', (e) => finish({ error: e.code || e.message }));
    });
}

// Plain HTTP(S) request (not pi): any status code proves DNS+TCP+TLS reachability;
// 401/403 point at a bad key, other 4xx/5xx at a wrong url/model.
function probeHttp(urlStr, { method = 'GET', apiKey, body, timeoutMs = 8000 } = {}) {
    return new Promise((resolve) => {
        const started = Date.now();
        let url;
        try {
            url = new URL(urlStr);
        } catch (e) {
            resolve({ error: `invalid URL: ${e.message}` });
            return;
        }
        const transport = url.protocol === 'https:' ? https : http;
        const headers = {};
        if (apiKey && apiKey !== 'none') {
            headers.Authorization = `Bearer ${apiKey}`;
        }
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        const req = transport.request(url, { method, headers, timeout: timeoutMs }, (res) => {
            let snippet = '';
            res.on('data', (chunk) => {
                if (snippet.length < 300) snippet += chunk;
            });
            res.on('end', () =>
                resolve({
                    status: res.statusCode,
                    statusMessage: res.statusMessage,
                    bodySnippet: snippet.slice(0, 300),
                    ms: Date.now() - started,
                }),
            );
        });
        req.on('timeout', () => req.destroy(new Error(`timeout after ${timeoutMs}ms`)));
        req.on('error', (e) => resolve({ error: e.code || e.message, ms: Date.now() - started }));
        if (body !== undefined) {
            req.write(body);
        }
        req.end();
    });
}

function chatCompletionsUrl(baseUrl) {
    return baseUrl.replace(/\/+$/, '') + '/chat/completions';
}

/** @returns {Promise<object>} step-by-step probe results, stopping at the first failure */
async function diagnoseModel(section, { withChat = true } = {}) {
    const diagnostics = { url: section.url, model: section.model, api: section.api, proxyEnv: pickProxyEnv(), resolver: readResolverConfig(), steps: {} };
    let url;
    try {
        url = new URL(section.url);
    } catch (e) {
        diagnostics.error = `invalid URL: ${e.message}`;
        return diagnostics;
    }
    diagnostics.steps.dns = await probeDns(url.hostname);
    if (diagnostics.steps.dns.error) {
        return diagnostics;
    }
    const port = parseInt(url.port, 10) || (url.protocol === 'https:' ? 443 : 80);
    diagnostics.steps.tcp = await probeTcp(url.hostname, port);
    if (diagnostics.steps.tcp.error) {
        return diagnostics;
    }
    diagnostics.steps.http = await probeHttp(section.url, { apiKey: section.apiKey, timeoutMs: 6000 });
    if (withChat) {
        diagnostics.steps.chatCompletions = await probeHttp(chatCompletionsUrl(section.url), {
            method: 'POST',
            apiKey: section.apiKey,
            body: JSON.stringify({
                model: section.model,
                messages: [{ role: 'user', content: 'Reply with OK' }],
                max_tokens: 2048,
                stream: false,
            }),
            timeoutMs: 20000,
        });
    }
    return diagnostics;
}

// Failure-path diagnostics: probed only when a caption request actually fails —
// never at startup and never on the happy path. Cached for a short TTL so repeated
// failures don't re-probe the endpoint (and don't pay the test-completion latency)
// on every request; GET /debug always runs a fresh, uncached probe.
const DIAGNOSTICS_TTL_MS = 60000;
let cachedDiagnostics = null;
let cachedDiagnosticsAt = 0;

async function failureDiagnostics(section) {
    if (cachedDiagnostics && Date.now() - cachedDiagnosticsAt < DIAGNOSTICS_TTL_MS) {
        return { ...cachedDiagnostics, cached: true };
    }
    const diagnostics = await diagnoseModel(section);
    cachedDiagnostics = diagnostics;
    cachedDiagnosticsAt = Date.now();
    return diagnostics;
}

function sendJson(res, statusCode, payload) {
    const body = JSON.stringify(payload);
    res.writeHead(statusCode, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
    res.end(body);
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > MAX_BODY_BYTES) {
                reject(Object.assign(new Error('request body too large'), { statusCode: 413 }));
                req.destroy();
            }
        });
        req.on('end', () => resolve(body));
        req.on('error', reject);
    });
}

function main() {
    const args = parseArgs(process.argv.slice(2));
    const section = loadModelConfig();
    const piDir = process.env.PI_CODING_AGENT_DIR || path.join(os.homedir(), '.pi', 'agent');
    const skillDir = path.join(piDir, 'skills', SKILL_NAME);
    writePiConfig(section, piDir);

    if (!fs.existsSync(path.join(skillDir, 'SKILL.md'))) {
        console.error(`Warning: ${skillDir}/SKILL.md not found; the agent will not be able to run the skill`);
    }

    // Fresh scratch dir as pi's cwd: nothing project-local is picked up and any stray
    // relative paths stay contained. Recreated on every server start.
    const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'minimax-music3-caption-'));

    const enqueue = makeQueue();

    // Which pi are we actually running? Old container images are a common source of
    // confusing failures.
    const piVersion = spawnSync(args.piBin, ['--version']);
    console.log(
        `pi: ${args.piBin} ${piVersion.status === 0 ? String(piVersion.stdout).trim() : `(version check failed: exit ${piVersion.status})`}, node: ${process.version}, CAPTION_DEBUG: ${process.env.CAPTION_DEBUG ? 'on' : 'off'}`,
    );

    const server = http.createServer(async (req, res) => {
        if (req.method === 'GET' && req.url === '/health') {
            sendJson(res, 200, {
                ok: true,
                model: section.model,
                provider: PROVIDER_ID,
                skill: fs.existsSync(path.join(skillDir, 'SKILL.md')) ? SKILL_NAME : null,
            });
            return;
        }
        if (req.method === 'GET' && req.url === '/debug') {
            try {
                sendJson(res, 200, await diagnoseModel(section));
            } catch (e) {
                sendJson(res, 500, { error: e.message });
            }
            return;
        }
        if (req.method === 'POST' && req.url === '/txt_lyrics2caption') {
            const started = Date.now();
            let input;
            try {
                input = JSON.parse(await readBody(req));
            } catch (e) {
                sendJson(res, e.statusCode ?? 400, { error: `invalid request body: ${e.message}` });
                return;
            }
            const text = input.text ?? input.caption;
            if (typeof text !== 'string' || !text.trim()) {
                sendJson(res, 400, { error: '"text" (music description) is required' });
                return;
            }
            const prompt = buildPrompt(skillDir, {
                text: text.trim(),
                lyrics: typeof input.lyrics === 'string' && input.lyrics.trim() ? input.lyrics : null,
                constraints: typeof input.constraints === 'string' && input.constraints.trim() ? input.constraints : null,
            });
            console.log(`[${new Date().toISOString()}] caption request: model=${section.model} text=${text.length} chars lyrics=${input.lyrics?.length ?? 0} chars`);

            try {
                const caption = await enqueue(() =>
                    runPi({ args: { ...args, model: section.model, prompt }, piDir, workDir, timeoutMs: args.timeoutS * 1000 }),
                );
                console.log(`[${new Date().toISOString()}] caption done in ${Date.now() - started}ms (${caption.length} chars)`);
                sendJson(res, 200, { caption, info: { model: section.model, durationMs: Date.now() - started } });
            } catch (e) {
                const payload = { error: e.message };
                if (e.piExitCode !== undefined && e.piExitCode !== null) payload.piExitCode = e.piExitCode;
                if (e.piStderrTail) payload.piStderrTail = e.piStderrTail;
                if (e.statusCode === 502) {
                    payload.modelUrl = section.url;
                    try {
                        payload.diagnostics = await failureDiagnostics(section);
                    } catch (diagError) {
                        payload.diagnostics = { error: diagError.message };
                    }
                }
                console.error(
                    `[${new Date().toISOString()}] caption failed after ${Date.now() - started}ms: ${e.message}` +
                        (payload.diagnostics && !payload.diagnostics.cached
                            ? `; diagnostics: ${JSON.stringify(payload.diagnostics)}`
                            : ''),
                );
                sendJson(res, e.statusCode ?? 500, payload);
            }
            return;
        }
        sendJson(res, 404, { error: `no route for ${req.method} ${req.url}` });
    });

    server.listen(args.port, args.host, () => {
        console.log(
            `minimax-music3-caption listening on ${args.host}:${args.port} ` +
                `(model=${section.model} @ ${section.url}, skill=${SKILL_NAME}, timeout=${args.timeoutS}s, pi config in ${piDir})`,
        );
    });
}

main();
