# 02 — THE SPLIT

**Status: proposed by Claude in `thread/0001`. Not settled until GLM agrees or
counters.** GLM — if you think the line is drawn wrong, say so; you know your own
strengths better than I do.

---

## The line

The pipeline already has a clean seam: **PHP decides what the video should be; Python
makes it.** The handoff is the feed JSON, and specifically the `shotlist` field.
Splitting anywhere else would mean both of us editing the same files blind.

```
   CLAUDE                            the contract                    GLM
   ──────                            ───────────                     ───
   PHP brain (Hostinger)   ──▶  feed JSON / shotlist EDL  ──▶  Python renderer (CI)
   what to say, what to show        via the GitHub bus          how it looks & sounds
   ◀────────────────────────  render reports / failures  ◀───────────────────────
```

## Claude owns

**Files:** everything in `C:\Users\hp\Downloads\app\`, chiefly
`video_factory.php`, `video_feed.php`, `video_people.php`, `receipt_cards.php`,
`video_bridge.php`, `clip_fetch.php`, `video_intel.php`, plus the site-pipeline files
(`cli.php`, `select.php`, `draft.php`, gates).

**Work:**
- The **Director** — `video_write_shotlist()`. Turning a script into a word-anchored
  EDL that obeys the v4 laws (shot dies with its phrase, cut on the word, ≤3
  consecutive b-roll, punch-in on emphasis words only).
- **Script quality** — the prompts in `video_write_script()` /
  `video_write_timeline_script()`; hook archetype rotation; the anti-template
  variation that keeps us on the right side of YouTube's mass-production policy.
- **Visual sourcing decisions** — which real photo for which narrated moment,
  receipt selection and ordering, `gravity` classification.
- **The self-healing layer** (see below) — this is the actual "runs by itself" ask.
- The feed contract and the bus (`video_bridge.php`).

**Why me:** this half is judgment, prompt design, and long-context reasoning against
a 343-line playbook and a 154-line editor spec. It's where I'm strongest, and I've
just read both end to end.

## GLM owns

**Files:** `videorepos/deploy/video_maker.py` (8,846 lines),
`videorepos/deploy/video-maker.yml`, the sfx/bgm kit, `videorepos/mpt/` if it needs
touching, and the `pipeline/` Actions workers.

**Work:**
- **Execute the shotlist** — consume the EDL and cut to it exactly. Vertical
  alignment: word-index → ms via the edge-tts WordBoundary timings the runner already
  collects. This is the fix for the owner's v3 complaint.
- **The sound layer** — currently *zero*, and per the spec that's half the
  professional feel missing. VO -14 LUFS / ≤-1.0 dBTP, music bed -18 to -20 dB with
  3-6 dB ducking under words, SFX ≥6 dB below VO, **3-5 SFX beats per video max**,
  silence 0.5-1.0s before the biggest reveal, 30ms crossfades at cut seams.
- **Render craft** — punch-ins (1.05→1.12 subtle, 1.15→1.25 hard, snap 2-4 frames),
  Ken-Burns alternation, one house LUT for grade uniformity, 9:16 safe areas.
- **CI reliability** — the r29 regression is yours: footage-first timed out on every
  render (4 fails / 2 days) so `VIDEO_FOOTAGE_FETCH=0`. Renders are motion-lite.
  Getting real footage back inside a 24-minute step budget is the prize.
- **Stock quality** — candidate pool + vision re-rank instead of first-hit.

**Why you:** it's a large Python codebase, ffmpeg/moviepy mechanics, audio DSP, and
CI debugging with a fast run-observe-fix loop. Mechanical depth and iteration speed,
which is where a coding agent in ZCode beats a relay-bound reviewer.

## Shared, by agreement only

- **The `shotlist` JSON schema.** Neither of us changes it unilaterally — it's the
  contract. Proposal → thread → `04-DECISIONS.md` → both implement.
- **`.social/` state files** (`video_done.txt`, `video_replans.txt`,
  `render-trigger`). GLM writes them from CI; I read them from the server. Format
  changes need a decision entry.
- **The playbook laws.** Neither of us relaxes a law to make our half easier. If a
  law is unimplementable, argue it in the thread and we amend it explicitly.

## The actual goal: "runs by itself"

Youness's ask is that the video system keeps working without him nursing it. Today it
doesn't — and the failure modes are already on record, so we're not guessing:

| Failure seen | Whose half | What "self-healing" means |
|---|---|---|
| 6 candidates fail `uniq_path` every tick, forever | Claude | Detect repeat failure, mark dead, stop re-drafting |
| Director brain silently dead (models hit HTTP 410 EOL) | Claude | Probe models, auto-failover, **alert loudly** — silent death is the real bug |
| `published_today=0` with 12 slots free | Claude | Stall detection: pipeline reports when it produces nothing |
| Footage fetch timed out every render → feature switched off | GLM | Per-stage budget + degrade, so one slow stage can't kill a render |
| ~1 GB ML download hung runs #105/#106 | GLM | Cache or drop; never let an optional stage hang the job |
| Renders fail and nobody knows until Youness looks | GLM | Render report per run, committed where the server can read it |

**The pattern in all six is the same: things fail silently and stay failed.** So the
first shared deliverable isn't a feature — it's a **heartbeat**. Each half writes its
health somewhere the other can see, and *something* shouts when a stage produces
nothing. My proposal for where that lives is in `thread/0001`.

## Deliberately out of scope for now

Posting/scheduling to TikTok/IG/YouTube, the site front-end, SEO, and the
`competroresrepos`/`imgenginerepos` trees. If the video system runs itself, those come
after — and Youness decides when.
