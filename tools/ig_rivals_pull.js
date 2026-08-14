#!/usr/bin/env node
/* GenZHype | r116 — pull rival Instagram accounts from YOUR OWN machine.
 *
 * WHY THIS RUNS ON YOUR LAPTOP AND NOT THE SERVER. Instagram rival data has
 * exactly two doors. Meta's business_discovery API needs a Facebook developer
 * account, and ours is blocked (developers.facebook.com/account_status/error
 * — the same block that deleted our old app). The other door is a real
 * browser that is already logged in, on a normal home connection. Our server
 * and the GitHub runners are datacenter IPs, which these platforms flag on
 * sight — we proved that with TikTok: a real visible browser and a valid
 * token still returned six empty profiles.
 *
 * So: OpenCLI drives the Chrome you are already using, reads public accounts
 * the way a person would, and this script ships what it saw to the site.
 * Nothing is stored on your machine and nothing logs in on your behalf.
 *
 * USE
 *   node ig_rivals_pull.js                 pull the default rival list
 *   node ig_rivals_pull.js handle1 handle2  pull specific accounts
 *
 * NEEDS
 *   - Chrome open, logged into instagram.com, OpenCLI extension enabled
 *   - env GENZHYPE_TOKEN=<the ingest token>
 *
 * ON FIELD NAMES. OpenCLI does not contract its output shape, so this script
 * does NOT try to interpret it — it passes each post through untouched and
 * lets the server read whichever key names actually came back (the server
 * also keeps the raw payload). That way a rename upstream costs a one-line
 * server fix instead of a silent column of zeros.
 */
'use strict';

const { execFileSync, execSync } = require('child_process');
const https = require('https');

const ENDPOINT = process.env.GENZHYPE_ENDPOINT
  || 'https://genzhype.com/api/ig_rivals_ingest.php';
// .trim() is not cosmetic. On Windows, `set VAR=value && node ...` captures
// the space BEFORE the && into the value, so the token arrives with a
// trailing space and the server rejects it as "forbidden" — which reads like
// a wrong token when it is the right one.
const TOKEN = (process.env.GENZHYPE_TOKEN || '').trim();
// Asked for 24 last run and OpenCLI returned 12 for every account, so 12
// looks like its page size rather than our ceiling. Asking for more is
// free; the real depth comes from running this weekly, since the table
// dedups on (rival, post) and each run adds whatever is new.
const LIMIT = process.env.IG_LIMIT || '36';

// The rival bench. Nothing here is discovered — it is a chosen list, and the
// quality of every rule downstream is capped by how well it matches our lane.
//
// PROVEN (returned real posts and real counts on the 2026-08-13 pull):
//   dexerto, defnoodles, popcrave
// ADDED from published follower counts in the same lane — drama, celebrity
// and internet-culture news that posts dated stories the way we do:
//   theshaderoom, deuxmoi, commentsbycelebs, hollywoodunlocked, popbase,
//   dailyloud, nojumper, thepopnote, pubity, ladbible
// DROPPED: thespillsesh (returned 3 posts, all zero likes — noise), and
//   thehollywoodfix (refused on every attempt while others in the same run
//   succeeded, so it is that handle and not the login).
//
// A handle that fails costs one skipped account and nothing else, so a wrong
// guess here is cheap; a MISSING rival is what actually costs us.
const DEFAULT_RIVALS = [
  'dexerto', 'defnoodles', 'popcrave',
  'theshaderoom', 'deuxmoi', 'commentsbycelebs', 'hollywoodunlocked',
  'popbase', 'dailyloud', 'nojumper', 'thepopnote', 'pubity', 'ladbible',
];

const rivals = process.argv.slice(2).length ? process.argv.slice(2) : DEFAULT_RIVALS;

function log(...a) { console.log(...a); }

const IS_WIN = process.platform === 'win32';

/** Handles are the only thing that reaches the command line, and on Windows
 *  it goes through cmd.exe (see below), so restrict them to what an Instagram
 *  handle can actually contain rather than trusting the caller. */
function safeHandle(h) {
  return String(h).trim().replace(/^@/, '').replace(/[^A-Za-z0-9._]/g, '');
}

/** Ask OpenCLI for one account. Returns an array of posts, or null.
 *
 *  WINDOWS NOTE. opencli installs as opencli.cmd, and since Node's fix for
 *  CVE-2024-27980 a .cmd cannot be spawned without a shell — it fails with
 *  EINVAL, which reads like "opencli is broken" when it is installed fine.
 *  So Windows runs it through the shell; every other platform does not. */
