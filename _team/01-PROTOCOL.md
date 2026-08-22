# 01 — PROTOCOL

> **PUBLIC REPO.** No credential values here or anywhere in `_team/`.

How Claude and GLM work together over the bus. Short on purpose. Change it by
agreement (`team_decision`), then edit this file.

## The turn

**Every turn, in this order:**

1. **`team_brief`** — always first, before touching anything. It tells you what the
   other agent did since you last looked, what's waiting for you, which files they're
   holding, and what decisions are open. Skipping it is how we end up duplicating
   work or editing the same file blind.
2. **`team_inbox`** if the brief says you have unread mail.
3. **`team_status`** — say what you're starting, *when you start it*, not when you
   finish. The point is that work-in-flight is visible.
4. **`team_claim`** the files you're about to edit. If it refuses, the other agent
   holds them — `team_send` a request instead of editing anyway.
5. Do the work.
6. **`team_send`** / **`team_log`** / **`team_release`** as you go.


## The owner's rules (binding on both agents)

Set by Youness, recorded by GLM in `03-BOARD.md`. These outrank anything either
agent decides between themselves.

1. **Reuse before building.** Bring proven parts from existing repos and edit them
   to fit. Build from scratch only when nothing exists. Search first.
2. **GitHub first.** Everything goes to the repo — PR, owner reviews, merge. The
   Hostinger deploy is **last**, only when everything is finished: no holes, no
   gaps, no leaks.
3. **Keys never enter the public repo**, and tokens in logs are always redacted.
4. **No new Hostinger crons, no heavy load on Hostinger** — it is shared hosting.
   GitHub Actions is free and unlimited on a public repo; put the work there.
5. **The strategist recommends; the owner approves.**

## Rules

1. **One owner per file.** Ownership is in `02-SPLIT.md`; claims enforce it at runtime.
   Neither of us can see the other's working tree, so a claim is the only real
   collision guard.
2. **Need a change in a file you don't own?** Request it. Give the path, the current
   behaviour, the wanted behaviour, and why. Don't stub it and hope.
3. **Disagree explicitly.** If the other's plan is wrong, say which part and why, and
   propose the alternative. Silent divergence is the expensive failure — we only find
   out much later.
4. **Report reality.** Paths, line numbers, real error text. If something failed, say
   so and paste the error. Never "should work".
5. **Don't duplicate.** Check the log in `team_brief` before building something —
   the other agent may already have shipped it. (This has already happened once: both
   of us independently diagnosed the silent-model-rot problem.)
6. **Decisions.** `open` = needs the other agent. `boss` = genuinely Youness's call,
   not ours; it goes to the top of his dashboard. `settle` = record what was agreed.
   Don't escalate something to him that we can settle ourselves.
7. **Never write a credential** into any tool call. The bus refuses anything
   credential-shaped, but don't rely on it — describe secrets by name and location
   ("the director key in config.php").

## Don't block on a relay

Youness still has to tell each agent to take a turn — that part can't be automated
away. So when you're waiting on an answer: **write the question, then go do the parts
that don't depend on it.** Never sit idle waiting for a round trip.

## When you finish something real

`team_log` it, `team_release` the files, and set `team_status` to `done` with a one
line summary. That's what the other agent reads at the start of their next turn, and
what Youness sees on the dashboard.

## Durable record

The bus state is live runtime data and is not committed. When a decision settles,
whoever settled it also appends it to `02-SPLIT.md` or `00-BRIEF.md` if it changes
how the system is built — that's the part that has to survive the bus being reset.
