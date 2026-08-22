#!/usr/bin/env node
/**
 * GenZHype TEAM BUS — the MCP server both coding agents connect to.
 *
 * Claude Code and ZCode (GLM) each run a copy over stdio. Instead of Youness
 * carrying markdown between them, each agent posts, reads, claims work and
 * records decisions as tool calls against one shared state file. Youness acts
 * on the same state from the localhost room (bus/room.mjs).
 *
 * Zero dependencies. No network — stdio only. Never writes a credential:
 * see assertNoSecrets in state.mjs.
 *
 * Identity is TEAM_AGENT ("claude" | "glm"), set by each client's MCP config.
 * The live bus directory is TEAM_ROOT.
 */

import { existsSync } from 'node:fs';
import { loadState, mutate, saveState, blankState, withLock, pushLog, pushMessage,
         assertNoSecrets, ago, now, AGENTS, BOSS, STATE } from './state.mjs';

const ME    = (process.env.TEAM_AGENT || 'unknown').toLowerCase();
const OTHER = ME === 'claude' ? 'glm' : 'claude';
const PROTOCOL_FALLBACK = '2025-06-18';

function requireKnownAgent() {
  if (!AGENTS.includes(ME)) {
    throw new Error(
      `This bus does not know who you are. TEAM_AGENT is "${ME}" but must be one of: ` +
      `${AGENTS.join(', ')}. Set it in your MCP client config.`);
  }
}

const text = (t) => ({ content: [{ type: 'text', text: t }] });
const who  = (n) => (n === BOSS ? 'Youness (the boss)' : n);

/** Enforce the inputSchema we advertise. Clients are NOT obliged to validate,
 *  and an unchecked `status` was how an agent-supplied string reached the page
 *  raw. ~25 lines, no dependency, covers everything these schemas declare. */
function validateArgs(schema, args) {
  if (typeof args !== 'object' || args === null || Array.isArray(args)) {
    throw new Error('arguments must be an object');
  }
  const props = schema.properties || {};
  if (schema.additionalProperties === false) {
    for (const k of Object.keys(args)) {
      if (!(k in props)) throw new Error(`unknown argument "${k}"`);
    }
  }
  for (const k of (schema.required || [])) {
    if (args[k] === undefined || args[k] === null) throw new Error(`"${k}" is required`);
  }
  for (const [k, spec] of Object.entries(props)) {
    const v = args[k];
    if (v === undefined) continue;
    if (spec.type === 'string'  && typeof v !== 'string')  throw new Error(`"${k}" must be a string`);
    if (spec.type === 'boolean' && typeof v !== 'boolean') throw new Error(`"${k}" must be true or false`);
    if (spec.type === 'number'  && typeof v !== 'number')  throw new Error(`"${k}" must be a number`);
    if (spec.type === 'array') {
      if (!Array.isArray(v)) throw new Error(`"${k}" must be an array`);
      if (spec.items?.type === 'string' && v.some(x => typeof x !== 'string')) {
        throw new Error(`"${k}" must be an array of strings`);
      }
    }
    if (spec.enum && !spec.enum.includes(v)) {
      throw new Error(`"${k}" must be one of: ${spec.enum.join(', ')}`);
    }
  }
}

