# Tasks: The Record (organ 02)

Verification baseline for every task: `php.exe -l` clean on every touched PHP file ·
committed to the local `Downloads\app` git before any upload · hook wrapped non-fatal.

## ✅ Task 1: record.php core + CLI verbs
**Description:** New `app/record.php`: `record_install()` (guarded CREATE/ALTER),
`record_touch($pdo,$kind,$pageId,$ref,$section,array $data)` (JSON-merge into one
section, upsert), `record_get()`, `record_story()` (pretty print). `cli.php` gets
`record <page_id>` and `record-selftest` (write→read→delete→PASS).
- Acceptance: [ ] table created on first call [ ] touch merges keys without clobbering other sections [ ] `record-selftest` prints PASS
- Verify: [x] lint [ ] post-deploy: `php app/cli.php record-selftest` → PASS (SSH session)
- Dependencies: none · Files: `app/record.php`, `app/cli.php` · Scope: S

## ✅ Task 2: W1 — build section from the drama loop + page backfill
**Description:** In the cli.php drama loop, after v/q/g, `record_touch('drama',$pid,
$slug,'build',{draft:{provider,events}, verify:{pass,issues}, quality:{pass,scores,
flags}, gate:{pass,failed:[labels]}, published:bool})`. Plus `record_backfill_pages
($pdo, 30)` seeding recent published dramas from `pages`/`ai_log` (called once from
the `record-selftest` verb's sibling `record-backfill`).
- Acceptance: [ ] next tick writes a build section for each built page [ ] backfill produces ≥1 record for an existing page
- Verify: [x] lint [ ] `php app/cli.php record <page_id>` shows build
- Dependencies: 1 · Files: `app/cli.php`, `app/record.php` · Scope: S

## ✅ Task 3: W2 — plan section from the video factory + video backfill
**Description:** After `video_write_script`/`video_write_timeline_script` persist, and
in the story gate's skip branch, `record_touch('video',$pid,$slug,'plan',{tpl, hook,
hook_archetype, gravity, style?, shotlist_shots:int, skipped_why?})`. Backfill from
`video_scripts` (all rows, incl. 700).
- Acceptance: [ ] a new script produces a plan section [ ] skipped stories appear with `skipped_why` [ ] video 700 has a plan section after backfill
- Verify: [x] lint [ ] `record 700` shows plan
- Dependencies: 1 · Files: `app/video_factory.php`, `app/record.php` · Scope: S

## ✅ Task 4: W3 — delivery + judge from the drop ingest (accepted AND rejected)
**Description:** In `video_bridge.php ingest`: for each meta'd drop, `record_touch
('video',$pid,$slug,'delivery',{render_stats, substitutions, scenes})` and
`'judgment',{judge:{pass,scores,issues}}`. For drops with `render.log` but no meta,
parse the `JudgeRejected` line → `judgment.judge={pass:false,...}` so rejects are
recorded.
- Acceptance: [ ] an accepted drop fills delivery+judgment [ ] a rejected drop fills judgment with pass:false and the reason
- Verify: [x] lint [ ] after next drop ingest, `record <pid>` shows it
- Dependencies: 1 · Files: `app/video_bridge.php`, `app/record.php` · Scope: S

## ✅ Task 5: W4 — outcome snapshots from metrics collection
**Description:** In `sm_collect` after each `platform_metrics` insert, resolve the
post's `page_id`+`platform` via `social_posts` and `record_touch('post',$pageId,
$platform,'outcome',{snapshots:[{at,views,likes,comments,shares}]})` — appending
to the array (touch supports `$append` for list keys).
- Acceptance: [ ] a metrics run appends one snapshot per matched post [ ] re-runs don't duplicate the same reading
- Verify: [x] lint [ ] `record <pid>` shows outcome after a collection run
- Dependencies: 1 · Files: `app/social_metrics.php`, `app/record.php` · Scope: S

## Checkpoint A — deploy batch A (5 files) then:
- [ ] `record-selftest` PASS · [ ] `record-backfill` run · [ ] `record 700` shows plan + judgment(reject) · [ ] next tick shows a fresh build section · [ ] hPanel resource graph flat

## ✅ Task 6: records.php — token-gated export
**Description:** `public_html/api/records.php`: token via POST or GET, `since=`,
`page_id=`, `kind=`, `limit≤200`; returns `{records:[...]}` with sections decoded.
403 without token. Read-only.
- Acceptance: [ ] 403 without token [ ] valid JSON with token [ ] since filter works
- Verify: [x] lint [ ] curl from local with token POSTed (read from local config, never printed)
- Dependencies: 1 · Files: `public_html/api/records.php` · Scope: XS

## ✅ Task 7: verdict_ingest.php + room POST
**Description:** `public_html/api/verdict_ingest.php`: token-gated JSON POST
`{video, verdict, reasons, note, at}` → resolves `video` to page_id (numeric id, slug,
or TikTok URL → `social_posts.link`) → `record_touch('video',...,'judgment',
{owner_verdict:{...}})`. Room: after `appendVerdict`, POST it (token read from
`Downloads\app\config.php` at call time); on failure log + continue.
- Acceptance: [ ] a room verdict appears in `records.php` judgment within a minute [ ] room offline → verdict still in verdicts.jsonl + a visible failure line
- Verify: [x] lint [ ] end-to-end click test
- Dependencies: 1, 6 · Files: `public_html/api/verdict_ingest.php`, `_team/bus/room.mjs` · Scope: S

## Checkpoint B — deploy batch B (2 api files) + room restart:
- [ ] export works from local · [ ] verdict round-trip works · [ ] all 5 spec success criteria ticked · [ ] code-review + simplify pass before calling it shipped
