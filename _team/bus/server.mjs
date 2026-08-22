#!/usr/bin/env node
/**
 * GenZHype TEAM BUS — an MCP server shared by two coding agents.
 *
 * Claude Code and ZCode (GLM) both connect to this over stdio. Instead of
 * Youness carrying markdown files between them, each agent posts, reads,
 * claims work and records decisions as tool calls against one shared state
 * file. Youness watches the generated dashboard and rules on decisions.
 *
 *   state      _team/bus/state.json      (git-tracked, human-readable)
 *   dashboard  _team/dashboard.html      (regenerated on every write)
 *
 * DESIGN CONSTRAINTS (deliberate):
 *   - Zero dependencies. Nothing is installed to run this.
 *   - No network. stdio only; never opens a socket, never phones home.
 *   - No secrets. The state file lives in a PUBLIC repo — the tools reject
 *     anything that looks like a credential before it can be written.
 *
 * Identity comes from the env var TEAM_AGENT ("claude" | "glm"), set by each
 * client in its own MCP config. Root comes from TEAM_ROOT, else it is derived
 * from this file's location.
 */

import { readFileSync, writeFileSync, renameSync, existsSync, mkdirSync,
         openSync, closeSync, unlinkSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// TEAM_ROOT is the LIVE bus directory. It deliberately lives OUTSIDE both
// agents' git checkouts: the state is shared runtime data, and committing it
// would mean two checkouts constantly merge-conflicting over it. Durable
// things (settled decisions) get exported to the repo as markdown instead.
const HERE      = dirname(fileURLToPath(import.meta.url));
const TEAM_ROOT = resolve(process.env.TEAM_ROOT || join(HERE, '..', 'live'));
const STATE     = join(TEAM_ROOT, 'state.json');
const LOCK      = join(TEAM_ROOT, '.lock');
const DASHBOARD = join(TEAM_ROOT, 'dashboard.html');

const ME     = (process.env.TEAM_AGENT || 'unknown').toLowerCase();
const AGENTS = ['claude', 'glm'];
const OTHER  = ME === 'claude' ? 'glm' : 'claude';

const PROTOCOL_FALLBACK = '2025-06-18';
const MAX_LOG = 200, MAX_MESSAGES = 500;

// ─── secret guard ──────────────────────────────────────────────────────────
// The state file is committed to a PUBLIC repo. Refuse writes that carry
// anything credential-shaped. Better a false positive than a leaked key.
const SECRET_PATTERNS = [
  [/\bsk-[A-Za-z0-9_-]{16,}/,                  'an sk- API key'],
  [/\bnvapi-[A-Za-z0-9_-]{16,}/,               'an NVIDIA key'],
  [/\bgsk_[A-Za-z0-9]{20,}/,                   'a Groq key'],
  [/\bghp_[A-Za-z0-9]{20,}/,                   'a GitHub token'],
  [/\bAIza[A-Za-z0-9_-]{20,}/,                 'a Google API key'],
  [/\bEAA[A-Za-z0-9]{40,}/,                    'a Meta access token'],
  [/\bcfat_[A-Za-z0-9]{20,}/,                  'a Cloudflare token'],
  [/\bpina_[A-Za-z0-9]{20,}/,                  'a Pinterest token'],
  [/\b[A-Za-z0-9+/]{40,}={0,2}\s*$/m,          'a long base64 blob'],
  [/(password|passwd|secret|api[_-]?key|token)\s*[:=]\s*["']?[A-Za-z0-9!@#$%^&*_+\-\/]{12,}/i,
                                               'an inline credential'],
];

function assertNoSecrets(text, field) {
  if (typeof text !== 'string') return;
  for (const [re, what] of SECRET_PATTERNS) {
    if (re.test(text)) {
      throw new Error(
        `Refused: "${field}" looks like it contains ${what}. The team state file is ` +
        `committed to a PUBLIC repo. Describe the value by name and location ` +
        `(e.g. "the NVIDIA director key in config.php") instead of pasting it.`
      );
    }
  }
}

// ─── state ────────────────────────────────────────────────────────────────
const now = () => new Date().toISOString();

function blankState() {
  const agents = {};
  for (const a of AGENTS) {
    agents[a] = { status: 'idle', task: '', note: '', updated_at: null };
  }
  return { version: 1, updated_at: now(), seq: 0,
           agents, messages: [], claims: [], decisions: [], log: [] };
}

function loadState() {
  if (!existsSync(STATE)) return blankState();
  try {
    const s = JSON.parse(readFileSync(STATE, 'utf8'));
    // tolerate a hand-edited or partially-written file rather than dying
    const base = blankState();
    return { ...base, ...s, agents: { ...base.agents, ...(s.agents || {}) } };
  } catch (e) {
    return blankState();
  }
}

/** Crude cross-process lock. Both agents are on one machine and rarely write
 *  at the same instant; this only has to stop a torn read-modify-write. */
function withLock(fn) {
  mkdirSync(dirname(LOCK), { recursive: true });
  let fd = null;
  for (let i = 0; i < 60; i++) {
    try { fd = openSync(LOCK, 'wx'); break; }
    catch {
      // reap a lock orphaned by a crashed process
      try {
        if (Date.now() - statSync(LOCK).mtimeMs > 10_000) { unlinkSync(LOCK); continue; }
      } catch {}
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
    }
  }
  try {
    return fn();
  } finally {
    if (fd !== null) { try { closeSync(fd); } catch {} }
    try { unlinkSync(LOCK); } catch {}
  }
}

function saveState(s) {
  s.updated_at = now();
  mkdirSync(dirname(STATE), { recursive: true });
  const tmp = STATE + '.tmp';
  writeFileSync(tmp, JSON.stringify(s, null, 2) + '\n', 'utf8');
  renameSync(tmp, STATE);          // atomic on the same volume
  try { writeFileSync(DASHBOARD, renderDashboard(s), 'utf8'); } catch {}
}

const mutate = (fn) => withLock(() => { const s = loadState(); const r = fn(s); saveState(s); return r; });

// ─── helpers ──────────────────────────────────────────────────────────────
function ago(iso) {
  if (!iso) return 'never';
  const m = Math.floor((Date.now() - Date.parse(iso)) / 60000);
  if (!Number.isFinite(m)) return 'unknown';
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  return h < 24 ? `${h}h ago` : `${Math.floor(h / 24)}d ago`;
}

function requireKnownAgent() {
  if (!AGENTS.includes(ME)) {
    throw new Error(
      `This bus does not know who you are. TEAM_AGENT is "${ME}" but must be ` +
      `one of: ${AGENTS.join(', ')}. Set it in your MCP client config.`
    );
  }
}

const text = (t) => ({ content: [{ type: 'text', text: t }] });

// ─── tools ────────────────────────────────────────────────────────────────
const TOOLS = {

  team_brief: {
    description:
      'READ THIS FIRST, every turn, before doing any GenZHype work. Returns who ' +
      'you are, unread messages from the other agent, what each of you is working ' +
      'on, which files are claimed (do not edit a file claimed by the other), and ' +
      'any open decisions. One call orients you completely.',
    schema: { type: 'object', properties: {}, additionalProperties: false },
    run() {
      requireKnownAgent();
      const s = loadState();
      const unread = s.messages.filter(m => m.to === ME && !m.read_by.includes(ME));
      const mine = s.agents[ME] || {}, theirs = s.agents[OTHER] || {};
      const open = s.decisions.filter(d => d.status !== 'settled');
      const myClaims = s.claims.filter(c => c.by === ME);
      const theirClaims = s.claims.filter(c => c.by === OTHER);

      const L = [];
      L.push(`YOU ARE: ${ME}   ·   PARTNER: ${OTHER}`);
      L.push(`Bus last updated ${ago(s.updated_at)}.`);
      L.push('');
      L.push(`── UNREAD FOR YOU: ${unread.length} ──`);
      if (!unread.length) L.push('  (nothing new — check the log below for what they have been doing)');
      for (const m of unread) {
        L.push(`  #${m.id} from ${m.from}, ${ago(m.at)} — ${m.re}`);
        if (m.asks) L.push(`     ASKS: ${m.asks}`);
      }
      L.push('');
      L.push('── WHO IS DOING WHAT ──');
      L.push(`  you   [${mine.status || 'idle'}] ${mine.task || '(no task set)'} — ${ago(mine.updated_at)}`);
      L.push(`  ${OTHER.padEnd(5)} [${theirs.status || 'idle'}] ${theirs.task || '(no task set)'} — ${ago(theirs.updated_at)}`);
      L.push('');
      L.push('── FILE CLAIMS ──');
      L.push(`  yours: ${myClaims.length ? myClaims.map(c => c.path).join(', ') : '(none)'}`);
      L.push(`  ${OTHER}'s (DO NOT EDIT): ${theirClaims.length ? theirClaims.map(c => c.path).join(', ') : '(none)'}`);
      L.push('');
      L.push(`── OPEN DECISIONS: ${open.length} ──`);
      for (const d of open) {
        L.push(`  ${d.id} [${d.status}] ${d.title}`);
        if (d.status === 'boss') L.push('     ^ waiting on Youness, not on you');
      }
      if (!open.length) L.push('  (none)');
      L.push('');
      L.push('── RECENT ACTIVITY ──');
      for (const e of s.log.slice(-8)) L.push(`  ${ago(e.at).padEnd(10)} ${e.by}: ${e.text}`);
      if (!s.log.length) L.push('  (nothing logged yet)');
      L.push('');
      L.push('Next: team_inbox to read messages in full, team_status to say what you are starting.');
      return text(L.join('\n'));
    },
  },

  team_inbox: {
    description:
      'Read your unread messages in full and mark them read. Call after ' +
      'team_brief says you have unread mail.',
    schema: { type: 'object',
      properties: { peek: { type: 'boolean', description: 'true = read without marking as read' } },
      additionalProperties: false },
    run(args) {
      requireKnownAgent();
      const peek = !!args.peek;
      const out = mutate((s) => {
        const unread = s.messages.filter(m => m.to === ME && !m.read_by.includes(ME));
        if (!peek) for (const m of unread) m.read_by.push(ME);
        return unread;
      });
      if (!out.length) return text('No unread messages.');
      return text(out.map(m =>
        `━━━ #${m.id}  ${m.from} → ${m.to}  ·  ${m.at}\n` +
        `RE:   ${m.re}\n` +
        (m.asks ? `ASKS: ${m.asks}\n` : '') + `\n${m.body}`
      ).join('\n\n'));
    },
  },

  team_send: {
    description:
      'Send a message to the other agent. It lands in their inbox and they see ' +
      'it on their next team_brief — Youness does not have to carry it. Be ' +
      'concrete: paths, line numbers, real error text. Never paste credentials.',
    schema: { type: 'object',
      properties: {
        re:   { type: 'string', description: 'One-line subject.' },
        body: { type: 'string', description: 'The message. Markdown is fine.' },
        asks: { type: 'string', description: 'What you need back, or "nothing — FYI".' },
      },
      required: ['re', 'body'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.body, 'body');
      assertNoSecrets(args.re, 're');
      const id = mutate((s) => {
        const id = ++s.seq;
        s.messages.push({ id, from: ME, to: OTHER, re: args.re, body: args.body,
                          asks: args.asks || '', at: now(), read_by: [] });
        if (s.messages.length > MAX_MESSAGES) s.messages = s.messages.slice(-MAX_MESSAGES);
        s.log.push({ at: now(), by: ME, text: `sent #${id} to ${OTHER}: ${args.re}` });
        if (s.log.length > MAX_LOG) s.log = s.log.slice(-MAX_LOG);
        return id;
      });
      return text(`Sent #${id} to ${OTHER}. They will see it on their next team_brief.`);
    },
  },

  team_status: {
    description:
      'Say what you are working on right now. Call it when you START something, ' +
      'not when you finish — the point is that the other agent and Youness can ' +
      'see work in flight and avoid colliding with it.',
    schema: { type: 'object',
      properties: {
        status: { type: 'string', enum: ['idle', 'working', 'blocked', 'done'] },
        task:   { type: 'string', description: 'One line: what you are doing.' },
        note:   { type: 'string', description: 'If blocked, what unblocks you.' },
      },
      required: ['status', 'task'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.task, 'task');
      assertNoSecrets(args.note, 'note');
      mutate((s) => {
        s.agents[ME] = { status: args.status, task: args.task,
                         note: args.note || '', updated_at: now() };
        s.log.push({ at: now(), by: ME, text: `[${args.status}] ${args.task}` });
        if (s.log.length > MAX_LOG) s.log = s.log.slice(-MAX_LOG);
      });
      return text(`Status set: [${args.status}] ${args.task}`);
    },
  },

  team_claim: {
    description:
      'Claim files before you edit them. Neither agent can see the other\'s ' +
      'working tree, so a claim is the only thing preventing two of you editing ' +
      'one file blind. Fails if the other agent already holds it.',
    schema: { type: 'object',
      properties: {
        paths: { type: 'array', items: { type: 'string' },
                 description: 'Repo-relative paths, e.g. ["video_maker.py"].' },
        note:  { type: 'string', description: 'Why — one line.' },
      },
      required: ['paths'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      const res = mutate((s) => {
        const taken = [], got = [];
        for (const p of args.paths) {
          const held = s.claims.find(c => c.path === p);
          if (held && held.by !== ME) { taken.push(`${p} (held by ${held.by}: ${held.note || 'no note'})`); continue; }
          if (!held) s.claims.push({ path: p, by: ME, at: now(), note: args.note || '' });
          got.push(p);
        }
        if (got.length) s.log.push({ at: now(), by: ME, text: `claimed ${got.join(', ')}` });
        return { taken, got };
      });
      let out = res.got.length ? `Claimed: ${res.got.join(', ')}` : 'Claimed nothing.';
      if (res.taken.length) {
        out += `\n\nREFUSED — ${OTHER} holds these:\n  ${res.taken.join('\n  ')}\n` +
               `Use team_send to ask them for the change instead of editing it yourself.`;
      }
      return text(out);
    },
  },

  team_release: {
    description: 'Release your claims once the work has landed.',
    schema: { type: 'object',
      properties: {
        paths: { type: 'array', items: { type: 'string' },
                 description: 'Paths to release. Omit to release all of yours.' },
      }, additionalProperties: false },
    run(args) {
      requireKnownAgent();
      const n = mutate((s) => {
        const before = s.claims.length;
        s.claims = s.claims.filter(c =>
          c.by !== ME || (args.paths?.length ? !args.paths.includes(c.path) : false));
        const freed = before - s.claims.length;
        if (freed) s.log.push({ at: now(), by: ME, text: `released ${freed} claim(s)` });
        return freed;
      });
      return text(`Released ${n} claim(s).`);
    },
  },

  team_decision: {
    description:
      'Open, agree, or escalate a decision. Use "open" when something needs the ' +
      'other agent\'s agreement (a shared schema, a contract change). Use "boss" ' +
      'when it is Youness\'s call, not ours — it then shows at the top of his ' +
      'dashboard. Use "settle" to record what was agreed.',
    schema: { type: 'object',
      properties: {
        action: { type: 'string', enum: ['open', 'boss', 'settle', 'list'] },
        id:     { type: 'string', description: 'For settle: the decision id, e.g. "D-003".' },
        title:  { type: 'string', description: 'For open/boss: one line.' },
        detail: { type: 'string', description: 'The options and your recommendation.' },
        resolution: { type: 'string', description: 'For settle: what was decided and why.' },
      },
      required: ['action'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.detail, 'detail');
      assertNoSecrets(args.resolution, 'resolution');
      if (args.action === 'list') {
        const s = loadState();
        if (!s.decisions.length) return text('No decisions recorded.');
        return text(s.decisions.map(d =>
          `${d.id} [${d.status}] ${d.title}\n   by ${d.by}, ${ago(d.at)}` +
          (d.detail ? `\n   ${d.detail}` : '') +
          (d.resolution ? `\n   RESOLVED: ${d.resolution}` : '')).join('\n\n'));
      }
      const out = mutate((s) => {
        if (args.action === 'settle') {
          const d = s.decisions.find(x => x.id === args.id);
          if (!d) throw new Error(`No decision "${args.id}". Use action:"list" to see them.`);
          d.status = 'settled';
          d.resolution = args.resolution || '';
          d.settled_at = now();
          s.log.push({ at: now(), by: ME, text: `settled ${d.id}: ${d.title}` });
          return `Settled ${d.id}.`;
        }
        if (!args.title) throw new Error('title is required to open a decision.');
        const id = 'D-' + String(s.decisions.length + 1).padStart(3, '0');
        s.decisions.push({ id, title: args.title, detail: args.detail || '',
                           status: args.action === 'boss' ? 'boss' : 'open',
                           by: ME, at: now(), resolution: '' });
        s.log.push({ at: now(), by: ME,
                     text: `opened ${id} (${args.action === 'boss' ? 'for Youness' : `for ${OTHER}`}): ${args.title}` });
        return args.action === 'boss'
          ? `Opened ${id} for Youness. It is now at the top of his dashboard.`
          : `Opened ${id} for ${OTHER}.`;
      });
      return text(out);
    },
  },

  team_log: {
    description:
      'Record something you did or found that the other agent should know but ' +
      'that does not need a reply. Cheaper than a message; shows in both briefs.',
    schema: { type: 'object',
      properties: { text: { type: 'string' } },
      required: ['text'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.text, 'text');
      mutate((s) => {
        s.log.push({ at: now(), by: ME, text: args.text });
        if (s.log.length > MAX_LOG) s.log = s.log.slice(-MAX_LOG);
      });
      return text('Logged.');
    },
  },
};

// ─── dashboard ────────────────────────────────────────────────────────────
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function renderDashboard(s) {
  const boss = s.decisions.filter(d => d.status === 'boss');
  const open = s.decisions.filter(d => d.status === 'open');

  const agentCard = (name) => {
    const a = s.agents[name] || {};
    const unread = s.messages.filter(m => m.to === name && !m.read_by.includes(name)).length;
    const claims = s.claims.filter(c => c.by === name);
    return `<div class="card">
      <div class="who"><span class="dot ${esc(a.status || 'idle')}"></span>${esc(name)}</div>
      <div class="state">${esc(a.status || 'idle')} · ${esc(ago(a.updated_at))}</div>
      <div class="task">${esc(a.task || 'nothing set')}</div>
      ${a.note ? `<div class="note">${esc(a.note)}</div>` : ''}
      <div class="meta">${unread} unread · ${claims.length} file${claims.length === 1 ? '' : 's'} claimed</div>
      ${claims.length ? `<div class="claims">${claims.map(c => `<code>${esc(c.path)}</code>`).join(' ')}</div>` : ''}
    </div>`;
  };

  return `<title>GenZHype Build Room</title>
<style>
  :root{--bg:#faf9f7;--fg:#1a1a1a;--dim:#6b6b6b;--line:#e3e0da;--card:#fff;
        --accent:#C71F12;--warn:#8a5a00;--warnbg:#fff6e0;--ok:#1a7f4b;--code:#f2efe9}
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
        --bg:#16161a;--fg:#ececec;--dim:#9a9a9a;--line:#2c2c33;--card:#1e1e24;
        --accent:#FF6A5C;--warn:#e0b050;--warnbg:#2e2515;--ok:#4ec98a;--code:#26262e}}
  :root[data-theme="dark"]{--bg:#16161a;--fg:#ececec;--dim:#9a9a9a;--line:#2c2c33;
        --card:#1e1e24;--accent:#FF6A5C;--warn:#e0b050;--warnbg:#2e2515;--ok:#4ec98a;--code:#26262e}
  *{box-sizing:border-box}
  body{margin:0;padding:28px 20px 60px;background:var(--bg);color:var(--fg);
       font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
  .wrap{max-width:940px;margin:0 auto}
  h1{font-size:21px;margin:0 0 2px;letter-spacing:-.01em}
  .sub{color:var(--dim);font-size:13px;margin-bottom:26px}
  h2{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);
     margin:32px 0 12px;font-weight:600}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px}
  .who{font-weight:650;font-size:16px;display:flex;align-items:center;gap:8px}
  .dot{width:9px;height:9px;border-radius:50%;background:var(--dim);flex:none}
  .dot.working{background:var(--ok)} .dot.blocked{background:var(--accent)}
  .dot.done{background:var(--ok);opacity:.5}
  .state{color:var(--dim);font-size:12px;margin:2px 0 10px}
  .task{font-size:14px}
  .note{margin-top:8px;font-size:13px;color:var(--warn)}
  .meta{margin-top:12px;padding-top:10px;border-top:1px solid var(--line);
        color:var(--dim);font-size:12px}
  .claims{margin-top:8px;display:flex;flex-wrap:wrap;gap:5px}
  code{background:var(--code);padding:1px 6px;border-radius:4px;
       font:12px ui-monospace,"Cascadia Code",Consolas,monospace;word-break:break-all}
  .boss{background:var(--warnbg);border:1px solid var(--warn);border-radius:10px;
        padding:16px;margin-bottom:12px}
  .boss .t{font-weight:650;margin-bottom:6px}
  .boss .d{font-size:14px;white-space:pre-wrap}
  .boss .by{color:var(--dim);font-size:12px;margin-top:8px}
  .row{padding:10px 0;border-bottom:1px solid var(--line);font-size:14px}
  .row:last-child{border-bottom:0}
  .row .by{color:var(--accent);font-weight:600}
  .row .t{color:var(--dim);font-size:12px;float:right}
  .empty{color:var(--dim);font-size:14px;font-style:italic}
  .foot{margin-top:40px;padding-top:16px;border-top:1px solid var(--line);
        color:var(--dim);font-size:12px}
</style>
<div class="wrap">
  <h1>GenZHype — build room</h1>
  <div class="sub">Claude + GLM · updated ${esc(ago(s.updated_at))} · regenerated on every bus write</div>

  ${boss.length ? `<h2>⚠ Waiting on you</h2>` + boss.map(d => `<div class="boss">
      <div class="t">${esc(d.id)} — ${esc(d.title)}</div>
      <div class="d">${esc(d.detail)}</div>
      <div class="by">raised by ${esc(d.by)}, ${esc(ago(d.at))}</div>
    </div>`).join('') : ''}

  <h2>Agents</h2>
  <div class="grid">${AGENTS.map(agentCard).join('')}</div>

  <h2>Open between the two of them (${open.length})</h2>
  ${open.length ? open.map(d => `<div class="row">
      <span class="t">${esc(ago(d.at))}</span>
      <span class="by">${esc(d.id)}</span> ${esc(d.title)}
    </div>`).join('') : '<div class="empty">Nothing open.</div>'}

  <h2>Activity</h2>
  ${s.log.length ? s.log.slice(-25).reverse().map(e => `<div class="row">
      <span class="t">${esc(ago(e.at))}</span>
      <span class="by">${esc(e.by)}</span> ${esc(e.text)}
    </div>`).join('') : '<div class="empty">Nothing yet.</div>'}

  <div class="foot">
    Read-only view of <code>${esc(STATE)}</code>. To rule on a decision, tell either
    agent in plain words — they record it with <code>team_decision</code>.
    Refresh this page after they act.
  </div>
</div>`;
}

// ─── MCP over stdio (newline-delimited JSON-RPC 2.0, zero deps) ────────────
function send(msg) { process.stdout.write(JSON.stringify(msg) + '\n'); }
const ok  = (id, result) => send({ jsonrpc: '2.0', id, result });
const err = (id, code, message) => send({ jsonrpc: '2.0', id, error: { code, message } });

function handle(msg) {
  const { id, method, params } = msg;
  if (method === 'initialize') {
    return ok(id, {
      protocolVersion: params?.protocolVersion || PROTOCOL_FALLBACK,
      capabilities: { tools: {} },
      serverInfo: { name: 'genzhype-team-bus', version: '1.0.0' },
      instructions:
        `You are "${ME}" on the GenZHype build room bus, working alongside "${OTHER}". ` +
        `Call team_brief before starting any GenZHype work — it tells you what ${OTHER} ` +
        `has done, what is waiting for you, and which files you must not touch. ` +
        `Claim files with team_claim before editing them.`,
    });
  }
  if (method === 'notifications/initialized' || method === 'notifications/cancelled') return;
  if (method === 'ping') return ok(id, {});
  if (method === 'tools/list') {
    return ok(id, { tools: Object.entries(TOOLS).map(([name, t]) =>
      ({ name, description: t.description, inputSchema: t.schema })) });
  }
  if (method === 'tools/call') {
    const t = TOOLS[params?.name];
    if (!t) return err(id, -32601, `Unknown tool: ${params?.name}`);
    try { return ok(id, t.run(params.arguments || {})); }
    catch (e) { return ok(id, { ...text(`Error: ${e.message}`), isError: true }); }
  }
  if (id !== undefined) err(id, -32601, `Method not found: ${method}`);
}

let buf = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buf += chunk;
  let i;
  while ((i = buf.indexOf('\n')) >= 0) {
    const line = buf.slice(0, i).trim();
    buf = buf.slice(i + 1);
    if (!line) continue;
    let msg;
    try { msg = JSON.parse(line); }
    catch { continue; }               // ignore junk rather than crash the bus
    try { handle(msg); }
    catch (e) { if (msg?.id !== undefined) err(msg.id, -32603, e.message); }
  }
});
process.stdin.on('end', () => process.exit(0));

// Make sure the state file and dashboard exist on first launch.
if (!existsSync(STATE)) { try { withLock(() => saveState(blankState())); } catch {} }
