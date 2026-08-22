> **This file is in a PUBLIC repository.** No credential values, no host details,
> no database identifiers. Describe secrets by name and location only.

# 00 — BRIEF: GenZHype in one read

Written by Claude, 2026-08-22, from the live `app/` tree + the full site backup
(`_genzhype.com.zip`, 21,686 files). GLM: read this instead of the 3.4 GB archive.
If you find something here that's wrong, append a correction at the bottom rather
than rewriting — I'd rather see the disagreement.

---

## 1. What the product is

**genzhype.com** — a creator-drama / Gen-Z-slang reference site. Tagline and whole
editorial posture: **"The receipts, not the gossip."** Every claim on a page is tied
to a dated, sourced receipt. Revenue = AdSense + AI/GEO citations from the site, and
later TikTok Creator Rewards from the video arm.

It runs **autopilot**: `'auto_publish' => true`, capped at `daily_publish_cap = 12`
pages/day. Pages only go live after passing a 12-check gate (≥8 dated events, ≥2
source domains, every claim sourced, schema clean, PageSpeed >90, licensed imagery,
AI originality/tone pass).

**The video arm is the site's marketing department.** Short vertical videos (9:16)
push dramas on TikTok / Reels / Shorts, which drives search demand back to the pages.

## 2. Where things live

| Thing | Path | Notes |
|---|---|---|
| PHP brain (editable now) | `C:\Users\hp\Downloads\app\` | This is `app/` from the site root |
| Full site backup | `C:\Users\hp\Downloads\_genzhype.com.zip` | 3.4 GB, 21,686 files |
| Production host | Hostinger (shared), MySQL | `app/` sits **outside** `public_html` |
| Render infra | GitHub Actions | public repo, for unlimited minutes |

Inside the backup, the parts that matter:

```
app/            the PHP brain (521 files)  <-- the local copy is this
videorepos/     video system docs + the Python renderer + sfx/bgm
  VIDEO-PLAYBOOK.md        the law-book (343 lines) — read it
  V4-EDITOR-SPEC.md        the hired-editor spec (154 lines) — read it
  DIRECTOR-UPGRADE-RESEARCH.md / SOURCING-PLAYBOOK.md / ADAPTATION.md
  deploy/video_maker.py    8,846 lines — THE RENDERER
  deploy/video-maker.yml   294 lines — the Actions workflow
  deploy/sfx/ bgm/         CC0 sound kit (whooshes, risers, impacts, pops, 3 bgm)
  mpt/                     vendored MoneyPrinterTurbo subset (voice.py is reused)
pipeline/       github-scraper, receipts-worker (separate Actions workers)
public_html/    the site
_docs/ _design/ system design + design system
```

## 3. The engine (site side)

```
scrapers (Reddit / Trends / YouTube / X)  ->  candidates queue
      -> SELECT (AI: worth building?)     ->  DRAFT (AI orders sources, never invents)
      -> VERIFY (AI 2nd pass + hard gates) -> DB -> published page
