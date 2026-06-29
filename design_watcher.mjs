// GenZHype | DESIGN WATCHER (external, GitHub Actions). Runs `dembrandt` on each rival + ours,
// extracts the full design-token bundle, and POSTs it to /api/design_ingest.php.
import { execSync } from 'node:child_process';

const BASE  = (process.env.DESIGN_BASE || 'https://genzhype.com').replace(/\/$/, '');
const TOKEN = process.env.INGEST_TOKEN || '';
const RIVALS = (process.env.COMPETITORS || 'dexerto.com,distractify.com,knowyourmeme.com,thethings.com,popcrave.com,dailydot.com,thetab.com,screenrant.com')
  .split(',').map(s => s.trim()).filter(Boolean);
const OURS = (process.env.OUR_URLS || 'https://genzhype.com/,https://genzhype.com/drama/')
  .split(',').map(s => s.trim()).filter(Boolean);

function extract(url) {
  try {
    const out = execSync(`dembrandt ${JSON.stringify(url)} --json-only`,
      { encoding: 'utf8', timeout: 150000, maxBuffer: 64 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] });
    const s = out.indexOf('{'), e = out.lastIndexOf('}');
    if (s < 0 || e < 0) return null;
    return JSON.parse(out.slice(s, e + 1));
  } catch (err) {
    console.error('  ! fail', url, String(err && err.message || err).slice(0, 140));
    return null;
  }
}

const items = [];
for (const d of RIVALS) {
  console.error('rival:', d);
  const t = extract('https://' + d);
  if (t) items.push({ domain: d, is_ours: 0, url: 'https://' + d, tokens: t });
}
for (const u of OURS) {
  console.error('ours :', u);
  const t = extract(u);
  if (t) items.push({ domain: 'genzhype.com', is_ours: 1, url: u, tokens: t });
}

if (!items.length) { console.error('nothing extracted this run'); process.exit(1); }

const res = await fetch(`${BASE}/api/design_ingest.php`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ token: TOKEN, items }),
});
console.error('delivered:', res.status, (await res.text()).slice(0, 400));
