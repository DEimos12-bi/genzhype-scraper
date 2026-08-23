/**
 * GenZHype build room — shared state layer.
 *
 * Imported by BOTH bus/server.mjs (the MCP server the two agents talk to) and
 * bus/room.mjs (the localhost page Youness acts from). One implementation of
 * the locking and the atomic write, so the two entry points cannot drift.
 *
 * Three participants: youness (the boss), claude, glm.
 */

import { readFileSync, writeFileSync, renameSync, existsSync, mkdirSync, appendFileSync,
         openSync, closeSync, unlinkSync, statSync, writeSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

// The LIVE bus directory, deliberately outside both agents' git checkouts:
// this is shared runtime state, and committing it would mean the two working
// copies merge-conflict over it constantly.
export const TEAM_ROOT = resolve(process.env.TEAM_ROOT || join(HERE, '..', 'live'));
export const STATE     = join(TEAM_ROOT, 'state.json');
export const LOCK      = join(TEAM_ROOT, '.lock');
export const DASHBOARD = join(TEAM_ROOT, 'dashboard.html');
export const VERDICTS  = join(TEAM_ROOT, 'verdicts.jsonl');

// THE EYES — the owner's verdicts are the only honest quality signal we have
// (the vision judge scored a faceless stock-still video 8/10). Each verdict is
// one JSONL line; the judge gets calibrated against these until it catches
// what he catches.
export const VERDICT_REASONS = [
  ['hook',     'Hook / first second'],
  ['story',    'Wrong story choice'],
  ['faces',    'No real faces'],
  ['visuals',  'Bad or repeated visuals'],
  ['captions', 'Captions'],
  ['voice',    'Voice'],
  ['pacing',   'Pacing / boring'],
  ['sound',    'Sound / music'],
  ['facts',    'Wrong facts or dates'],
  ['other',    'Something else'],
];

export function appendVerdict(v) {
  mkdirSync(dirname(VERDICTS), { recursive: true });
  appendFileSync(VERDICTS, JSON.stringify(v) + '\n', 'utf8');
}

export function readVerdicts(n = 3) {
  try {
    const lines = readFileSync(VERDICTS, 'utf8').trim().split('\n');
    return lines.slice(-n).map(l => { try { return JSON.parse(l); } catch { return null; } }).filter(Boolean);
  } catch { return []; }
}

// 2026-08-23 SOLO: the owner dropped the two-agent setup. GLM stood down
// (bus message #29 + board work-log). One agent, one boss, same room.
export const AGENTS = ['claude'];
export const BOSS   = 'youness';
export const MAX_LOG = 200, MAX_MESSAGES = 500;

export const now = () => new Date().toISOString();

export function ago(iso) {
  if (!iso) return 'never';
  const m = Math.floor((Date.now() - Date.parse(iso)) / 60000);
  if (!Number.isFinite(m)) return 'unknown';
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  return h < 24 ? `${h}h ago` : `${Math.floor(h / 24)}d ago`;
}

// ─── secret guard ──────────────────────────────────────────────────────────
// The repo this lives in is PUBLIC and the state file gets quoted into commits
// and messages. Refuse anything credential-shaped: a false positive costs one
// reworded sentence, a false negative costs a rotation.
const SECRET_PATTERNS = [
  [/\bsk-[A-Za-z0-9_-]{16,}/,    'an sk- API key'],
  [/\bnvapi-[A-Za-z0-9_-]{16,}/, 'an NVIDIA key'],
  [/\bgsk_[A-Za-z0-9]{20,}/,     'a Groq key'],
  [/\bgh[pousr]_[A-Za-z0-9]{20,}/, 'a GitHub token'],
  [/\bAIza[A-Za-z0-9_-]{20,}/,   'a Google API key'],
  [/\bEAA[A-Za-z0-9]{40,}/,      'a Meta access token'],
  [/\bcfat_[A-Za-z0-9]{20,}/,    'a Cloudflare token'],
  [/\bpina_[A-Za-z0-9]{20,}/,    'a Pinterest token'],
  [/\bxox[baprs]-[A-Za-z0-9-]{10,}/, 'a Slack token'],
  [/-----BEGIN [A-Z ]*PRIVATE KEY-----/, 'a private key'],
  [/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\./, 'a JWT'],
  [/(password|passwd|secret|api[_-]?key|token)\s*[:=]\s*["']?[A-Za-z0-9!@#$%^&*_+\-\/]{12,}/i,
                                 'an inline credential'],
];

export function assertNoSecrets(text, field) {
  if (typeof text !== 'string') return;
  for (const [re, what] of SECRET_PATTERNS) {
    if (re.test(text)) {
      throw new Error(
        `Refused: "${field}" looks like it contains ${what}. This state lives in a ` +
        `PUBLIC repo's orbit. Describe the value by name and location ` +
        `(e.g. "the NVIDIA director key in config.php") instead of pasting it.`
      );
    }
  }
}

// ─── load / save ──────────────────────────────────────────────────────────
export function blankState() {
  const agents = {};
  for (const a of AGENTS) agents[a] = { status: 'idle', task: '', note: '', updated_at: null };
  return { version: 1, updated_at: now(), seq: 0,
           agents, messages: [], claims: [], decisions: [], log: [] };
}

const STATUSES = ['idle', 'working', 'blocked', 'done'];

/** A torn write is FAR more likely to leave valid JSON of the wrong shape than
 *  invalid JSON, so catching a parse error is not enough — every field the
 *  renderer walks has to be forced back to its expected type. Without this a
 *  single `"decisions": null` takes the whole room server down. */
export function loadState() {
  const base = blankState();
  if (!existsSync(STATE)) return base;
  let s;
  try { s = JSON.parse(readFileSync(STATE, 'utf8')); }
  catch { return base; }
  if (!s || typeof s !== 'object' || Array.isArray(s)) return base;

  const out = { ...base, ...s };
  for (const k of ['messages', 'claims', 'decisions', 'log']) {
    if (!Array.isArray(out[k])) out[k] = [];
  }
  out.agents = (s.agents && typeof s.agents === 'object' && !Array.isArray(s.agents))
    ? { ...base.agents, ...s.agents } : base.agents;
  for (const a of AGENTS) {
    const v = out.agents[a];
    if (!v || typeof v !== 'object' || Array.isArray(v)) { out.agents[a] = base.agents[a]; continue; }
    if (!STATUSES.includes(v.status)) v.status = 'idle';   // also the XSS backstop
  }
  for (const m of out.messages) if (!Array.isArray(m.read_by)) m.read_by = [];
  if (typeof out.seq !== 'number' || !Number.isFinite(out.seq)) {
    out.seq = out.messages.reduce((n, m) => Math.max(n, Number(m.id) || 0), 0);
  }
  return out;
}

/** Is the process that wrote this lock still alive? Signal 0 tests for
 *  existence without delivering anything. */
function lockOwnerGone() {
  try {
    const { pid } = JSON.parse(readFileSync(LOCK, 'utf8'));
    if (typeof pid !== 'number') return true;
    if (pid === process.pid) return true;
    try { process.kill(pid, 0); return false; }   // still running
    catch (e) { return e.code === 'ESRCH'; }      // EPERM = alive, other user
  } catch { return true; }                        // unreadable/empty = orphan
}

/** Cross-process lock. Three writers on one machine: two MCP servers and the
 *  room. Only has to stop a torn read-modify-write, but it must never run the
 *  critical section WITHOUT the lock — the previous version fell through the
 *  acquire loop and did exactly that. */
export function withLock(fn) {
  mkdirSync(dirname(LOCK), { recursive: true });
  let fd = null;
  // 250 x 50ms = ~12.5s, deliberately longer than the 10s staleness window so
  // reaping happens before giving up, not after.
  for (let i = 0; i < 250; i++) {
    try {
      fd = openSync(LOCK, 'wx');
      try { writeSync(fd, JSON.stringify({ pid: process.pid, at: now() })); } catch {}
      break;
    } catch {
      try {
        if (Date.now() - statSync(LOCK).mtimeMs > 10_000 && lockOwnerGone()) {
          // Claim the reap by RENAMING: rename succeeds for exactly one waiter,
          // so two waiters can never both delete a lock a third just took.
          const claimed = `${LOCK}.${process.pid}.dead`;
          renameSync(LOCK, claimed);
          unlinkSync(claimed);
          continue;
        }
      } catch {}
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
    }
  }
  if (fd === null) {
    throw new Error(`bus lock: could not acquire ${LOCK} within ~12s — another ` +
                    `process is holding it. Retry; if it persists, delete that file.`);
  }
  try { return fn(); }
  finally {
    try { closeSync(fd); } catch {}
    try { unlinkSync(LOCK); } catch {}
  }
}

export function saveState(s) {
  s.updated_at = now();
  mkdirSync(dirname(STATE), { recursive: true });
  const tmp = `${STATE}.${process.pid}.tmp`;
  writeFileSync(tmp, JSON.stringify(s, null, 2) + '\n', 'utf8');
  renameSync(tmp, STATE);          // atomic within the volume
  try { writeFileSync(DASHBOARD, renderDashboard(s, { interactive: false }), 'utf8'); } catch {}
}

export const mutate = (fn) =>
  withLock(() => { const s = loadState(); const r = fn(s); saveState(s); return r; });

export function pushLog(s, by, text) {
  s.log.push({ at: now(), by, text });
  if (s.log.length > MAX_LOG) s.log = s.log.slice(-MAX_LOG);
}

export function pushMessage(s, from, to, re, body, asks = '') {
  const id = ++s.seq;
  s.messages.push({ id, from, to, re, body, asks, at: now(), read_by: [] });
  if (s.messages.length > MAX_MESSAGES) s.messages = s.messages.slice(-MAX_MESSAGES);
  return id;
}

// ─── dashboard ────────────────────────────────────────────────────────────
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function renderDashboard(s, { interactive = false, csrf = '', note = '', nonce = '', recos = null, alarms = null } = {}) {
  const hidden = csrf ? `<input type="hidden" name="_t" value="${esc(csrf)}">` : '';
  const boss = s.decisions.filter(d => d.status === 'boss');
  const open = s.decisions.filter(d => d.status === 'open');
  const bossUnread = s.messages.filter(m => m.from === BOSS && !m.read_by.length);

  // WHO NEEDS A POKE. Neither app can wake the other, so the last job left with
  // Youness is remembering whose turn it is. Work it out for him instead.
  const unreadFor = (n) => s.messages.filter(m => m.to === n && !m.read_by.includes(n)).length;
  const waiting = AGENTS.filter(a => unreadFor(a) > 0);
  const pokeLine = (() => {
    if (!waiting.length) {
      const busy = AGENTS.filter(a => (s.agents[a] || {}).status === 'working');
      if (busy.length) return { quiet: true, html: `${busy.join(' and ')} ${busy.length > 1 ? 'are' : 'is'} working. Nothing for you to do.` };
      return { quiet: true, html: 'Nobody is waiting on you. Send a message below to start something.' };
    }
    const who = waiting.map(a => `<b>${esc(a)}</b> (${unreadFor(a)} unread)`).join(' and ');
    return { quiet: false, html: `Tell ${who} to go. Say: <code>check the team bus and continue</code>` };
  })();

  const agentCard = (name) => {
    const a = s.agents[name] || {};
    const unread = s.messages.filter(m => m.to === name && !m.read_by.includes(name)).length;
    const claims = s.claims.filter(c => c.by === name);
    return `<div class="card">
      <div class="who"><span class="dot ${esc(a.status || 'idle')}"></span>${esc(name)}
        <span class="when">${esc(ago(a.updated_at))}</span></div>
      <div class="task">${esc(a.task || 'nothing set')}</div>
      ${a.note ? `<div class="note">${esc(a.note)}</div>` : ''}
      <div class="meta">${esc(a.status || 'idle')} · ${unread} unread ·
        ${claims.length ? claims.map(c => `<code>${esc(c.path)}</code>`).join(' ') : 'no files held'}</div>
    </div>`;
  };

  const act = (fields, label, cls = '') =>
    !interactive ? '' :
    `<form method="POST" action="/act" class="inline">${hidden}
       ${Object.entries(fields).map(([k, v]) =>
         `<input type="hidden" name="${esc(k)}" value="${esc(v)}">`).join('')}
       <button class="${cls}" type="submit">${esc(label)}</button>
     </form>`;

  const bossBlock = (d) => `<div class="boss">
      <div class="tag">Waiting on you</div>
      <div class="t">${esc(d.id)} · ${esc(d.title)}</div>
      <div class="d">${esc(d.detail)}</div>
      <div class="by">raised by ${esc(d.by)}, ${esc(ago(d.at))}</div>
      ${interactive ? `<form method="POST" action="/act" class="acts">${hidden}
          <input type="hidden" name="action" value="decide">
          <input type="hidden" name="id" value="${esc(d.id)}">
          <input type="text" name="note" placeholder="answer in your own words, or just approve…">
          <button type="submit" name="verdict" value="comment">Reply</button>
          <button type="submit" name="verdict" value="approved">Approve</button>
          <button type="submit" name="verdict" value="declined">Decline</button>
        </form>` : ''}
    </div>`;

  return `<!doctype html><html><head><meta charset="utf-8">
<title>GenZHype build room</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'${nonce ? `; script-src 'nonce-${esc(nonce)}'` : ''}; form-action 'self'; frame-ancestors 'none'">
<style>
  :root{--bg:#faf9f7;--fg:#1a1a1a;--dim:#6b6b6b;--line:#e3e0da;--card:#fff;
        --accent:#C71F12;--warn:#8a5a00;--warnbg:#fff6e0;--ok:#1a7f4b;--code:#f2efe9}
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
        --bg:#16161a;--fg:#ececec;--dim:#9a9a9a;--line:#2c2c33;--card:#1e1e24;
        --accent:#FF6A5C;--warn:#e0b050;--warnbg:#2e2515;--ok:#4ec98a;--code:#26262e}}
  :root[data-theme="dark"]{--bg:#16161a;--fg:#ececec;--dim:#9a9a9a;--line:#2c2c33;
        --card:#1e1e24;--accent:#FF6A5C;--warn:#e0b050;--warnbg:#2e2515;--ok:#4ec98a;--code:#26262e}
  *{box-sizing:border-box}
  body{margin:0;padding:26px 18px 60px;background:var(--bg);color:var(--fg);
       font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
  .wrap{max-width:900px;margin:0 auto}
  h1{font-size:20px;margin:0 0 2px;font-weight:600}
  .sub{color:var(--dim);font-size:13px;margin-bottom:24px}
  h2{font-size:11px;text-transform:uppercase;letter-spacing:.09em;color:var(--dim);
     margin:30px 0 10px;font-weight:600}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
  .who{font-weight:600;font-size:15px;display:flex;align-items:center;gap:8px}
  .who .when{margin-left:auto;font-weight:400;font-size:12px;color:var(--dim)}
  .dot{width:8px;height:8px;border-radius:50%;background:var(--dim);flex:none}
  .dot.working{background:var(--ok)} .dot.blocked{background:var(--accent)}
  .dot.done{background:var(--ok);opacity:.45}
  .task{font-size:14px;margin-top:8px}
  .note{margin-top:7px;font-size:13px;color:var(--warn)}
  .meta{margin-top:11px;padding-top:9px;border-top:1px solid var(--line);
        color:var(--dim);font-size:12px}
  code{background:var(--code);padding:1px 6px;border-radius:4px;
       font:12px ui-monospace,Consolas,monospace;word-break:break-all}
  .boss{background:var(--warnbg);border:1px solid var(--warn);border-radius:10px;
        padding:14px 16px;margin-bottom:10px}
  .boss .tag{font-size:11px;text-transform:uppercase;letter-spacing:.09em;
             color:var(--warn);font-weight:600;margin-bottom:6px}
  .boss .t{font-weight:600;margin-bottom:5px}
  .boss .d{font-size:14px;white-space:pre-wrap;line-height:1.55}
  .boss .by{color:var(--dim);font-size:12px;margin-top:8px}
  .acts{display:flex;gap:7px;margin-top:12px;flex-wrap:wrap;align-items:center}
  .inline{display:inline-flex;gap:6px;margin:0}
  .inline.grow{flex:1;min-width:230px}
  input[type=text]{flex:1;min-width:0;padding:7px 10px;border:1px solid var(--line);
    border-radius:6px;background:var(--card);color:var(--fg);font:inherit;font-size:13px}
  button{padding:7px 13px;border:1px solid var(--line);border-radius:6px;
    background:var(--card);color:var(--fg);font:inherit;font-size:13px;cursor:pointer}
  button:hover{border-color:var(--dim)}
  .say{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
  .say form{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin:0}
  .say input[type=text]{flex:1 1 100%;margin-bottom:4px}
  .row{padding:9px 0;border-bottom:1px solid var(--line);font-size:14px;
       display:flex;gap:10px;align-items:baseline}
  .row:last-child{border-bottom:0}
  .row .by{color:var(--accent);font-weight:600;min-width:56px;flex:none}
  .row .tx{flex:1} .row .t{color:var(--dim);font-size:12px;flex:none}
  .empty{color:var(--dim);font-size:14px;font-style:italic}
  .flash{background:var(--card);border:1px solid var(--ok);border-left:3px solid var(--ok);
         border-radius:8px;padding:11px 14px;margin-bottom:12px;font-size:14px}
  .poke{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--accent);
        border-radius:8px;padding:12px 15px;margin-bottom:14px;display:flex;
        align-items:center;gap:12px;flex-wrap:wrap}
  .poke .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.09em;
             color:var(--accent);font-weight:600}
  .poke .txt{font-size:15px;flex:1;min-width:200px}
  .poke .quiet{color:var(--dim);font-weight:400}
  .poke code{font-size:12px}
  .poke button{white-space:nowrap}
  .alarm{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--warn);
         border-radius:0 8px 8px 0;padding:13px 16px;margin-bottom:8px}
  .alarm.watch{border-left-color:var(--dim)}
  .alarm .t{font-weight:600;font-size:15px;margin-bottom:5px}
  .alarm .d{font-size:14px;color:var(--fg);line-height:1.55;margin-bottom:5px}
  .alarm .ev{font:12px var(--mono);color:var(--dim);word-break:break-word}
  .alarm form{display:flex;gap:7px;margin-top:10px;flex-wrap:wrap;align-items:center}
  .alarm input[type=text]{flex:1;min-width:170px}
  .allclear{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--ok,#3fb950);
            border-radius:0 8px 8px 0;padding:13px 16px;margin-bottom:10px;font-size:14px}
  .watchman{font:12px var(--mono);color:var(--dim);margin:-4px 0 10px}
  .watchman.stale{color:var(--warn)}
  .reco{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--accent);
        border-radius:0 8px 8px 0;padding:14px 16px;margin-bottom:10px}
  .reco .kind{font:500 11px var(--mono);letter-spacing:.09em;text-transform:uppercase;
              color:var(--accent);margin-bottom:5px}
  .reco .t{font-weight:600;font-size:15px;margin-bottom:6px}
  .reco .w{font-size:14px;color:var(--fg);margin-bottom:6px;line-height:1.55}
  .reco .ev{font-size:13px;color:var(--dim);margin-bottom:4px}
  .reco .rk{font-size:13px;color:var(--warn)}
  .proof{margin-top:9px;padding:9px 12px;border-radius:6px;font-size:13.5px;line-height:1.5}
  .proof b{font-family:var(--mono);font-size:11px;letter-spacing:.09em;text-transform:uppercase}
  .proof.no{background:var(--warnbg);color:var(--fg);border:1px solid var(--warn)}
  .proof.no b{color:var(--accent)}
  .proof.yes{background:var(--card);border:1px solid var(--ok)}
  .proof.yes b{color:var(--ok)}
  .proof.meh{background:var(--card);border:1px dashed var(--line);color:var(--dim)}
  .reco form{display:flex;gap:7px;margin-top:11px;flex-wrap:wrap;align-items:center}
  .reco input[type=text]{flex:1;min-width:180px}
  .vrow{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0}
  .vrow label{display:inline-flex;gap:6px;align-items:center;font-size:13px;
    border:1px solid var(--line);padding:5px 11px;border-radius:6px;cursor:pointer;
    color:var(--fg)}
  .vrow label:hover{border-color:var(--dim)}
  .vpast{font-size:13px;color:var(--dim);margin-top:10px}
  .vpast b{color:var(--accent)}
  .foot{margin-top:36px;padding-top:14px;border-top:1px solid var(--line);
        color:var(--dim);font-size:12px;line-height:1.6}
</style></head><body><div class="wrap">
  <h1>GenZHype — build room</h1>
  <div class="sub">you · claude — updated ${esc(ago(s.updated_at))}${
      interactive ? ' · live — updates when they act, and never while you are typing' : ' · read-only snapshot'}</div>

  ${note ? `<div class="flash">${esc(note)}</div>` : ''}
  ${interactive ? `<div class="poke">
      <span class="lbl">${pokeLine.quiet ? 'Status' : 'Your move'}</span>
      <span class="txt ${pokeLine.quiet ? 'quiet' : ''}">${pokeLine.html}</span>
      ${pokeLine.quiet ? '' : `<button type="button" id="copygo">Copy that line</button>`}
    </div>` : ''}
  ${boss.length ? boss.map(bossBlock).join('') : ''}
  ${bossUnread.length ? `<div class="card" style="margin-bottom:10px">
      <div class="meta" style="margin:0;padding:0;border:0">You have ${bossUnread.length}
      message(s) the agents have not picked up yet — they will see them on their next turn.</div>
    </div>` : ''}

  ${interactive && alarms ? (() => {
      const b = alarms.board || {};
      const list = b.alarms || [];
      // Law 4: the watchman's own liveness ships with its findings. A dead
      // watchman and a clean board must never look the same.
      const ran = b.last_run
        ? `the machine last checked itself at ${esc(b.last_run)} UTC`
        : 'the machine has never checked itself';
      const stale = b.stale || !b.last_run;
      const head = `<div class=\"watchman${stale ? ' stale' : ''}\">${esc(ran)}${stale ? ' - that is old, the watchman itself may be down' : ''}</div>`;
      if (alarms.error) return `<h2>What is broken</h2>${head}<div class=\"empty\">Could not reach the site: ${esc(alarms.error)}</div>`;
      // Law 5: 'nothing wrong' must not look like 'did not run'.
      if (!list.length) return `<h2>What is broken</h2>${head}<div class=\"allclear\">Nothing is broken. Every check ran and found nothing.</div>`;
      const alarmCount = list.filter(a => a.severity === 'alarm').length;
      return `<h2>What is broken (${alarmCount})</h2>${head}` + list.map(a => `<div class=\"alarm ${a.severity === 'alarm' ? '' : 'watch'}\">
          <div class=\"t\">${esc(a.title)}</div>
          ${a.detail ? `<div class=\"d\">${esc(a.detail)}</div>` : ''}
          ${a.evidence ? `<div class=\"ev\">${esc(a.evidence)}</div>` : ''}
          <form method=\"POST\" action=\"/\">
            <input type=\"hidden\" name=\"csrf\" value=\"${esc(csrf)}\">
            <input type=\"hidden\" name=\"action\" value=\"alarm\">
            <input type=\"hidden\" name=\"code\" value=\"${esc(a.code)}\">
            <input type=\"text\" name=\"note\" placeholder=\"a note, if you want one\">
            <button type=\"submit\" name=\"verdict\" value=\"ack\">I have seen it</button>
            <button type=\"submit\" name=\"verdict\" value=\"resolve\">It is fixed</button>
          </form>
        </div>`).join('');
    })() : ''}

  ${interactive && recos ? (() => {
      const list = recos.list || [];
      if (!list.length && !recos.error) return '';
      const label = { rule_change: 'Change a rule', upgrade: 'Fix a broken part', question: 'Worth testing', other: 'Proposal' };
      return `<h2>The machine proposes (${list.length})</h2>` + (recos.error
        ? `<div class="empty">Could not reach the site: ${esc(recos.error)}</div>`
        : list.map(r => `<div class="reco">
            <div class="kind">${esc(label[r.type] || label.other)} · ${esc(r.made_on || '')}</div>
            <div class="t">${esc(r.title)}</div>
            <div class="w">${esc(r.why || '')}</div>
            ${r.evidence ? `<div class="ev">Evidence: ${esc(r.evidence)}</div>` : ''}
            ${r.record_ids ? `<div class="ev">From videos: ${esc(r.record_ids)}</div>` : ''}
            ${r.risk ? `<div class="rk">Risk: ${esc(r.risk)}</div>` : ''}
            ${r.proof ? (() => {
                const v = String(r.proof.verdict || 'UNTESTED');
                const cls = v === 'CONTRADICTED' ? 'no' : (v === 'SUPPORTED' ? 'yes' : 'meh');
                const head = v === 'CONTRADICTED' ? 'Your own videos say NO'
                           : v === 'SUPPORTED' ? 'Your own videos back this'
                           : 'Cannot be tested yet';
                return `<div class="proof ${cls}"><b>${esc(head)}</b>` +
                       (r.proof.tested_on ? ` &middot; checked against ${esc(r.proof.tested_on)} posted videos` : '') +
                       `<br>${esc(r.proof.why || '')}</div>`;
              })() : ''}
            <form method="POST" action="/act">${hidden}
              <input type="hidden" name="action" value="reco">
              <input type="hidden" name="id" value="${esc(r.id)}">
              <input type="text" name="note" placeholder="your words (optional)">
              <button type="submit" name="verdict" value="approved">Approve</button>
              <button type="submit" name="verdict" value="dismissed">Decline</button>
            </form></div>`).join(''));
    })() : ''}

  <h2>Agents</h2>
  <div class="grid">${AGENTS.map(agentCard).join('')}</div>

  ${interactive ? `<h2>Say something to them</h2>
  <div class="say"><form method="POST" action="/act">${hidden}
    <input type="hidden" name="action" value="say">
    <input type="text" name="text" placeholder="e.g. the hook is too slow — make the first second hit harder" required>
    <button type="submit" name="to" value="claude">Send to Claude</button>
  </form></div>` : ''}

  ${interactive ? `<h2>Judge a video</h2>
  <div class="say"><form method="POST" action="/act">${hidden}
    <input type="hidden" name="action" value="verdict">
    <input type="text" name="video" placeholder="which video — the number (700) or the TikTok link" required>
    <div class="vrow">
      <label><input type="radio" name="verdict" value="bad" required> Disaster</label>
      <label><input type="radio" name="verdict" value="okay"> Okay</label>
      <label><input type="radio" name="verdict" value="good"> Good</label>
    </div>
    <div class="vrow">
      ${VERDICT_REASONS.map(([k, label]) =>
        `<label><input type="checkbox" name="reasons" value="${k}"> ${esc(label)}</label>`).join('')}
    </div>
    <input type="text" name="note" placeholder="why — your own words (optional but gold)">
    <button type="submit">Record verdict</button>
  </form>
  ${(() => { const vs = readVerdicts(3); return vs.length ?
     `<div class="vpast">${vs.map(v =>
        `<div><b>${esc(v.video)}</b> — ${esc(v.verdict)}${v.reasons?.length ? ' · ' + esc(v.reasons.join(', ')) : ''}${v.note ? ' · “' + esc(v.note) + '”' : ''}</div>`).join('')}</div>` : ''; })()}
  </div>` : ''}

  <h2>Open between the two of them (${open.length})</h2>
  ${open.length ? open.map(d => `<div class="row">
      <span class="by">${esc(d.id)}</span><span class="tx">${esc(d.title)}</span>
      <span class="t">${esc(ago(d.at))}</span></div>`).join('')
    : '<div class="empty">Nothing open.</div>'}

  <h2>Activity</h2>
  ${s.log.length ? s.log.slice(-30).reverse().map(e => `<div class="row">
      <span class="by">${esc(e.by === BOSS ? 'you' : e.by)}</span>
      <span class="tx">${esc(e.text)}</span>
      <span class="t">${esc(ago(e.at))}</span></div>`).join('')
    : '<div class="empty">Nothing yet.</div>'}

  <div class="foot">
    ${interactive
      ? `Live. Anything you do here reaches the agents on their next turn — you still
         tell each one to go. State: <code>${esc(STATE)}</code>`
      : `Read-only snapshot. For the version you can act from, run
         <code>_team/bus/room.cmd</code> and open localhost:7777`}
  </div>
</div>
${interactive && nonce ? `<script nonce="${esc(nonce)}">
(function () {
  var seq = ${JSON.stringify(String(s.updated_at || ''))};
  var TITLE = document.title, pending = false, blink = null;

  // Never reload out from under him mid-sentence.
  function busy() {
    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) return true;
    var ins = document.querySelectorAll('input[type=text]');
    for (var i = 0; i < ins.length; i++) if (ins[i].value.trim()) return true;
    return false;
  }

  function startBlink() {
    if (blink) return;
    var on = false;
    blink = setInterval(function () {
      on = !on;
      document.title = on ? '\\u25CF something happened' : TITLE;
    }, 1200);
  }
  function stopBlink() {
    if (blink) { clearInterval(blink); blink = null; }
    document.title = TITLE;
  }

  function refreshNow() { stopBlink(); location.replace('/'); }

  setInterval(function () {
    if (busy()) return;
    fetch('/seq', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.updated_at === seq) return;
        // Looking at it and not typing -> just refresh. Looking away -> flash
        // the tab and wait, so he can leave this open and go do something else.
        if (document.hasFocus() && !document.hidden && !busy()) refreshNow();
        else { pending = true; startBlink(); }
      })
      .catch(function () {});
  }, 5000);

  window.addEventListener('focus', function () { if (pending && !busy()) refreshNow(); });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && pending && !busy()) refreshNow();
  });

  var cg = document.getElementById('copygo');
  if (cg) cg.addEventListener('click', function () {
    var t = 'check the team bus and continue';
    var done = function () { cg.textContent = 'Copied'; setTimeout(function () { cg.textContent = 'Copy that line'; }, 1600); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(done, function () { cg.textContent = 'Copy failed'; });
    } else {
      var ta = document.createElement('textarea');
      ta.value = t; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { cg.textContent = 'Copy failed'; }
      document.body.removeChild(ta);
    }
  });
})();
</script>` : ''}
</body></html>`;
}
