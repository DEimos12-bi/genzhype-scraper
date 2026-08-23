# Spec: The Reflector (organ 05) — upgrade, not rebuild

> Architecture: `_team/MASTER-PLAN.html` v3, organ 05. Rule #1 applied: `strategist.php`
> already IS this organ. It runs daily on GitHub Actions, reads everything, writes
> recommendations to `strategist_reco` (proposed → approved → dismissed → done), and
> emails the owner. It is well built. **We upgrade it; we do not replace it.**

## Objective

Make the thinking pass learn from **what actually happened to individual videos**,
not only from averages — and let it ask for things it doesn't have.

## The three gaps (measured against the architecture)

| Gap | Today | After |
|---|---|---|
| **Reads averages, not stories** | `strategist_gather()` returns AVG/COUNT/MAX per platform | + `traces`: the joined story of recent videos — hook, shots, judge scores, **the owner's verdict**, views |
| **One kind of output** | generic `recommendations[]` | typed: `rule_change` · `upgrade` (a work order for Claude) · `question` |
| **Cannot say "I don't know"** | silently omits | every pass ends with `open_questions[]` → the **Question Ledger** (organ 08) |

The first gap is the load-bearing one. GEPA's finding is that reading full execution
traces beats reading scores by up to 19 points **with 35× fewer trials** — which is the
only reason learning is possible at a few videos a day. Averages hide the thing that
matters: *video 700 scored 8/10 from the judge and "disaster" from the owner.*

## Assumptions

1. `strategist.php` keeps its trust model verbatim: **recommends, never applies.** No
   auto-apply until the Proving Ground (organ 10) exists — that is the plan's order and
   this upgrade does not break it.
2. Owner verdicts are **ground truth and outrank the vision judge** in the prompt.
3. Traces are capped (~25 videos, compact strings) to stay inside a free-tier context.
4. It keeps running on GitHub Actions daily — **no new Hostinger cron** (owner rule 4).
5. Read-only against production tables except its own (`strategist_*`, `comp_rule`,
   `question_ledger`). It still cannot touch pages, videos, posters or money paths.

## Success criteria (testable)

1. `php app/cli.php reflect --dry` prints the traces block containing at least one real
   video with its hook, judge score and (once recorded) the owner verdict.
2. A real pass writes ≥1 typed recommendation into `strategist_reco` with `type` set.
3. A pass with an unanswerable question writes a row into `question_ledger`.
4. `php app/cli.php questions` lists the ledger.
5. Nothing in `pages`, `video_scripts`, `platform_*` is written by the pass.

## Boundaries

- **Always:** lint on both PHPs · commit local before deploy · back up on server before
  replacing · traces capped · owner verdict labelled as outranking the judge
- **Ask first:** anything that would auto-apply a change; any new scheduled job
- **Never:** write to production content tables · put secrets in a proposal · let a
  reflection failure break the daily pass (wrapped, non-fatal)

## Out of scope (deliberate, comes later in plan order)

Proving Ground replay (organ 10) before auto-apply · Experiment Desk (06) · the room
surfacing recos next to decisions (07). This upgrade feeds them all when they land.
