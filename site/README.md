# site/ — the PHP half of GenZHype

This is the code that runs on the web host: the site itself, the autopilot tick,
and the learning machine's organs. Until 2026-08-23 it lived only on one laptop
and one shared-hosting disk, with no history anywhere — a disk failure would have
taken all of it. It is here now because the standing rule is **GitHub first, the
host last**.

## Layout

| Path | What it is |
|---|---|
| `app/` | Everything the site thinks with. Not web-reachable. |
| `public_html/api/` | The few token-gated endpoints the PC and the renderer call. |

## The learning machine

The organs, in the order a decision travels through them:

| File | Organ | Job |
|---|---|---|
| `app/record.php` | 02 The Record | One joined story per work unit: plan → build → delivery → judgment → outcome. The spine everything else reads. |
| `app/memory.php` | 04 The Memory | An approval reaches the hands. Approved recommendations become directives the script, director and hook writers must follow. |
| `app/strategist.php` | 05 The Reflector | Reads whole execution traces, not scores, and proposes changes. Owner verdicts outrank every machine score. |
| `app/proving.php` | 10 The Proving Ground | Replays a proposal against our own posted videos before the owner ever sees it. |
| `app/inspector.php` | 11 The Inspector | Last checkpoint before pixels. Fails safe: no date beats a wrong date. |

The rule they all serve: **the machine recommends, the owner decides.** Nothing in
this directory applies a change on its own.

## Secrets

`app/config.php` is **never committed** — `app/.gitignore` excludes it along with
every `*.json` (which is where the OAuth refresh tokens live), all logs and
`error_log`. `app/config.sample.php` is the shape of it: all 52 keys, no values.
Copy it on the server and fill it in there.

## Deploying

The host is shared with other clients' projects, so this repo does not deploy
itself and adds no cron of its own. Files are copied up deliberately, one change
at a time, with a timestamped backup into `app/_bak/` and `php -l` run on the
server before anything is trusted.