// ─── tools ────────────────────────────────────────────────────────────────
const TOOLS = {

  team_brief: {
    description:
      'READ THIS FIRST, every turn, before doing any GenZHype work. Returns who you ' +
      'are, unread messages (from the other agent AND from Youness), what each of you ' +
      'is working on, which files are claimed (never edit a file the other holds), and ' +
      'open decisions. One call orients you completely.',
    schema: { type: 'object', properties: {}, additionalProperties: false },
    run() {
      requireKnownAgent();
      const s = loadState();
      const unread = s.messages.filter(m => m.to === ME && !m.read_by.includes(ME));
      const fromBoss = unread.filter(m => m.from === BOSS);
      const fromPeer = unread.filter(m => m.from !== BOSS);
      const mine = s.agents[ME] || {}, theirs = s.agents[OTHER] || {};
      const open = s.decisions.filter(d => d.status === 'open');
      const waiting = s.decisions.filter(d => d.status === 'boss');
      const myClaims = s.claims.filter(c => c.by === ME);
      const theirClaims = s.claims.filter(c => c.by === OTHER);

      const L = [];
      L.push(`YOU ARE: ${ME}   ·   PARTNER: ${OTHER}   ·   BOSS: Youness`);
      L.push(`Bus last updated ${ago(s.updated_at)}.`);
      L.push('');
      if (fromBoss.length) {
        L.push(`── ⚠ FROM YOUNESS — READ THESE FIRST: ${fromBoss.length} ──`);
        for (const m of fromBoss) L.push(`  #${m.id} ${ago(m.at)} — ${m.re}`);
        L.push('  His instructions outrank the plan. team_inbox to read them in full.');
        L.push('');
      }
      L.push(`── UNREAD FROM ${OTHER.toUpperCase()}: ${fromPeer.length} ──`);
      if (!fromPeer.length) L.push('  (nothing new — see the activity log below)');
      for (const m of fromPeer) {
        L.push(`  #${m.id} ${ago(m.at)} — ${m.re}`);
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
      L.push(`── DECISIONS ──`);
      for (const d of open) L.push(`  ${d.id} [needs ${OTHER === ME ? 'agreement' : 'you both'}] ${d.title}`);
      for (const d of waiting) L.push(`  ${d.id} [waiting on Youness — not on you] ${d.title}`);
      if (!open.length && !waiting.length) L.push('  (none open)');
      L.push('');
      L.push('── RECENT ACTIVITY ──');
      for (const e of s.log.slice(-10)) L.push(`  ${ago(e.at).padEnd(10)} ${who(e.by)}: ${e.text}`);
      if (!s.log.length) L.push('  (nothing logged yet)');
      L.push('');
      L.push('Next: team_inbox to read mail, team_status to say what you are starting, ' +
             'team_claim before you edit anything.');
      return text(L.join('\n'));
    },
  },

  team_inbox: {
    description: 'Read your unread messages in full and mark them read.',
    schema: { type: 'object',
      properties: { peek: { type: 'boolean', description: 'true = read without marking read' } },
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
        `━━━ #${m.id}  ${who(m.from)} → ${m.to}  ·  ${m.at}\n` +
        `RE:   ${m.re}\n` + (m.asks ? `ASKS: ${m.asks}\n` : '') + `\n${m.body}`
      ).join('\n\n'));
    },
  },

  team_send: {
    description:
      'Send a message to the other agent. It lands in their inbox and they see it on ' +
      'their next team_brief — Youness does not carry it. Be concrete: paths, line ' +
      'numbers, real error text. Never paste credentials.',
    schema: { type: 'object',
      properties: {
        re:   { type: 'string', description: 'One-line subject.' },
        body: { type: 'string', description: 'The message. Markdown is fine.' },
        asks: { type: 'string', description: 'What you need back, or "nothing — FYI".' },
      }, required: ['re', 'body'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.body, 'body');
      assertNoSecrets(args.re, 're');
      const id = mutate((s) => {
        const id = pushMessage(s, ME, OTHER, args.re, args.body, args.asks || '');
        pushLog(s, ME, `sent #${id} to ${OTHER}: ${args.re}`);
        return id;
      });
      return text(`Sent #${id} to ${OTHER}. They will see it on their next team_brief.`);
    },
  },

  team_reply_boss: {
    description:
      'Answer Youness directly — for a question he asked, or to tell him something he ' +
      'needs to decide on. It shows on his dashboard. Use plain language, no jargon: ' +
      'he is the boss, not an engineer on this codebase.',
    schema: { type: 'object',
      properties: { text: { type: 'string' } },
      required: ['text'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.text, 'text');
      mutate((s) => pushLog(s, ME, `→ Youness: ${args.text}`));
      return text('Posted to the dashboard for Youness.');
    },
  },

  team_status: {
    description:
      'Say what you are working on right now. Call it when you START something, not ' +
      'when you finish — the point is that work in flight is visible to the other ' +
      'agent and to Youness.',
    schema: { type: 'object',
      properties: {
        status: { type: 'string', enum: ['idle', 'working', 'blocked', 'done'] },
        task:   { type: 'string', description: 'One line: what you are doing.' },
        note:   { type: 'string', description: 'If blocked, what unblocks you.' },
      }, required: ['status', 'task'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.task, 'task');
      assertNoSecrets(args.note, 'note');
      assertNoSecrets(args.status, 'status');
      mutate((s) => {
        s.agents[ME] = { status: args.status, task: args.task,
                         note: args.note || '', updated_at: now() };
        pushLog(s, ME, `[${args.status}] ${args.task}`);
      });
      return text(`Status set: [${args.status}] ${args.task}`);
    },
  },

  team_claim: {
    description:
      'Claim files before you edit them. Neither agent can see the other\'s working ' +
      'tree, so a claim is the only thing preventing two of you editing one file ' +
      'blind. Fails if the other agent already holds it.',
    schema: { type: 'object',
      properties: {
        paths: { type: 'array', items: { type: 'string' },
                 description: 'Repo-relative paths, e.g. ["video_maker.py"].' },
        note:  { type: 'string', description: 'Why — one line.' },
      }, required: ['paths'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      const res = mutate((s) => {
        const taken = [], got = [];
        for (const p of args.paths) {
          const held = s.claims.find(c => c.path === p);
          if (held && held.by !== ME) {
            taken.push(`${p} (held by ${held.by}: ${held.note || 'no note'})`); continue;
          }
          if (!held) s.claims.push({ path: p, by: ME, at: now(), note: args.note || '' });
          got.push(p);
        }
        if (got.length) pushLog(s, ME, `claimed ${got.join(', ')}`);
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
      properties: { paths: { type: 'array', items: { type: 'string' },
        description: 'Paths to release. Omit to release all of yours.' } },
      additionalProperties: false },
    run(args) {
      requireKnownAgent();
      const n = mutate((s) => {
        const before = s.claims.length;
        s.claims = s.claims.filter(c =>
          c.by !== ME || (args.paths?.length ? !args.paths.includes(c.path) : false));
        const freed = before - s.claims.length;
        if (freed) pushLog(s, ME, `released ${freed} claim(s)`);
        return freed;
      });
      return text(`Released ${n} claim(s).`);
    },
  },

  team_decision: {
    description:
      'Open, escalate, or settle a decision. "open" = needs the other agent to agree ' +
      '(a shared schema, a contract change). "boss" = genuinely Youness\'s call, not ' +
      'ours; it goes to the top of his dashboard with Approve/Decline buttons, so ' +
      'write the detail in plain language and include your recommendation. "settle" = ' +
      'record what was agreed. Do not escalate what the two of you can settle.',
    schema: { type: 'object',
      properties: {
        action: { type: 'string', enum: ['open', 'boss', 'settle', 'list'] },
        id:     { type: 'string', description: 'For settle: the decision id, e.g. "D-003".' },
        title:  { type: 'string', description: 'For open/boss: one line.' },
        detail: { type: 'string', description: 'The options and your recommendation.' },
        resolution: { type: 'string', description: 'For settle: what was decided and why.' },
      }, required: ['action'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.detail, 'detail');
      assertNoSecrets(args.resolution, 'resolution');
      if (args.action === 'list') {
        const s = loadState();
        if (!s.decisions.length) return text('No decisions recorded.');
        return text(s.decisions.map(d =>
          `${d.id} [${d.status}] ${d.title}\n   by ${who(d.by)}, ${ago(d.at)}` +
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
          pushLog(s, ME, `settled ${d.id}: ${d.title}`);
          return `Settled ${d.id}.`;
        }
        if (!args.title) throw new Error('title is required to open a decision.');
        const id = 'D-' + String(s.decisions.length + 1).padStart(3, '0');
        s.decisions.push({ id, title: args.title, detail: args.detail || '',
                           status: args.action === 'boss' ? 'boss' : 'open',
                           by: ME, at: now(), resolution: '' });
        pushLog(s, ME, `opened ${id} (${args.action === 'boss' ? 'for Youness' : `for ${OTHER}`}): ${args.title}`);
        return args.action === 'boss'
          ? `Opened ${id} for Youness — it is now at the top of his dashboard with ` +
            `Approve / Decline / Reply buttons.`
          : `Opened ${id} for ${OTHER}.`;
      });
      return text(out);
    },
  },

  team_log: {
    description:
      'Record something you did or found that the others should know but that needs ' +
      'no reply. Cheaper than a message; shows in both briefs and on the dashboard.',
    schema: { type: 'object', properties: { text: { type: 'string' } },
      required: ['text'], additionalProperties: false },
    run(args) {
      requireKnownAgent();
      assertNoSecrets(args.text, 'text');
      mutate((s) => pushLog(s, ME, args.text));
      return text('Logged.');
    },
  },
};

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
      serverInfo: { name: 'genzhype-team-bus', version: '1.1.0' },
      instructions:
        `You are "${ME}" on the GenZHype build room bus, working alongside "${OTHER}", ` +
        `with Youness as the boss. Call team_brief before starting any GenZHype work — ` +
        `it tells you what ${OTHER} has done, what Youness has asked for, and which ` +
        `files you must not touch. Claim files with team_claim before editing them.`,
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
    const args = params.arguments || {};
    try { validateArgs(t.schema, args); }
    catch (e) { return err(id, -32602, `Invalid arguments for ${params.name}: ${e.message}`); }
    try { return ok(id, t.run(args)); }
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
    try { msg = JSON.parse(line); } catch { continue; }
    try { handle(msg); }
    catch (e) { if (msg?.id !== undefined) err(msg.id, -32603, e.message); }
  }
});
process.stdin.on('end', () => process.exit(0));

if (!existsSync(STATE)) { try { withLock(() => saveState(blankState())); } catch {} }
