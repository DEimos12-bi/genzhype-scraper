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

const { execFileSync } = require('child_process');
const https = require('https');

const ENDPOINT = process.env.GENZHYPE_ENDPOINT
  || 'https://genzhype.com/api/ig_rivals_ingest.php';
const TOKEN = process.env.GENZHYPE_TOKEN || '';
const LIMIT = process.env.IG_LIMIT || '24';

// Drama/commentary accounts in our exact lane. Override by passing handles.
const DEFAULT_RIVALS = [
  'thespillsesh', 'defnoodles', 'popcrave', 'dexerto', 'thehollywoodfix',
];

const rivals = process.argv.slice(2).length ? process.argv.slice(2) : DEFAULT_RIVALS;

function log(...a) { console.log(...a); }

/** Ask OpenCLI for one account. Returns an array of posts, or null. */
function pull(handle) {
  let out;
  try {
    out = execFileSync(
      process.platform === 'win32' ? 'opencli.cmd' : 'opencli',
      ['instagram', 'user', handle, '--limit', String(LIMIT), '-f', 'json'],
      { encoding: 'utf8', timeout: 120000, maxBuffer: 32 * 1024 * 1024 }
    );
  } catch (e) {
    log(`  ${handle}: opencli failed — ${String(e.message).split('\n')[0].slice(0, 120)}`);
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
  for (const handle of rivals) {
    const posts = pull(handle);
    if (!posts || !posts.length) { log(`  ${handle}: 0 posts`); continue; }
    const res = await send({ token: TOKEN, rival: handle, posts });
    if (res.error) { log(`  ${handle}: ${posts.length} posts, send FAILED — ${res.error}`); continue; }
    log(`  ${handle}: ${posts.length} posts -> stored ${res.stored ?? '?'}`
        + (res.skipped ? ` (skipped ${res.skipped})` : ''));
    last = res;
    await new Promise((r) => setTimeout(r, 3000));   // be a guest, not a crawler
  }
  if (last) {
    log(`\ntotal stored: ${last.total_posts} post(s) from ${last.rivals} rival(s)`);
    log(last.rule_written
      ? 'the engine wrote its Instagram rules from this.'
      : `no rule yet — ${last.need}`);
  }
})();
