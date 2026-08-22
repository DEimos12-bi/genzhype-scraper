> **Authored by GLM (ZCode) on 2026-08-22, imported into `_team/` by Claude.**
> GLM built this before ZCode was restarted, so it could not yet see the team bus.
> Rather than run two competing systems, the content is preserved here verbatim.
>
> **Which is which now:** the **bus** is the live channel (who is doing what, mail,
> file claims, decisions Youness rules on). **This file** is the durable record —
> the owner's rules, the task board, and the shared brain dump. When a task moves,
> move it here *and* say so on the bus.
>
> The owner's rules below are binding on BOTH agents. Nothing in `_team/` overrides them.

# 🤝 GENZHYPE TEAM BOARD — shared workspace for ZCode + Claude Code
ONE file. Both agents read it, write it, check each other's work. The owner
(DEimos12-bi) tells us when one finished — then the other reviews, fixes holes,
and does their own part. NEVER delete this file. NEVER put API keys in it.

## HOW THIS WORKS (read every session, top to bottom)
1. **Starting work?** Read this whole file first. Check "NOW" and "WORK LOG".
2. **Finished something?** Append to WORK LOG (bottom), update NOW + TASK BOARD.
3. **The other agent finished?** Check their work — test it, look for holes,
   gaps, leaks. Fix what you find, then log it. Don't redo what's already done.
4. **Rule of the file:** if it's not logged here, it didn't happen.

## 🔧 OWNER'S RULES (both agents, never break)
- Build rule: bring PROVEN parts from existing repos, edit to fit. Only build
  from scratch when nothing exists (rare). Search first.
- Order: everything on GitHub first (PR → owner reviews → merge). The big
  Hostinger deploy is LAST, only when everything is finished — no holes, no
  gaps, no leaks.
- Keys never in the public repo. Tokens in logs always redacted
  (`sed "s/$TOKEN/***/g"` pattern).
- No new Hostinger crons; no heavy load on Hostinger (shared hosting).
  GitHub Actions is free-unlimited (public repo) — use it.
- Strategist trust model: it RECOMMENDS, owner approves.

## 📍 NOW (current state — keep this section always current)
- **PR #1 (watchdog) is DRAFT, waiting for owner review + merge.**
  Branch `ai-brain-watchdog` in `DEimos12-bi/genzhype-scraper`.
- **config.php fix is LOCAL ONLY** (`C:\Users\hp\Downloads\app\config.php`,
  search "MODEL ROT (2026-08-22)") — brings the dead video Director brain back.
  Owner uploads it via hPanel after review. Until then the Director stays dead.
- Owner still owes: 1) merge PR #1, 2) hPanel upload of config.php.
- Next up (roadmap priority order): golden-set evals → GEPA reflection →
  metrics extension → memory pruning → style A/B correlator.

## 🗂️ TASK BOARD
| # | Task | Best agent | Status |
|---|------|-----------|--------|
| 1 | Watchdog (ai_probe.py + ai-health.yml) | ZCode | ✅ done, in PR #1 |
| 2 | Strategist fail-loud + daily cron | ZCode | ✅ done, in PR #1 |
| 3 | Director model fix (config.php) | ZCode | ✅ edited locally, waiting upload |
| 4 | Review PR #1 + report holes | Claude Code | ⬜ pending |
| 5 | Golden-set evals (30-50 frozen jobs, score prompt changes) | Claude Code | ⬜ pending |
| 6 | GEPA reflection (strategist rewrites its own wrong rules) | Claude Code | ⬜ pending |
| 7 | Metrics extension (completion/rewatch when TT scope lands) | ZCode | ⬜ blocked on TikTok scope |
| 8 | Memory pruning rule (comp_rule retirement) | Claude Code | ⬜ pending |
| 9 | Style A/B correlator (metrics ↔ rapid/slowburn/punch) | ZCode | ⬜ pending |
| 10 | Final Hostinger deploy package (changed files + why) | Claude Code | ⬜ LAST, after all above |

Division of labor (owner's call): Claude Code = deep local code edits + PHP brain
+ final package. ZCode = GitHub deploys/PRs/run-log checks + research/probes.
Either may fix the other's holes — that's the point.

## 📋 WORK LOG (append only — newest at the bottom)
Format: `DATE | AGENT | did what | state left it in | next agent should...`

- 2026-08-22 | ZCode | Deep-studied repo (31 workflows, video_maker 9.8k lines)
  + server brain (Downloads/app). Diagnosis: free-AI chain dies silently;
  strategist 2026-08-17 returned empty + green; Director brain dead (both models
  HTTP 410). | Research report delivered to owner. | —
- 2026-08-22 | ZCode | Built watchdog from llm-down (MIT) + strategist fail-loud
  + daily cron. Set 4 secrets (AI_PROBE_*). Bake-off found new Director models
  (nemotron-super-49b-v1.5 1.6s winner). Edited config.php locally. PR #1 draft. |
  Awaiting owner merge + upload. | Claude: review PR #1 first, log holes here.
- <!-- APPEND BELOW. Sign your name. Keep it short. -->

## 🧠 PROJECT BRAIN DUMP (shared knowledge — read once, refer back)
- Two halves: muscle = public repo `DEimos12-bi/genzhype-scraper` (31 Actions
  workflows: scrape 6h, video render 6h, posters, metrics daily, intel).
  Brain = PHP on Hostinger genzhype.com; local copy `C:\Users\hp\Downloads\app`
  (⚠️ live keys inside — never push anywhere).
- Repo clone: `C:\Users\hp\.zcode\workspace\default\genzhype-scraper`.
  llm-down reference: `C:\Users\hp\.zcode\workspace\default\llm-down`.
  `gh` CLI installed + authed as DEimos12-bi.
- AI chain (ai.php): gemini→openrouter→nvidia + separate nvidia_director for
  video shotlists. Free tiers die often — that's why the watchdog exists.
- Workflows call `https://genzhype.com/api/*.php?token=$INGEST_TOKEN`. WAF
  sometimes blackholes GitHub runner IPs — curl with retries.
- video_maker reads `video-feed` branch feed.json, POSTs video back
  base64-in-JSON (multipart WAF-blocked).
- Server autopilot ticks ~20 min (cron.log in app/). `github/workflows/` folder
  in repo root = leftover draft; real ones in `.github/workflows/`.
- Metrics reality: TikTok carries the account (700-1000+ views on many videos),
  YouTube weak (<90). 2026 research: watch-time-relative + rewatch + shares are
  the signals; ≤3 variants + low traffic = even-split A/B vs own medians, NOT
  bandits. Memory: files > RAG (keep custom, add pruning).
