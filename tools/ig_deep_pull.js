/* GenZHype | r118 — DEEP Instagram rival pull (collect here, upload from Node).
 *
 * WHY IT SAVES A FILE INSTEAD OF UPLOADING. Measured, not assumed: Instagram
 * serves a Content-Security-Policy whose connect-src allows only its own
 * hosts, so ANY fetch from inside their page to genzhype.com is refused
 * outright — "Fetch API cannot load ... Refused to connect". That blocks the
 * upload and even the loading of this file, which is why it has to be pasted
 * whole rather than fetched.
 *
 * What CSP does not block is a request to Instagram's own API (same origin)
 * or a local file download. So this collects in the page and hands you a
 * file; ig_rivals_pull.js --file ships it from outside the browser.
 *
 * WHY IT EXISTS AT ALL. OpenCLI's instagram-user command makes ONE
 * unpaginated request (so --limit can never exceed a page: 12 posts) and cuts
 * every caption at 100 characters. This pages through max_id and keeps full
 * captions, plus views, permalinks and Instagram's real post ids.
 *
 * HOW TO USE
 *   1. instagram.com open in Chrome, logged in
 *   2. F12 -> Sources -> Snippets -> New snippet
 *   3. paste this WHOLE file, Ctrl+Enter
 *   4. it downloads ig_rivals.json — then, in cmd:
 *        node ig_rivals_pull.js --file %USERPROFILE%\Downloads\ig_rivals.json
 */
(async () => {
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
    // Bounded by rounds as well as posts: if the cursor ever stops advancing
    // this must not spin forever inside your browser.
    for (let round = 0; round < 12 && out.length < PER_ACCOUNT; round++) {
      let d;
      try {
        d = await page(username, maxId);
      } catch (e) {
        console.log(`   ${username}: stopped at ${out.length} — ${e.message}`);
        break;
      }
      const items = (d && d.items) || [];
      if (!items.length) { break; }
      for (const p of items) {
        out.push({
          id: String(p.id || p.pk || ''),
          shortcode: p.code || '',
          permalink: p.code ? 'https://www.instagram.com/p/' + p.code + '/' : '',
          caption: (p.caption && p.caption.text) ? p.caption.text : '',
          likes: p.like_count || 0,
          comments: p.comment_count || 0,
          views: p.play_count || p.view_count || p.ig_play_count || 0,
          media_type: p.media_type === 1 ? 'photo' : (p.media_type === 2 ? 'video' : 'carousel'),
          taken_at: p.taken_at || 0,
        });
        if (out.length >= PER_ACCOUNT) { break; }
      }
      if (!d.more_available || !d.next_max_id) { break; }
      maxId = d.next_max_id;
      await sleep(1500);
    }
    return out;
  }

  const result = { pulled_at: new Date().toISOString(), rivals: {} };
  let total = 0;
  console.log(`deep pull: ${RIVALS.length} accounts, up to ${PER_ACCOUNT} posts each`);
  for (const handle of RIVALS) {
    let posts = [];
    try { posts = await collect(handle); } catch (e) { console.log(`  ${handle}: ${e.message}`); }
    if (posts.length) { result.rivals[handle] = posts; total += posts.length; }
    console.log(`  ${handle}: ${posts.length} posts   (running total ${total})`);
    await sleep(4000);
  }

  // Hand the data over as a file. A download is not a network connection, so
  // the policy that blocks uploading does not apply to it.
  const blob = new Blob([JSON.stringify(result)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'ig_rivals.json';
  document.body.appendChild(a);
  a.click();
  a.remove();

  console.log(`\nDONE — ${total} posts from ${Object.keys(result.rivals).length} accounts.`);
  console.log('Saved as ig_rivals.json in your Downloads. Now run, in cmd:');
  console.log('  node ig_rivals_pull.js --file %USERPROFILE%\\Downloads\\ig_rivals.json');
})();
