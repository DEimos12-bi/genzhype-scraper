# `_team` — the GenZHype build room

> **PUBLIC REPO.** Everything in this folder is world-readable. No credential values,
> no host details, no database identifiers — describe secrets by name and location
> only. The bus enforces this at write time and will refuse anything
> credential-shaped.

Two coding agents build GenZHype together:

| Agent | Runs in | Owns |
|---|---|---|
| **Claude** | Claude Code | `app/*.php` — the Director, script prompts, feed contract, site-pipeline self-healing |
| **GLM** | ZCode | `video_maker.py`, `video-maker.yml`, the sound layer, render craft, CI reliability |

Youness is the boss: he watches the dashboard and rules on decisions. He is **not**
the courier — the agents talk to each other directly over the bus.

---

## For Youness

**Your dashboard:** `C:\Users\hp\genzhype-bus\dashboard.html` — double-click it.
Anything waiting on *you* is at the top in a yellow box. Refresh after either agent
does something.

**To rule on a decision**, just tell either agent in plain words:
*"D-003: yes, rotate them"*. It records the decision and the other agent sees it.

**To start a work session**, tell whichever agent you want to move:

```
Check the team bus and continue.
```

That's the whole job. The agent calls `team_brief`, sees what the other one did,
what's waiting, and which files it must not touch — then works.

**What you no longer have to do:** carry content between them, remember who owes whom
a reply, or notice that they duplicated each other's work.

**The one thing that hasn't changed:** neither tool can wake the other. You still say
"go" to each agent. There is no way around that with two separate desktop apps.

---

## How it works

A small MCP server (`bus/server.mjs`) that both agents connect to as tools. Zero
dependencies, no network, stdio only.

| Tool | What it does |
|---|---|
| `team_brief` | **Called first, every turn.** Who you are, unread mail, what the other is doing, claimed files, open decisions. |
| `team_inbox` | Read messages in full, mark them read |
| `team_send` | Message the other agent directly |
| `team_status` | Say what you're starting (so the other can see work in flight) |
| `team_claim` / `team_release` | Lock files before editing — refuses if the other holds it |
| `team_decision` | Open for the other agent · escalate to Youness · settle |
| `team_log` | Note something worth knowing that needs no reply |

**Live state lives outside both git checkouts**, at `C:\Users\hp\genzhype-bus\`:

```
genzhype-bus/
  state.json       the bus (one shared file, both agents read/write it)
  dashboard.html   regenerated on every write
```

That's deliberate. The two agents have separate checkouts of this repo; committing
live state would mean constant merge conflicts over it. Only the *durable* things —
settled decisions — get written back here as markdown.

---

## Install

Already done on this machine. Recorded so it can be rebuilt.

**Claude Code** — `~/.claude.json`, top-level `mcpServers`:

```json
{
  "genzhype-team": {
    "type": "stdio",
    "command": "node",
    "args": ["C:\\Users\\hp\\genzhype-repo\\_team\\bus\\server.mjs"],
    "env": {
      "TEAM_AGENT": "claude",
      "TEAM_ROOT": "C:\\Users\\hp\\genzhype-bus"
    }
  }
}
```

**ZCode** — `~/.zcode/cli/config.json`, under `mcp.servers`:

```json
{
  "mcp": {
    "servers": {
      "genzhype-team": {
        "type": "stdio",
        "command": "node",
        "args": ["C:\\Users\\hp\\genzhype-repo\\_team\\bus\\server.mjs"],
        "env": {
          "TEAM_AGENT": "glm",
          "TEAM_ROOT": "C:\\Users\\hp\\genzhype-bus"
        },
        "timeoutMs": 60000
      }
    }
  }
}
```

Only `TEAM_AGENT` differs — that's how the bus knows who is calling.

**Both apps must be restarted** after the config is written; MCP servers connect at
session start. Verify in ZCode under **Settings → MCP** (should read *connected*);
in Claude Code, `team_brief` simply appearing as a tool is the confirmation.

Notes for whoever debugs this later, from ZCode's own MCP guide:
- ZCode's config-file schema is **strict** — an unknown key silently drops the server.
- `${...}` template variables are **not** expanded in config-file servers, only in
  plugin-provided ones. Absolute paths only.
- User scope overrides workspace scope for same-named servers.

**Requirements:** Node (built and tested on v24). Nothing to `npm install`.

---

## Files here

| Path | What |
|---|---|
| `00-BRIEF.md` | The whole project in one read — start here if you're new to it |
| `01-PROTOCOL.md` | How the two agents work together |
| `02-SPLIT.md` | Who owns what, and the contract between the halves |
| `bus/server.mjs` | The MCP server (single file, zero deps) |
| `archive/` | The original file-relay thread, before the bus existed |
