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
It pings every provider/model in the EXACT chains app/ai.php uses (config.php
values mirrored here — keep the two in sync when rotating models), and:

  - exit 0  -> at least the default chain (gemini/openrouter/nvidia) OR the
               director brain still answers. Partial deaths are warnings.
  - exit 1  -> the whole default chain is dead (drafter + strategist silent)
               or the director brain is dead (no new shotlists -> the video
               pipeline starves). The red X makes GitHub email the owner,
               so silent death is impossible again.

ADAPTED FROM llm-down (MIT, github.com/menhao8888/llm-down): the urllib
request core, per-model chat/completions ping and result-archiving pattern
are brought over nearly verbatim; the web console / SQLite / responses /
embeddings probing were dropped — not needed in CI. Its README's own claim
("automation-friendly for scripts and CI jobs") is the part we kept.

Keys come from repo secrets (AI_PROBE_*), never from files — this repo is
public; app/config.php stays the only home for the real values."""
import json
import os
import sys
import time
import urllib.error
import urllib.request

# --- the chains, mirrored from app/config.php + app/ai.php defaults ---------
# gemini: config 'model' prepends the ai.php default ladder (ai.php builds
# [config.model ?? 3.5-flash, gemma-4-31b-it, flash-lite-latest, 3.5-flash-lite])
PROVIDERS = {
    "gemini": {
        "url": "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
        "key_env": "AI_PROBE_GEMINI",
        "models": ["gemini-2.5-flash", "gemini-3.5-flash", "gemma-4-31b-it",
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
MAX_TOKENS = 16       # "OK" is enough; keep the free-quota cost near zero


def log(*a):
    print(*a, flush=True)


def probe(base_url, key, model, retries=1):
    """One chat/completions ping (llm-down's probe_chat_completions core).
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
                txt = ((d.get("choices") or [{}])[0]
                        .get("message", {}).get("content", "") or "").strip()
                return (True, r.status, f"replied {txt[:20]!r}")
        except urllib.error.HTTPError as e:
            detail = ""
            try:
                detail = e.read().decode(errors="replace")[:120]
            except Exception:  # noqa: BLE001
                pass
            last = (False, e.code, detail or e.reason)
        except Exception as e:  # noqa: BLE001  # HTTP 0 / timeouts, the cron.log disease
            last = (False, 0, str(e)[:120])
        if attempt <= retries:
            time.sleep(3)
    return last


def main():
    results = {}
    for name, p in PROVIDERS.items():
        key = os.environ.get(p["key_env"], "")
        if not key:
            log(f"[{name}] no key in secrets — SKIPPED (treat as dead: ai.php "
                f"skips keyless providers too)")
            results[name] = {"alive": False, "reason": "no key", "models": []}
            continue
        models = []
        for m in p["models"]:
            ok, code, note = probe(p["url"], key, m)
            log(f"[{name}] {m}: {'ALIVE' if ok else 'DEAD'} "
                f"(HTTP {code}) {note}")
            models.append({"model": m, "ok": ok, "http": code, "note": note})
            if ok:
                break   # ai_chat stops at the first model that answers, we mirror
        results[name] = {"alive": any(m["ok"] for m in models), "models": models}

    default_chain = [n for n, p in PROVIDERS.items()
                     if p["critical"] and n != "nvidia_director"]
    chain_alive = [n for n in default_chain if results.get(n, {}).get("alive")]
    director_alive = results.get("nvidia_director", {}).get("alive", False)

    with open("ai-health.json", "w", encoding="utf-8") as f:
        json.dump({"checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                   "default_chain_alive": chain_alive,
                   "director_alive": director_alive,
                   "providers": results}, f, indent=1)

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
