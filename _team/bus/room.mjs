#!/usr/bin/env node
/**
 * GenZHype build room — the page Youness acts from.
 *
 * A tiny HTTP server that renders the live bus and accepts his actions:
 * approving or declining a decision, answering one in his own words, and
 * messaging either agent (or both). Those land in the same state file the two
 * agents read through the MCP bus, so all three of them work in one place.
 *
 * SAFETY (this is the only part of the system that opens a listener):
 *   - binds 127.0.0.1 ONLY — never reachable from the network
 *   - Host header must be loopback (blocks DNS-rebinding)
 *   - Origin, when sent, must be this exact server
 *   - every form carries a per-run CSRF token; POSTs without it are refused
 *   - request bodies are capped; only two actions exist; no shell, no eval
 *   - GET / is the only page; there is no file serving of any kind
 *
 * Run:  node room.mjs        (or double-click room.cmd)
 */

import { createServer } from 'node:http';
import { randomBytes } from 'node:crypto';
import { loadState, mutate, pushLog, pushMessage, renderDashboard,
         assertNoSecrets, AGENTS, BOSS, STATE, now } from './state.mjs';

const PORT  = Number(process.env.TEAM_PORT || 7777);
const TOKEN = randomBytes(18).toString('hex');   // new every run
const MAX_BODY = 16 * 1024;

const isLoopbackHost = (h = '') => {
  const host = h.split(':')[0].toLowerCase();
  return host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || host === '::1';
};

function page(note = '', nonce = '') {
  return renderDashboard(loadState(), { interactive: true, csrf: TOKEN, note, nonce });
}

function send(res, code, body, type = 'text/html; charset=utf-8', nonce = '') {
  res.writeHead(code, {
    'content-type': type,
    'cache-control': 'no-store',
    'x-content-type-options': 'nosniff',
    // Nothing external ever loads, and the page is never framed. The only
    // script that may run is the one carrying this response's nonce.
    'content-security-policy':
      "default-src 'none'; style-src 'unsafe-inline'; " +
      (nonce ? `script-src 'nonce-${nonce}'; connect-src 'self'; ` : '') +
      "form-action 'self'; frame-ancestors 'none'",
    'referrer-policy': 'no-referrer',
  });
  res.end(body);
}

function applyAction(f) {
  const action = f.get('action');

  if (action === 'say') {
    const text = (f.get('text') || '').trim();
    const to   = f.get('to');
    if (!text) return 'Nothing to send — the box was empty.';
    assertNoSecrets(text, 'message');
    const targets = to === 'both' ? AGENTS : (AGENTS.includes(to) ? [to] : []);
    if (!targets.length) return 'Unknown recipient.';
    mutate((s) => {
      for (const t of targets) pushMessage(s, BOSS, t, 'From Youness', text);
      pushLog(s, BOSS, `told ${to === 'both' ? 'both' : to}: ${text.slice(0, 120)}`);
    });
    return `Sent to ${to === 'both' ? 'Claude and GLM' : to}. They will see it on their next turn.`;
  }

  if (action === 'decide') {
    const id = f.get('id'), verdict = f.get('verdict');
    const note = (f.get('note') || '').trim();
    assertNoSecrets(note, 'note');
    return mutate((s) => {
      const d = s.decisions.find(x => x.id === id);
      if (!d) return `No decision ${id}.`;
      if (verdict === 'comment') {
        if (!note) return 'Type an answer first, then press Reply.';
        d.detail += `\n\n— Youness (${now().slice(0, 16).replace('T', ' ')}): ${note}`;
        for (const t of AGENTS) pushMessage(s, BOSS, t, `Re: ${d.id} ${d.title}`, note);
        pushLog(s, BOSS, `answered ${d.id}: ${note.slice(0, 120)}`);
        return `Answer recorded on ${d.id} and sent to both agents.`;
      }
      const word = verdict === 'approved' ? 'approved' : 'declined';
      d.status = 'settled';
      d.resolution = `${word} by Youness${note ? ` — ${note}` : ''}`;
      d.settled_at = now();
      for (const t of AGENTS) {
        pushMessage(s, BOSS, t, `${d.id} ${word}`, `${d.title}\n\n${d.resolution}`);
      }
      pushLog(s, BOSS, `${word} ${d.id}: ${d.title}`);
      return `${d.id} ${word}. Both agents will see it on their next turn.`;
    });
  }
  return 'Unknown action.';
}

createServer((req, res) => {
 try {
  if (!isLoopbackHost(req.headers.host)) return send(res, 421, 'Loopback only.', 'text/plain');

  if (req.method === 'GET' && req.url === '/seq') {
    // The page polls this instead of hard-refreshing, so it can decide for
    // itself whether now is a good moment to reload.
    const s = loadState();
    return send(res, 200, JSON.stringify({ updated_at: s.updated_at, seq: s.seq }),
                'application/json; charset=utf-8');
  }

  if (req.method === 'GET' && (req.url === '/' || req.url.startsWith('/?'))) {
    let note = '';
    try { note = new URL(req.url, 'http://localhost').searchParams.get('n') || ''; } catch {}
    const nonce = randomBytes(16).toString('base64');
    return send(res, 200, page(note.slice(0, 400), nonce), 'text/html; charset=utf-8', nonce);
  }

  if (req.method === 'POST' && req.url === '/act') {
    const origin = req.headers.origin;
    if (origin && !/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin)) {
      return send(res, 403, 'Cross-origin POST refused.', 'text/plain');
    }
    let body = '', tooBig = false;
    req.on('data', (c) => {
      body += c;
      if (body.length > MAX_BODY) { tooBig = true; req.destroy(); }
    });
    req.on('end', () => {
      if (tooBig) return send(res, 413, 'Too large.', 'text/plain');
      let note;
      try {
        const f = new URLSearchParams(body);
        if (f.get('_t') !== TOKEN) return send(res, 403, 'Stale page — reload localhost:' + PORT, 'text/plain');
        note = applyAction(f);
      } catch (e) {
        note = `Refused: ${e.message}`;
      }
      // PRG: redirect so a refresh never re-submits
      res.writeHead(303, { location: '/?n=' + encodeURIComponent(note).slice(0, 400) });
      res.end();
    });
    return;
  }

  send(res, 404, 'Not found.', 'text/plain');
 } catch (e) {
  // A bad state file must never take the room down — Youness would just see
  // the window vanish with no idea why.
  console.error('request failed:', e && e.message);
  try { send(res, 500, 'The build room hit an error: ' + (e && e.message) +
                       '\nThe server is still running — reload the page.', 'text/plain'); } catch {}
 }
}).on('error', (e) => {
  // A failed bind is fatal and must SAY so — the catch-all below would
  // otherwise leave a dead window that looks like it is running.
  if (e.code === 'EADDRINUSE') {
    console.error(`\n  Port ${PORT} is already in use — the build room is probably\n` +
                  `  already open in another window. Use that one, or close it first.\n` +
                  `  (To run a second copy: set TEAM_PORT to a different number.)\n`);
  } else {
    console.error('\n  Could not start the build room:', e.message, '\n');
  }
  process.exit(1);
}).listen(PORT, '127.0.0.1', () => {
  console.log('');
  console.log('  GenZHype build room  ->  http://localhost:' + PORT);
  console.log('  state: ' + STATE);
  console.log('');
  console.log('  Leave this window open while you work. Ctrl+C to stop.');
  console.log('');
});

// Last-resort net: log and keep serving rather than dying silently.
process.on('uncaughtException', (e) => console.error('uncaught:', e && e.stack || e));
process.on('unhandledRejection', (e) => console.error('unhandled rejection:', e));
