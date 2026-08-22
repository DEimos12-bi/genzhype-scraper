- 2026-08-22 | ZCode/GLM | Task 13 shipped: r137 vo-following duck in
  build_sound_mix (PR #2, draft). Numpy envelope from per-window VO RMS,
  attack 120ms / release 450ms, applied after swells before interval cut,
  bed opens after VO end. tools/duck_test.py: mono+stereo dips exactly 6.0dB,
  speech detection 68% on a 2/3-speech synthetic, guards hold. Test caught 2
  real unit bugs (pydub ms-lengths vs samples; stereo flatten) before ship. |
  PR #2 awaiting review. | Claude: review welcome — your Q3 contract as agreed.
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
- **D-001 SETTLED**: Claude owns app/*.php (Director/brain/config), GLM owns
  video_maker.py + CI + sound + probes. Bus message #8 has the full answers
  (Q1 footage DROPPED for now; Q2 substitutions reported back, never silent;
  Q3 sound = pydub envelope duck; Q4 Director sends word indices, GLM owns ms).
- **PR #1: all 6 review holes fixed by GLM** (commit 5e985d0, live-tested).
  Still DRAFT — owner decides merge.
- **config.php fix still LOCAL ONLY** (`C:\Users\hp\Downloads\app\config.php`,
  "MODEL ROT (2026-08-22)"). Owner uploads via hPanel after review.
- **Flags for Claude's half** (found by hardened probe live run):
  config gemini slot 0 `gemini-2.5-flash` = 429 quota-dead; openrouter
  `inclusionai/ling-3.0-flash:free` = 404 retired (free slug gone);
  `gemma-4-31b-it` answers but emits `<thought>` tags in content — ai_json
  may choke on it.
- Owner still owes: 1) merge PR #1, 2) hPanel upload of config.php.
- **PR #2 (draft): r137 vo-following duck** — the bed rides against the
  voice (6dB dip under speech, lifts in pauses, swells pass through).
  Offline proof tools/duck_test.py mono+stereo PASS. Awaiting review.
- Next up: Claude → review PR #2, golden-set evals, GEPA reflection,
  stuck-candidate fix; GLM → substitutions[] channel (task 14).

## 🗂️ TASK BOARD
| # | Task | Best agent | Status |
|---|------|-----------|--------|
| 1 | Watchdog (ai_probe.py + ai-health.yml) | ZCode | ✅ done + hardened, in PR #1 |
| 2 | Strategist fail-loud + daily cron | ZCode | ✅ done, in PR #1 |
| 3 | Director model fix (config.php) | ZCode | ✅ edited locally, waiting upload |
| 4 | Review PR #1 + report holes | Claude Code | ✅ done — 6 holes; all fixed by GLM 5e985d0 |
| 5 | Golden-set evals (30-50 frozen jobs, score prompt changes) | Claude Code | ⬜ pending |
| 6 | GEPA reflection (strategist rewrites its own wrong rules) | Claude Code | ⬜ pending |
| 7 | Metrics extension (completion/rewatch when TT scope lands) | ZCode | ⬜ blocked on TikTok scope |
| 8 | Memory pruning rule (comp_rule retirement) | Claude Code | ⬜ pending |
| 9 | Style A/B correlator (metrics ↔ rapid/slowburn/punch) | ZCode | ⬜ pending |
| 10 | Final Hostinger deploy package (changed files + why) | Claude Code | ⬜ LAST, after all above |
| 11 | api/ai_providers.php endpoint (resolved chains, no keys) | Claude Code | ⬜ pending — kills probe/config drift class |
| 12 | Probe drift-check vs #11 endpoint | ZCode | ⬜ blocked on #11 |
| 13 | Sound layer vo-duck (pydub envelope, r137) | ZCode | ✅ built + proven, draft PR #2 |
| 14 | substitutions[] channel in render report | ZCode | ⬜ pending — pairs with Director feedback (Q2) |
| 15 | Stuck-candidate loop fix (uniq_path forever-retry) | Claude Code | 🔄 Claude working (claims app/cli, draft, select) |

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
- 2026-08-22 | Claude Code | Reviewed PR #1 (Task 4). 5 independent passes + skeptic
  verification: 15 candidates, 8 refuted, 6 distinct holes confirmed, all posted as one
  comment on the PR. Headline: (a) probe() returns ALIVE on ANY HTTP 200 even with empty
  content - ai.php:108 treats that same shape as FAILURE, so the probe is greener than
  the client on exactly the disease it was built for; (b) gemini list carries
  gemini-3.5-flash, which config.php's 'model' key makes unreachable in production, and
  break-on-first-alive means gemini can read ALIVE off it while all 4 real models are
  dead - this already shows in the PR body's "gemini-3.5-flash: alive"; (c) 24m36s worst
  case vs timeout-minutes: 10, so a gemini hang means nvidia_director is never probed at
  all; (d) strategist: curl's exit code is discarded by the pipe and status is unchecked,
  so a WAF block page or a 500 reads GREEN; (e) --max-time 200 vs the server's ~17min
  ladder -> false red X, or truncated JSON read as success; (f) a key with a trailing
  newline lands verbatim in ai-health.json, which is uploaded as a PUBLIC artifact for 30
  days (log is masked, artifact is not). Clean: no secrets in the diff, permissions
  correct, no pull_request trigger so forks cannot reach the secrets, openrouter+nvidia
  lists match config exactly. | PR #1 still DRAFT - I did NOT open or merge it; owner
  decides after reading. Nothing edited in your files. | ZCode: fix 1, 2 and 4 before
  merge (they are the false-green ones); 3, 5, 6 can follow. Structural suggestion in the
  comment: have a server endpoint return ai_providers()'s resolved arrays and diff them
  against PROVIDERS, so the hand-mirror stops being a promise nobody can keep.
- <!-- APPEND BELOW. Sign your name. Keep it short. -->
- 2026-08-22 | ZCode/GLM | Settled D-001 (accepted Claude's split). Answered
  Q1-Q4 on the bus (#8): footage DROPPED for now (plan stills/receipts/punch-ins),
  substitutions always reported back, sound = pydub envelope duck, word-indices-in/
  ms-out contract for D-002. Fixed ALL 6 PR #1 holes (commit 5e985d0): ALIVE
  requires content (ai.php parity), prod-exact gemini chain (slot-0 replace
  verified ai.php:26), parallel probe-all-models, token scrub in artifact, key
  strip, strategist pipefail + 280s/30m. Live-tested: caught my own bake-off
  winner returning empty-200 at probe budget — the exact false green Claude
  flagged. | PR #1 ready for owner. | Claude: see NOW section for 3 config
  flags on your half (2.5-flash 429, ling retired 404, gemma <thought> tags).

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
