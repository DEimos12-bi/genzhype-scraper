#!/usr/bin/env python3
"""GenZHype | AI BRAIN HEALTH PROBE  (the immune system the brain never had).

WHY THIS EXISTS. Every incident that stopped the site publishing was the same
disease in a different organ: a FREE AI provider died quietly (Gemini 429/404
model rot 2026-08-22, OpenRouter free-tier retirements 2026-08-04, NVIDIA
HTTP 0 in cron.log) and nothing complained until the owner noticed days of
silence. ai_chat()'s fallback chain only saves you while ONE provider still
lives; when the last one drops, strategist_run() returns null, the endpoint
prints an empty body, the workflow stays green, and the machine looks healthy
while being brain-dead.

This probe runs on GitHub Actions (NOT on Hostinger — zero load on the
server) and answers one question every few hours: can the brain still think?
It pings every provider/model in the EXACT chains app/ai.php uses, and:

  - exit 0  -> at least the default chain (gemini/openrouter/nvidia) OR the
               director brain still answers. Partial deaths are warnings.
  - exit 1  -> the whole default chain is dead (drafter + strategist silent)
               or the director brain is dead (no new shotlists -> the video
               pipeline starves). The red X makes GitHub email the owner,
               so silent death is impossible again.

REVIEW HARDENING (2026-08-22, Claude's PR pass — the three false-greens):
  - ALIVE now requires HTTP 200 AND non-empty content: ai.php:108 treats an
    empty-content 200 as a failure and falls through, so the probe must not
    be greener than the client it mirrors. MAX_TOKENS 64 so reasoning models
    are not forced into empty replies (nvidia_director has no redundancy).
  - The gemini list mirrors PRODUCTION exactly: config's single 'model'
    REPLACES slot 0 of ai.php's default ladder (verified ai.php:26), so the
    live chain is [config.model, gemma-4-31b-it, flash-lite-latest,
    3.5-flash-lite] — gemini-3.5-flash is unreachable in prod and was removed
    after it produced a false ALIVE in this probe's first run.
  - ALL models per provider are probed (no break-on-first-alive): a provider
    living off its last model is exactly the "one quota reset away" state the
    owner needs to see. Providers run in parallel threads, so full-chain
    visibility still fits the job budget.
  - Keys are .strip()ed (a trailing newline from a secret paste both breaks
    auth AND echoes into artifacts) and every note is scrubbed of token
    shapes before the artifact write — ai-health.json uploads as a PUBLIC
    artifact; the log is masked, the artifact was not.
  - KNOWN DRIFT RISK, accepted for now: these lists hand-mirror app/config.php
    with nothing enforcing sync. Plan (board task): a token-gated
    api/ai_providers.php returns the RESOLVED ai_providers() arrays (no keys)
    and this probe diffs itself against it every run.

ADAPTED FROM llm-down (MIT, github.com/menhao8888/llm-down): the urllib
request core and per-model chat/completions ping are brought over nearly
verbatim; the web console / SQLite / responses / embeddings probing were
dropped — not needed in CI.

Keys come from repo secrets (AI_PROBE_*), never from files — this repo is
public; app/config.php stays the only home for the real values."""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

# --- the chains, mirrored from app/config.php + app/ai.php ------------------
# gemini: config 'model' REPLACES slot 0 of the ladder (ai.php:26). Rotate the
# models in BOTH places when config changes — until ai_providers.php lands.
PROVIDERS = {
    "gemini": {
        "url": "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
        "key_env": "AI_PROBE_GEMINI",
        "models": ["gemini-2.5-flash", "gemma-4-31b-it",
                   "gemini-flash-lite-latest", "gemini-3.5-flash-lite"],
        "critical": True,   # in ai_chat's default fallback order
    },
    "openrouter": {
        "url": "https://openrouter.ai/api/v1/chat/completions",
        "key_env": "AI_PROBE_OPENROUTER",
        "models": ["nvidia/nemotron-3-super-120b-a12b:free",
                   "inclusionai/ling-3.0-flash:free"],
        "critical": True,
    },
    "nvidia": {
        "url": "https://integrate.api.nvidia.com/v1/chat/completions",
        "key_env": "AI_PROBE_NVIDIA",
        "models": ["meta/llama-3.3-70b-instruct", "meta/llama-3.2-90b-vision-instruct"],
        "critical": True,
    },
    "nvidia_director": {
        "url": "https://integrate.api.nvidia.com/v1/chat/completions",
        "key_env": "AI_PROBE_NVIDIA_DIRECTOR",
        # 2026-08-22: deepseek-v4-pro + glm-5.2 both 410 end-of-life (found by
        # this probe's first live run). Bake-off winners on the live catalog:
        # nemotron-super-49b-v1.5 1.6s, mistral-nemotron 1.7s, kimi-k3 20.3s.
        "models": ["nvidia/llama-3.3-nemotron-super-49b-v1.5",
                   "mistralai/mistral-nemotron", "moonshotai/kimi-k3"],
        "critical": True,   # sole brain of video_write_shotlist
    },
}
TIMEOUT = 60          # reasoning models (deepseek) think before the first token
MAX_TOKENS = 64       # enough that reasoning models still emit visible content
RETRIES = 1

