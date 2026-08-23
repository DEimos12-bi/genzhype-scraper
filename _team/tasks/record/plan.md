# Implementation Plan: The Record (organ 02)

Spec: `_team/specs/SPEC-record.md`. Tasks: `_team/tasks/record/todo.md`.
Owner decisions (2026-08-23, by trust): ledger lives on the site = **yes**; verdicts
reach the site **automatically** from the room.

## Overview

One `work_record` row per page/video/post, five sections (plan · build · delivery ·
judgment · outcome) written by small non-fatal hooks riding ticks that already run,
read by one CLI verb and one token-gated export. Backfill seeds history. Nothing new
is scheduled on Hostinger.

## Architecture decisions

- **Sections as MEDIUMTEXT JSON, merged per section** (`record_touch`). Matches the
  codebase (`video_scripts.shotlist`), avoids MySQL JSON-type version risk, and lets
  each writer own one section without reading the others.
- **Observer, never participant.** Every hook is `try { record_touch(...) } catch {}`.
  A record failure logs and the pipeline continues. The record is write-only from
  the Doers' view; only organs read it.
- **Reference, don't duplicate.** `ai_log`, `platform_metrics`, `social_posts`,
  `video_scripts` stay canonical; the record stores compact snapshots + ids.
- **Token pattern copied verbatim** from `api/video_next.php`
  (`hash_equals($CONFIG['ingest_token'], $IN['token'])`, JSON out, 403 on miss).
- **Rejected renders are recorded too.** video-700 was a *reject* — the judge's
  reasons on rejects are exactly what learning needs. The bridge today ingests only
  metad (accepted) drops; the delivery hook also parses `render.log` for the
  `JudgeRejected` line so rejects enter the record.
- **Verdict transport:** the room POSTs JSON to `api/verdict_ingest.php`, reading
  the ingest token from the LOCAL `Downloads\app\config.php` at request time (never
  stored elsewhere). Offline/blocked → verdict stays in `verdicts.jsonl`, failure
  logged, nothing lost.

## Dependency graph

```
record.php (table + record_touch + readers)        ← Task 1
    ├── W1 build hook, cli.php drama loop + backfill ← Task 2
    ├── W2 plan hook, video_factory.php + backfill   ← Task 3
    ├── W3 delivery hook, video_bridge.php ingest    ← Task 4
    ├── W4 outcome hook, social_metrics.php          ← Task 5
    ├── api/records.php export                       ← Task 6
    └── api/verdict_ingest.php + room POST           ← Task 7
```

## Deploy batches (owner uploads — kept to two)

- **Batch A** after Tasks 1–5: `app/record.php`, `app/cli.php`, `app/video_factory.php`,
  `app/video_bridge.php`, `app/social_metrics.php`
- **Batch B** after Tasks 6–7: `public_html/api/records.php`,
  `public_html/api/verdict_ingest.php` (+ room restart locally, no upload)

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| ALTER/ENUM quirks on shared MySQL | Med | guarded `try/catch` migrations, the codebase's own pattern; selftest verb proves the table live |
| Hook adds load to a tick | Low | exactly one `INSERT … ON DUPLICATE KEY UPDATE` per hook, indexed by (kind,page_id,ref) |
| `render.log` format drifts → reject parse fails | Low | parse is best-effort, non-fatal; accepted drops carry structured meta anyway |
| WAF blocks the room's POST | Med | verdict still saved locally; failure visible in room console + bus log; can fall back to manual sync |
| No local MySQL → can't unit-test DB code | Med | `php -l` on everything; `record-selftest` CLI verb run server-side after deploy; end-to-end export check from local |

## Open questions

None blocking. Terms/slang records (`kind='term'`) are v2 by the same shape.