```

Key PHP: `cli.php` (63 KB, the cron entrypoint), `select.php`, `draft.php`,
`distill.php`, `gate*.php`, `verify*.php`, `repo.php`, `producer.php`.

## 4. The video pipeline (this is our job)

Two halves with a **JSON contract** between them. This seam is the whole reason a
two-model split works cleanly.

### Half A — PHP, on Hostinger ("what the video should be")

| File | Role |
|---|---|
| `video_factory.php` (104 KB) | Script writer + **Director**. `video_write_script()`, `video_write_timeline_script()`, `video_write_moment_script()`, `video_write_shotlist()` (the v4 word-anchored EDL), `video_event_visuals()`, `video_pin_post_cards()`, `video_story_gravity()` |
| `video_feed.php` | `video_feed_build_post()` = **the contract**. `video_feed_static_write()` publishes the job feed |
| `video_people.php` | Resolves real faces (stored QIDs → Wikidata P18 → YouTube avatars), 30-day cache |
| `receipt_cards.php` | Receipt imagery + `source_url` / `og_image` per receipt |
| `clip_fetch.php` | Server-side clip download (TikTok resolver works from the host, not from CI) |
| `video_bridge.php` | The **GitHub bus** — `feed` exports jobs, `ingest` accepts finished videos |
| `video_intel.php` | `vi_learn()` — mines rival channels for title/format rules |

`video_scripts` table: `page_id, slug, title, hook, script, image, video_path,
video_status(pending|ready), video_made_at, tpl, broll, shotlist, gravity,
force_render, footage_clips, visual_plan, created_at`.

### Half B — Python, on GitHub Actions ("how the video gets made")

`videorepos/deploy/video_maker.py` (8,846 lines) driven by `video-maker.yml`:
cron `17 */6 * * *`, plus `workflow_dispatch`, plus a push to `.social/render-trigger`.

Does: edge-tts narration with **WordBoundary** timings → word-by-word caption pops →
scene assembly (photos + receipts + stock b-roll + real footage) → Ken-Burns / punch-ins
→ ffmpeg encode → `-movflags +faststart` remux → POST back to the site.

Optional, all degrade gracefully when a secret is absent: `PEXELS_API_KEY`,
`PIXABAY_API_KEY`, `GEMINI_API_KEY` (vision judge + stock re-rank), `YT_COOKIES`.

### The contract (`video_feed_build_post()` output — memorise this)

```jsonc
{
  "page_id": 259, "slug": "...", "title": "...", "hook": "...",
  "script":  "the narration text",
  "image":   "hero photo url",
  "visuals": ["url", ...], "visual_titles": ["what each one is", ...],
  "people":  [{"name": "...", "photo": "url|null", "photos": ["url", ...]}],
  "broll":   ["stock search phrase", ...],
  "shotlist": { /* v4 DIRECTOR EDL — word-anchored. null until directed. */ },
  "receipts": ["card png url", ...],
  "receipt_meta": [{"kind":"event","source_url":"...","og_image":"..."}],
  "gravity": "standard|grave",
  "clips":   [...], "visual_plan": [...],
  "force":   false,
  "link":    "https://genzhype.com/drama/<slug>/"
}
```

`receipts[]` and `receipt_meta[]` are **index-aligned**. `gravity: "grave"` means a
death or serious tragedy — no savage hooks, no debate outro, sober register. That's a
decency law, not a style preference.

### The GitHub bus (why it exists)

Hostinger's WAF 403s `/api/video_next.php` for GitHub-runner IPs, and bitninja
blackholes some runner IPs at TCP connect. So:

- **`video-feed` branch** — server → runner. `video_bridge.php feed <dir>` exports the
  job feed **plus every visual pre-staged as `visuals/<sha1(url)>`**, so a blackholed
  runner never needs to reach genzhype.com for an image.
- **`video-drop` branch** — runner → server. `video_bridge.php ingest <dir>` stores the
  mp4, writes the sheet, flips `video_status='ready'`.
- Fallback path: a static feed JSON under `/media/<token>.json` (looks like an ordinary
  media file to the WAF; the token rides in the unlisted filename).
- Dedup state: `.social/video_done.txt`, replans in `.social/video_replans.txt`,
  both committed by the workflow with `[skip ci]`.

## 5. Where it actually stands today (2026-08-22)

Read from `cron.log` and `error_log` in the live tree. **This is the real starting
position — not the docs' aspiration.**

**Site pipeline is stalled.** Every hourly tick for the last ~6 hours:

```
velocity: cap=12 published_today=0 slots_left=12
draft fail #8973: db insert failed: Duplicate entry '/drama/twitch-data-leak-2026/' for key 'uniq_path'
draft fail #9187: all providers failed (last: nvidia/meta/llama-3.2-90b-vision-instruct HTTP 0)
```

Two distinct faults, both self-healing gaps:

1. **Stuck candidates.** The same 6 candidate IDs (#8973, #9175, #9184, #9187, #9188,
   #9192) fail `uniq_path` every single tick — a page at that path already exists.
   Nothing marks them dead, so they re-draft forever, burning AI calls each hour and
   producing zero pages.
2. **Model rot.** `nvidia/meta/llama-3.2-90b-vision-instruct` now returns HTTP 0.
   This is the *second* rot event today: `config.php` records that
   `deepseek-v4-pro` and `glm-5.2` both went HTTP 410 "end of life" this morning, and
   the **Director brain had been dead in silence** until a probe caught it. Current
   director chain is `nemotron-super-49b-v1.5` → `mistral-nemotron` → `kimi-k3`.

**Video renders are in "motion-lite" mode**, deliberately degraded for reliability:

```yaml
VIDEO_FOOTAGE_FETCH: "0"   # r29: footage-first timed out EVERY render (4 fails/2 days)
VIDEO_CLIP_VERIFY:   "0"   # r25: ~1GB ML download hung the runner (runs #105/#106)
VIDEO_FORCED_ALIGN:  "0"
```

So: real YouTube footage is off (bot-wall + 30-min timeouts), CLIP mismatch-checking
is off, whisperx forced alignment is off. Renders currently ride still images with
motion. There have been ~29 revisions (r1…r29) of trying to make footage work.

**Owner's standing verdicts on quality** (from the playbook — these are the bar):

- v1: *"not even a video — an image stuck with captions."*
- v3: *"the watch was one WORD but its scene dragged"* (shot-to-word sync missing);
  *"background clips slow/low quality, like a beginner put clips side by side."*
  **Loved and untouchable: the caption-word sync.**
- Round 6: **evidence must look platform-native** — receipts render as authentic
  X/news snapshots, **zero GenZHype branding on evidence**. Brand appears only as an
  occasional promo card.
- Round 10: **evidence = original pixels** — the real screenshot of the real source,
  not a rendered beige card (those are last-resort fallback only). And **exact-moment
  imagery**: if the person was at a specific event, show *that* event's photo.

## 6. The laws (non-negotiable, from VIDEO-PLAYBOOK.md + V4-EDITOR-SPEC.md)

Condensed. Full text is in the backup; both docs are worth a full read once.

**Strategy.** Stage 1 (now, until ~10k TikTok followers): optimise **retention +
follows only**. One 60–85s master serves all three platforms (TikTok Creator Rewards
requires 60s+; >60s gets +43% reach per Buffer's 1.1M-video study; only ~12-14% of
posts are >60s, so it's undersupplied).

**Retention.** Hook decides in ~1 second. Triple-layer hook (visual + 3-7 word
on-screen text + 5-10 word spoken). **No intros ever.** BUT/THEN connectives, never
"and then". Something changes every 1.5-3s; bigger interrupt every 8-12s; re-hook
every 25-35s. 0.3-0.5s silence + riser before the biggest receipt. Loop-back ending.
~150-180 WPM. Captions 1-3 words, ALL-CAPS heavy, out of top 220px / bottom 320px.

**Editing (the v4 diagnosis).** v3 edits *horizontally* (scene after scene); pros edit
**vertically** — every shot is glued to the exact words under it and dies when its
word dies. Shot length 1.5-3.0s (floor 1.0, ceiling 4.0). **A shot dies with its
phrase** — visual changes within ~300ms of the idea changing. Cut ON the word, 1-2
frames early, never >100ms late. Hard cuts only; **never cross-dissolve in a short**.
Punch-ins on emphasis words only.

**Sound (currently zero — a major "feels dead" cause).** VO -14 LUFS, true peak
≤-1.0 dBTP. Music bed -18 to -20 dB under voice, duck 3-6 dB more while words play.
SFX ≥6 dB below VO. **Budget: 3-5 SFX beats per video only** — Hormozi-style overload
now reads as fatigue. Music fully out 0.5-1.0s before the biggest reveal, slam back
with the impact. 30ms crossfades at every cut seam.

**The #1 danger: templated mass-production.** YouTube's YPP policy (renamed 2025-07-15)
bans monetising content "made with a template with little to no variation across
videos". TikTok makes it FYF-ineligible = zero reach. **Our defence** is original
per-video scripts from real dated receipts, *plus* baked-in variation (hook archetype
rotation via `crc32(slug)%3`, ending variety, scene-rhythm differences).
**Rule: never let two videos feel like the same video with nouns swapped.**

**Other hard rules.** TikTok: flip the AI-generated toggle on every upload (mandatory
there; *not* required on YouTube for narration over real photos). Music: CC0/owned
only — a Content ID claim on a >60s Short is **blocked globally**, not just
demonetised. Never re-upload a file carrying another platform's watermark. No "follow
for part 2" (down-ranked). Alleged/reportedly framing on anything unproven.

## 7. Credentials — read this before you commit anything

`app/config.php` holds **live** secrets: the MySQL password, the ingest token, the
admin hash, Gemini / OpenRouter / NVIDIA / Groq / Cloudflare keys, and Buffer / FB /
IG / Threads / X / Reddit tokens. Its own comments already record one leak
("regenerate after the leak!"), and the render repo is **public**.

Therefore:

- `config.php` is **never** committed, to any repo, in any form.
- Secrets reach the runner two ways only: GitHub Actions **secrets**, or the runtime
  vault at `/api/creds.php` (ingest-token-gated).
- Nothing in `genzhype-agents/` gets committed either — quote *paths and shapes* in
  messages, never key values.
- If a task seems to need a key in a file, say so in the thread instead of doing it.

There's a `DO_NOT_UPLOAD_HERE` sentinel at the backup root. Respect it.

---

## Corrections / additions

<!-- GLM: append below. Sign each entry. Don't rewrite what's above. -->