function pull(rawHandle) {
  const handle = safeHandle(rawHandle);
  if (!handle) { log(`  ${rawHandle}: not a usable handle`); return null; }
  const opts = { encoding: 'utf8', timeout: 120000,
                 maxBuffer: 32 * 1024 * 1024, windowsHide: true };
  let out;
  try {
    out = IS_WIN
      // One command string, because passing an args array WITH shell:true is
      // deprecated in Node (the args are concatenated, not escaped). The
      // handle is already restricted to [A-Za-z0-9._] above, so there is
      // nothing left in it for cmd.exe to interpret.
      ? execSync(`opencli.cmd instagram user ${handle} --limit ${Number(LIMIT) || 24} -f json`, opts)
      : execFileSync('opencli',
          ['instagram', 'user', handle, '--limit', String(LIMIT), '-f', 'json'], opts);
  } catch (e) {
    const msg = String(e.message).split('\n')[0].slice(0, 140);
    log(`  ${handle}: opencli failed — ${msg}`);
    // stdout/stderr carry the real reason (not logged in, extension asleep,
    // unknown subcommand); without them the next step is pure guesswork.
    const extra = [e.stdout, e.stderr].map((b) => (b ? String(b).trim() : '')).filter(Boolean);
    if (extra.length) { log(`     said: ${extra.join(' | ').slice(0, 300)}`); }
    return null;
  }

  let data;
  try {
    data = JSON.parse(out);
  } catch {
    log(`  ${handle}: output was not JSON (${out.slice(0, 60).replace(/\s+/g, ' ')}…)`);
    return null;
  }

  // The posts array hides under a different key depending on the command;
  // find the first array of objects rather than hardcoding a guess.
  if (Array.isArray(data)) return data;
  for (const k of ['posts', 'items', 'media', 'edges', 'data', 'results']) {
    if (Array.isArray(data[k])) return data[k];
  }
  for (const v of Object.values(data)) {
    if (Array.isArray(v) && v.length && typeof v[0] === 'object') return v;
  }
  log(`  ${handle}: no post array found in output (keys: ${Object.keys(data).join(', ')})`);
  return null;
}

function send(payload) {
  return new Promise((resolve) => {
    const body = JSON.stringify(payload);
    const u = new URL(ENDPOINT);
    const req = https.request({
      hostname: u.hostname, path: u.pathname + u.search, method: 'POST',
      headers: { 'Content-Type': 'application/json',
                 'Content-Length': Buffer.byteLength(body) },
      timeout: 60000,
    }, (res) => {
      let d = '';
      res.on('data', (c) => { d += c; });
      res.on('end', () => { try { resolve(JSON.parse(d)); } catch { resolve({ raw: d.slice(0, 200) }); } });
    });
    req.on('error', (e) => resolve({ error: e.message }));
    req.on('timeout', () => { req.destroy(); resolve({ error: 'timeout' }); });
    req.write(body);
    req.end();
  });
}

(async () => {
  if (!TOKEN) {
    log('GENZHYPE_TOKEN is not set — nothing would be accepted. Set it and re-run.');
    process.exit(1);
  }
  log(`pulling ${rivals.length} rival(s): ${rivals.join(', ')}`);
  let last = null;
  let shownFields = false;
  let first = true;
  for (const handle of rivals) {
    // Wait BETWEEN accounts, always — previously the pause only happened
    // after a successful send, so a failure made the next request immediate.
    // The 5th account was the one Instagram kept refusing.
    if (!first) { await new Promise((r) => setTimeout(r, 6000)); }
    first = false;
    const posts = pull(handle);
    if (!posts || !posts.length) { log(`  ${handle}: 0 posts`); continue; }
    const res = await send({ token: TOKEN, rival: handle, posts });
    if (res.error) { log(`  ${handle}: ${posts.length} posts, send FAILED — ${res.error}`); continue; }
    log(`  ${handle}: ${posts.length} posts -> stored ${res.stored ?? '?'}`
        + (res.skipped ? ` (skipped ${res.skipped})` : ''));
    if (res.fields && res.fields.length && !shownFields) {
      // Print once: this is what tells us whether the server read the right
      // columns or is quietly storing zeros.
      log(`     fields seen: ${res.fields.join(', ')}`);
      shownFields = true;
    }
    last = res;
  }
  if (last) {
    log(`\ntotal stored: ${last.total_posts} post(s) from ${last.rivals} rival(s)`);
    log(last.rule_written
      ? 'the engine wrote its Instagram rules from this.'
      : `no rule yet — ${last.need}`);
  }
})();
