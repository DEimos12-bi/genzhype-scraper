/* GenZHype | r117 — DEEP Instagram rival pull, run from the browser console.
 *
 * WHY NOT OPENCLI. Its `instagram user` command is 41 lines: one call to
 * /api/v1/feed/user/<name>/username/?count=N with no pagination, so Instagram
 * answers with a single page (12 posts) no matter what --limit says. It also
 * cuts every caption at 100 characters, which silently capped the caption
 * measurements in our first study. Both limits are in the tool, not in
 * Instagram — the same endpoint pages through max_id.
 *
 * SO THIS RUNS WHERE OPENCLI RUNS: inside your logged-in instagram.com tab,
 * using your own session. It just does the job properly — follows the
 * pagination cursor, keeps full captions, and also collects view counts,
 * permalinks and follower counts that OpenCLI never returned.
 *
 * HOW TO USE
 *   1. open instagram.com in Chrome (logged in)
 *   2. F12 -> Console
 *   3. paste this whole file, press Enter
 *
 * It reports progress per account and uploads as it goes, so a rate-limit
 * halfway through still keeps everything collected up to that point.
 */
(async () => {
  const TOKEN = 'a4355f1060578d8fd7f563485e2f72735d33ed9a';
  const ENDPOINT = 'https://genzhype.com/api/ig_rivals_ingest.php';
  const PER_ACCOUNT = 200;
  const RIVALS = [
    'dexerto', 'defnoodles', 'popcrave',
    'theshaderoom', 'deuxmoi', 'commentsbycelebs', 'hollywoodunlocked',
    'popbase', 'dailyloud', 'nojumper', 'thepopnote', 'pubity', 'ladbible',
  ];

  const APP_ID = '936619743392459';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function page(username, maxId) {
    let url = 'https://www.instagram.com/api/v1/feed/user/'
            + encodeURIComponent(username) + '/username/?count=33';
    if (maxId) { url += '&max_id=' + encodeURIComponent(maxId); }
    const r = await fetch(url, {
      credentials: 'include',
      headers: { 'X-IG-App-ID': APP_ID },
    });
    if (!r.ok) { throw new Error('HTTP ' + r.status); }
    return r.json();
  }

  async function collect(username) {
    const out = [];
    let maxId = null;
    // Hard stop on rounds as well as on posts: if Instagram ever stops
    // advancing the cursor, this must not spin forever inside your browser.
    for (let round = 0; round < 12 && out.length < PER_ACCOUNT; round++) {
      let d;
      try {
        d = await page(username, maxId);
      } catch (e) {
        console.log(`   ${username}: stopped after ${out.length} — ${e.message}`);
        break;
      }
      const items = d?.items || [];
      if (!items.length) { break; }
      for (const p of items) {
        out.push({
          // Instagram's own id: stable across pulls, so the server stops
          // having to fingerprint posts by their content.
          id: String(p.id || p.pk || ''),
          shortcode: p.code || '',
          permalink: p.code ? 'https://www.instagram.com/p/' + p.code + '/' : '',
          caption: (p.caption && p.caption.text) ? p.caption.text : '',   // FULL, not 100 chars
          likes: p.like_count ?? 0,
          comments: p.comment_count ?? 0,
          views: p.play_count ?? p.view_count ?? p.ig_play_count ?? 0,
          media_type: p.media_type === 1 ? 'photo' : (p.media_type === 2 ? 'video' : 'carousel'),
          taken_at: p.taken_at || 0,
        });
        if (out.length >= PER_ACCOUNT) { break; }
      }
      if (!d.more_available || !d.next_max_id) { break; }
      maxId = d.next_max_id;
      await sleep(1500);            // pace the paging, not just the accounts
    }
    return out;
  }

  async function upload(rival, posts) {
    const r = await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, rival, posts }),
    });
    return r.json();
  }

  console.log(`deep pull: ${RIVALS.length} accounts, up to ${PER_ACCOUNT} posts each`);
  let last = null;
  for (const handle of RIVALS) {
    let posts = [];
    try {
      posts = await collect(handle);
    } catch (e) {
      console.log(`  ${handle}: failed — ${e.message}`);
    }
    if (!posts.length) { console.log(`  ${handle}: 0 posts`); await sleep(4000); continue; }
    try {
      const res = await upload(handle, posts);
      last = res;
      console.log(`  ${handle}: ${posts.length} posts -> stored ${res.stored}`);
    } catch (e) {
      console.log(`  ${handle}: ${posts.length} posts, upload failed — ${e.message}`);
    }
    await sleep(4000);              // be a guest between accounts
  }
  if (last) {
    console.log(`\ntotal in the database: ${last.total_posts} posts from ${last.rivals} rivals`);
    console.log(last.rule_written ? 'rules rewritten from this data.' : `no rule yet — ${last.need}`);
  }
})();