# ai-health.json uploads as a PUBLIC artifact: scrub token shapes, always.
_TOKEN_SHAPES = re.compile(r"(nvapi-[\w-]+|sk-or-[\w-]+|AIza[\w-]+|AQ\.[\w.-]+|Bearer\s+[\w.-]+)")


def log(*a):
    print(*a, flush=True)


def scrub(s):
    return _TOKEN_SHAPES.sub("[REDACTED]", s)[:160]


def probe(base_url, key, model, retries=RETRIES):
    """One chat/completions ping (llm-down's probe core, hardened).
    ALIVE mirrors ai.php: 200 with EMPTY content is a fall-through, not life.
    Returns (ok, http_code, note)."""
    body = json.dumps({
        "model": model,
        "messages": [{"role": "user", "content": "Reply with exactly: OK"}],
        "max_tokens": MAX_TOKENS,
    }).encode()
    last = (False, 0, "no attempt")
    for attempt in range(1, retries + 2):
        req = urllib.request.Request(
            base_url, data=body, method="POST",
            headers={"Authorization": f"Bearer {key}",
                     "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
                d = json.load(r)
                choice = (d.get("choices") or [{}])[0]
                txt = (choice.get("message", {}).get("content", "") or "").strip()
                finish = choice.get("finish_reason", "")
                if txt:
                    return (True, r.status, f"replied {txt[:20]!r}")
                # 200 with no content: ai.php falls through on this exact
                # shape, so we must too — no false greens on the director.
                return (False, r.status,
                        f"empty content (finish_reason={finish or 'n/a'})")
        except urllib.error.HTTPError as e:
            detail = ""
            try:
                detail = e.read().decode(errors="replace")
            except Exception:  # noqa: BLE001
                pass
            last = (False, e.code, detail or e.reason)
        except Exception as e:  # noqa: BLE001  # HTTP 0 / timeouts, the cron.log disease
            last = (False, 0, str(e)[:120])
        if attempt <= retries:
            time.sleep(3)
    return last


def probe_provider(name, p):
    """Probe EVERY model in the chain (parallel across providers, sequential
    within) — a provider living off its last model must be visible as such."""
    key = (os.environ.get(p["key_env"]) or "").strip()
    if not key:
        log(f"[{name}] no key in secrets — SKIPPED (treat as dead: ai.php "
            f"skips keyless providers too)")
        return name, {"alive": False, "reason": "no key", "models": []}
    models = []
    for m in p["models"]:
        ok, code, note = probe(p["url"], key, m)
        log(f"[{name}] {m}: {'ALIVE' if ok else 'DEAD'} (HTTP {code}) {scrub(note)}")
        models.append({"model": m, "ok": ok, "http": code, "note": scrub(note)})
    return name, {"alive": any(m["ok"] for m in models), "models": models}


def main():
    with ThreadPoolExecutor(max_workers=len(PROVIDERS)) as ex:
        futures = [ex.submit(probe_provider, n, p) for n, p in PROVIDERS.items()]
        results = dict(f.result() for f in futures)

    default_chain = [n for n, p in PROVIDERS.items()
                     if p["critical"] and n != "nvidia_director"]
    chain_alive = [n for n in default_chain if results.get(n, {}).get("alive")]
    director_alive = results.get("nvidia_director", {}).get("alive", False)

    with open("ai-health.json", "w", encoding="utf-8") as f:
        json.dump({"checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                   "default_chain_alive": chain_alive,
                   "director_alive": director_alive,
                   "providers": results}, f, indent=1)

    for n in PROVIDERS:   # per-provider survivor count: last-model life is a warning
        alive_models = sum(1 for m in results.get(n, {}).get("models", []) if m["ok"])
        total = len(PROVIDERS[n]["models"])
        if 0 < alive_models <= 1 and total > 1:
            log(f"::warning::[{n}] living off {alive_models}/{total} models — "
                f"one deprecation away from a dead provider.")

    log(f"\nVERDICT: default chain alive for {len(chain_alive)}/{len(default_chain)} "
        f"({' + '.join(chain_alive) or 'NONE'}); director "
        f"{'ALIVE' if director_alive else 'DEAD'}")
    if not chain_alive:
        log("::error::BRAIN SILENT — every default provider is dead. The "
            "drafter and strategist cannot think. Rotate models/keys in "
            "app/config.php (see the MODEL ROT note in ai.php).")
        return 1
    if not director_alive:
        log("::error::DIRECTOR BRAIN DEAD — no new video shotlists can be "
            "written; the video pipeline will starve once the queue drains.")
        return 1
    if len(chain_alive) == 1:
        log("::warning::only one provider left standing — one quota reset "
            "away from a dead brain.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
