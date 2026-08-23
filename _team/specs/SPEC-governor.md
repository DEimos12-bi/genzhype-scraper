# SPEC — organ 13, The Governor

## The case that built it

On 2026-08-23 a single step of the autopilot tick had been failing on **every
tick since 2026-07-12** — 51 identical failures over six weeks. It printed one
line to a log file nobody reads and then carried on. Nothing counted it, nothing
compared it to yesterday, nothing told the owner. The machine did not know it
was broken.

Everything else in the machine is built to make good decisions. The Governor
exists for a different question: **is anything still working?**

## What it is

A watchman with no hands. It reads what the machine has already recorded, notices
what changed for the worse, and says so where the owner is already looking.

## Laws

1. **It never fixes anything.** It raises an alarm; the owner decides. Same trust
   model as every other organ.
2. **It dedupes by code.** A recurring fault updates one row's `last_seen`. A
   Governor that repeats itself becomes noise, gets ignored, and then fails at
   the exact job it exists to do.
3. **It is read-only on the machine's data** and bounded — this runs on shared
   hosting next to other people's sites.
4. **It reports its own liveness.** The watchman must not become the next silent
   thing, so it records when it last ran and the room shows that timestamp. If
   the Governor dies, the staleness is visible.
5. **Silence is a finding.** "Nothing to report" is a real answer and must be
   distinguishable from "did not run".

## The checks

| Code | Catches | Evidence it reads |
|---|---|---|
| `heartbeat` | The tick stopped entirely | `cron_events` — newest tick start |
| `step_vanished` | A step that used to run every tick stopped | `cron_events` — last 24h vs the 7 days before |
| `error_streak` | The same failure repeating and being ignored | `cron.log` tail, normalised and counted |
| `dry_lane` | A lane that used to produce output producing none | `pages`, `platform_videos` vs trailing median |
| `reward_hack` | Machine scores improving while real results do not | `ai_reviews` pass rate vs `platform_metrics` views |
| `backlog` | Work piling up faster than it is consumed | `video_scripts` ready count |

`step_vanished` is the one that would have caught 12 July.
`reward_hack` is the one the literature says matters most: 73.8% of
self-improving optimisations improve a proxy while the real task gets worse.

## Acceptance

- [ ] A planted silence is detected (self-test proves it, not argument)
- [ ] Running twice does not create two rows for one fault
- [ ] Zero findings is reported as zero, not as an empty screen
- [ ] Read-only: a dry run writes nothing but the liveness marker
- [ ] Alarms reach the room
