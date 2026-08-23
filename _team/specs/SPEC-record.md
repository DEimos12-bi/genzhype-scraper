# Spec: The Record (organ 02) — one joined story per unit of work

> Architecture: `_team/MASTER-PLAN.html` v3, organ 02 — "the spine; everything else
> reads it." Status: **awaiting owner approval.**

## Objective

One place that answers, in one command: **"show me everything about page/video X."**
What was planned, what was built, what every check said, what the renderer did, what
the judge and the owner said, and how it performed — joined, not scattered across
`cron.log` prose, drop branches, and five tables. This is the substrate every learning
organ (Reflector, Proving Ground, Experiment Desk) reads. Without it, no learning.

**User stories**
- As the Reflector, I fetch all records since date D and read complete stories.
- As Claude diagnosing a bad video, I run one command instead of an hour of
  archaeology across branches and logs (the video-700 experience).
- As Youness, my room verdict lands inside the same story as the video's metrics.

## Assumptions (correct me now or I proceed with these)

1. **Storage = MySQL on the existing Hostinger DB**, one new table, sections held as
   `MEDIUMTEXT` JSON — the codebase's own pattern (`video_scripts.shotlist`), immune
   to MySQL-version JSON-type surprises.
2. **Reference, don't duplicate.** Metrics, AI calls, scripts stay in their tables;
   the record stores compact snapshots + ids, and the full sources remain canonical.
3. **v1 covers dramas + their videos + their posts.** Terms/slang join in v2 by the
   same shape (`kind` column exists from day one).
4. **Verdict transport:** the room (his PC) POSTs each verdict to a token-gated site
   endpoint, reading the ingest token from the LOCAL `Downloads\app\config.php`
   (never committed, never stored anywhere new).
5. **No new Hostinger crons** — every writer rides an existing tick (owner rule 4).

## Tech stack

PHP 8.x (site, matching existing `app/` style) · MySQL InnoDB utf8mb4 · one new
`api/` endpoint pair · ~30 lines of JS in the existing room server.

## Data shape (one row per work unit)

```
work_record
  id            INT AUTO_INCREMENT PK
  kind          ENUM('drama','term','video','post')   -- v1 writes drama+video+post
  page_id       INT UNSIGNED, INDEX                   -- the page it belongs to
  ref           VARCHAR(120)                          -- e.g. platform post id / video slug
  created_at / updated_at DATETIME
  plan          MEDIUMTEXT  -- decisions: {surface, choice, rule_id?}  (script, hook type, style...)
  build         MEDIUMTEXT  -- pipeline outcomes: draft/verify/quality/gate per-check results
  delivery      MEDIUMTEXT  -- render report: substitutions[], render stats, judge scores
  judgment      MEDIUMTEXT  -- owner_verdict (outranks) + vision judge + mechanical scores
  outcome       MEDIUMTEXT  -- platform metrics snapshots over time, keyed by platform
  UNIQUE (kind, page_id, ref)
```

Writers merge into their own section only (`record_touch($pdo,$kind,$pageId,$ref,
$section,$data)`) — JSON-merge, last-write-wins per key, never cross-section.

## The writers (all riding existing ticks)

| # | Section | Hook point (exists today) |
|---|---|---|
| W1 | build | `cli.php` drama loop — the same v/q/g results we now print also land in the record (incl. per-check gate detail) |
| W2 | plan | `video_factory.php` after script/shotlist write — template, hook archetype, style, gravity |
| W3 | delivery + judgment(judge) | `video_bridge.php ingest` — the drop already carries render report + judge verdict to the server |
| W4 | outcome | `social_metrics` run — snapshot per platform appended on its existing daily collection |
| W5 | judgment(owner) | NEW `api/verdict_ingest.php` (token-gated) ← room POSTs on every verdict |

## The readers

- `api/records.php?token=…&since=…&page_id=…` — JSON export (future Reflector's food,
  and my diagnosis tool from local).
- `php cli.php record <page_id>` — the story pretty-printed (his SSH session / me).

## Backfill

One-shot function seeding the last ~30 published dramas + all videos present in
`video_scripts` (including 700) from data already in the DB — so the Reflector is
born with history, not amnesia.

## Commands

```
Lint:    php.exe -l app/record.php  (winget PHP 8.4, per deploy ritual)
Deploy:  owner uploads via hPanel — exact file list given at ship time
Verify:  curl records.php export from local → see joined sections on a real page
Test:    php cli.php record-selftest  (writes+reads one throwaway record, prints PASS)
```

## Project structure

```
app/record.php              NEW — installer, record_touch(), readers, backfill, selftest
app/cli.php                 hook W1 + `record` / `record-selftest` CLI verbs
app/video_factory.php       hook W2
app/video_bridge.php        hook W3
app/social_metrics.php      hook W4
public_html/api/records.php        NEW — token-gated export
public_html/api/verdict_ingest.php NEW — token-gated verdict intake
_team/bus/room.mjs          verdict POST to the site (reads token from local config.php)
```

## Code style

Match `app/` exactly: procedural functions with `record_` prefix, guarded
`ALTER TABLE` migrations in an installer, WHY-comments in the house voice,
`try/catch` non-fatal hooks — **a record write must never break the pipeline it
observes** (observer, not participant).

## Testing strategy

No local MySQL → three layers instead: (1) `php -l` on every file (ritual);
(2) `record-selftest` CLI verb against the live DB — one throwaway row,
write→read→delete→PASS; (3) end-to-end: after deploy, fetch `records.php` from
local and confirm a real page's joined story. Room-side verdict POST tested
locally against the endpooint before ship.

## Boundaries

- **Always:** lint before upload · commit to local app git before upload · hooks
  wrapped so failure never blocks the pipeline · token checked constant-time in
  both endpoints
- **Ask first:** any schema change beyond this table · anything that would add
  load beyond one indexed query per hook
- **Never:** secrets in record rows · a new Hostinger cron · deleting source
  tables' data ("reference, don't duplicate") · the record influencing decisions
  (write-only from the pipeline's view — organs read it, Doers don't)

## Success criteria (testable)

1. `php cli.php record 700` prints plan + delivery + judgment + outcome for the
   Twitch-lawsuit video in one screen.
2. A brand-new page next tick produces a record with `build` filled automatically.
3. A verdict clicked in the room appears in that video's record within a minute.
4. `records.php?since=` returns valid JSON of all of it, token-gated, 403 without.
5. Zero new crons; each hook ≤1 extra query; pipeline behavior otherwise unchanged
   (a record failure logs and continues).

## Open questions (owner)

1. **OK that the room sends your verdicts over the internet to your own site**
   (token-protected, read from your local config)? Alternative: verdicts stay
   local-only and the machine learns from them with a delay, only when I sync.
2. Anything you consider **secret** about verdicts? They'd live in your DB only —
   never in the public repo.
