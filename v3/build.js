/* The plan's graphics, placed by rule: every kind of graphic has its own place on the screen, so two never share one,
   and everything stays inside the part of the screen TikTok's own buttons leave free (y 150 to 1250, x 40 to 940).
   Captions sit at y 1284. Loaded after engine.js and before start(). */
const PLAN = C.plan, REP = {}, MERGE = {};
const esc = s => String(s == null ? '' : s).replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
const lineOf = id => LN[id];
const spanOf = id => { const sh = C.shots.filter(s => s.line === id); return [sh[0].t0, sh[sh.length - 1].t1]; };
const at = (id, word, lead = .05) => { const w0 = norm(String(word || '')).split(' ')[0]; if (w0) for (const w of lineOf(id).words) if (norm(w.w) === w0) return w.s - lead; return lineOf(id).s; };
const TONES = ['', 'red', '', 'yellow'];

/* captions: spoken numbers shown as digits ("three hundred thousand euros" -> "€300,000") */
for (const pl of PLAN.lines) for (const c of (pl.caps || [])) {
  const want = c.say.split(/\s+/).map(norm), ws = lineOf(pl.id).words;
  for (let i = 0; i + want.length <= ws.length; i++) if (want.every((x, j) => norm(ws[i + j].w) === x)) {
    if (want.length === 1) REP[ws[i].w] = c.show; else (MERGE[pl.id] = MERGE[pl.id] || []).push([ws.slice(i, i + want.length).map(w => w.w), c.show]);
    break; }
}

PLAN.lines.forEach((pl, li) => {
  const [a, b] = spanOf(pl.id), ov = (pl.overlays || []).filter(o => !o.drop), r = li % 2 ? 2 : -2;
  const key = j => ({key: 'L' + li + '.' + j});
  const card = ov.find(o => ['rows', 'quote', 'receipt', 'blocks'].includes(o.k));
  const light = ov.filter(o => ['stamp', 'chip', 'sub'].includes(o.k));
  const stamp = light.find(o => o.k === 'stamp'), chip = light.find(o => o.k === 'chip'), sub = light.find(o => o.k === 'sub');
  const idx = o => (pl.overlays || []).indexOf(o);
  const tags = ov.filter(o => o.k === 'tag').slice(0, 1);
  tags.forEach(o => item(`<div class="tag"><b>${esc(o.name).toUpperCase()}</b><i>${esc(o.role)}</i></div>`, 50, 160, Math.max(a + .1, at(pl.id, o.on, .1)), b, 'left', {tl: 1, fit: 900, ...key(idx(o))}));
  const top = tags.length ? 330 : 0;                                   // a name plate takes the top-left corner

  if (pl.id === 'vote' && PLAN.vote) { const v = PLAN.vote, wa = at(pl.id, v.a.word), wb = at(pl.id, v.b.word);
    const last = word => { const w0 = norm(word).split(' ')[0]; const hits = lineOf(pl.id).words.filter(w => norm(w.w) === w0); return hits.length > 1 ? hits[hits.length - 1].s - .04 : null; };
    item(`<div class="big ring" style="font-size:96px">${esc(v.question).toUpperCase()}</div>`, 520, 330, a + .15, b, 'slam', {r: -2, fit: 940, key: 'vote.q'});
    item(`<div class="ob cool"><span>${esc(v.a.sub || 'ONE SIDE').toUpperCase()}</span><b>${esc(v.a.word).toUpperCase()}</b></div>`, 60, 440, wa - .02, b, 'slam', {tl: 1, r: -1.5, big: 1, pulse: last(v.a.word) ? [last(v.a.word)] : [], key: 'vote.a'});
    item(`<div class="ob red"><span>${esc(v.b.sub || 'THE OTHER').toUpperCase()}</span><b>${esc(v.b.word).toUpperCase()}</b></div>`, 60, 730, wb - .02, b, 'slam', {tl: 1, r: 1.5, big: 1, pulse: last(v.b.word) ? [last(v.b.word)] : [], key: 'vote.b'});
    item('<div class="go">COMMENT ONE WORD 👇</div>', 500, 1110, at(pl.id, 'Comment'), b, 'pop', {r: -2, wob: 9, key: 'vote.go'});
    return; }
  if (pl.id === 'site') { const end = C.end + 1, S = C.site;
    if (S && S.desktop && S.tablet && S.phone) {                         // the real page on a PC, a tablet and a phone
      const dev = (cls, name, W, H, pad) => `<div class="dev ${cls}" style="width:${W}px;height:${H}px"><div class="scr" style="left:${pad}px;top:${pad}px;width:${W - 2 * pad}px;height:${H - 2 * pad}px"><img src="site/${name}.jpg" style="width:${W - 2 * pad}px" alt=""></div></div>`;
      const roll = (name, W, H, pad, far) => (t, el) => { const m = S[name], full = m.h * (W - 2 * pad) / m.w, p = clamp((t - a - .5) / Math.max(1, C.end - a - .7));
        el.querySelector('img').style.transform = `translateY(${(-Math.max(0, Math.min(full - (H - 2 * pad), far)) * p * p * (3 - 2 * p)).toFixed(1)}px)`; };
      item(`<div class="url" style="font-size:96px">${esc(C.brand.site)}</div>`, 505, 262, at(pl.id, 'Gen', .04), end, 'slam', {r: -2, fit: 900, key: 'site.url'});
      item(`<div class="pcwrap">${dev('pc', 'desktop', 820, 500, 14)}<i class="neck"></i><i class="foot"></i></div>`, 110, 372, a + .1, end, 'rise', {tl: 1, fn: roll('desktop', 820, 500, 14, 520), key: 'site.pc'});
      item(dev('tab', 'tablet', 330, 450, 14), 70, 770, at(pl.id, 'timeline'), end, 'pop', {tl: 1, r: -3, fn: roll('tablet', 330, 450, 14, 420), key: 'site.tab'});
      item(dev('ph', 'phone', 204, 420, 10), 716, 800, at(pl.id, 'receipt'), end, 'pop', {tl: 1, r: 3, fn: roll('phone', 204, 420, 10, 620), key: 'site.ph'});
      item('<div class="bio" style="font-size:44px">LINK IN BIO →</div>', 558, 1150, at(pl.id, 'Link'), end, 'pop', {r: -2, wob: 7, key: 'site.bio'});
      return; }
    item('<div class="tick"><u>✓</u>THE FULL TIMELINE</div>', 60, 400, at(pl.id, 'full'), end, 'pop', {tl: 1, r: -3, key: 'site.1'});
    item('<div class="tick"><u>✓</u>EVERY RECEIPT</div>', 380, 540, at(pl.id, 'every'), end, 'pop', {tl: 1, r: 3, key: 'site.2'});
    item(`<div class="url">${esc(C.brand.site)}</div>`, 505, 800, at(pl.id, 'Gen', .04), end, 'slam', {r: -2, fit: 940, key: 'site.url'});
    item('<div class="bio">LINK IN BIO →</div>', 520, 1010, at(pl.id, 'Link'), end, 'pop', {r: 3, wob: 8, key: 'site.bio'});
    return; }

  if (!card) {                                                          // footage with one or two big words
    const ys = stamp && chip && sub ? [400, 600, 800] : stamp && (chip || sub) ? [chip ? 420 : 0, 610, sub ? 810 : 0] : [420, 620, 620];
    const y0 = top ? 140 : 0;
    if (chip && !stamp && !sub) item(`<div class="sub" style="font-size:68px">${esc(chip.t)}</div>`, 520, 470 + y0, at(pl.id, chip.on), b, 'pop', {r: -r, fit: 900, ...key(idx(chip))});
    else if (chip) item(`<div class="chip" style="font-size:34px">${esc(chip.t)}</div>`, 520, (stamp ? ys[0] : 440) + y0, at(pl.id, chip.on), b, 'pop', {r: -r, fit: 900, ...key(idx(chip))});
    if (stamp) item(`<div class="stamp${li % 3 === 2 ? ' paper' : ''}" style="font-size:${stamp.t.length <= 6 ? 210 : stamp.t.length <= 9 ? 170 : 132}px">${esc(stamp.t)}</div>`, 520, ys[1] + y0, at(pl.id, stamp.on, .04), b, 'slam', {r: r * 1.5, fit: 930, ...key(idx(stamp))});
    if (sub) item(`<div class="sub">${esc(sub.t)}</div>`, 520, (stamp ? ys[2] : 640) + y0, at(pl.id, sub.on), b, 'pop', {r, fit: 900, ...key(idx(sub))});
    return; }

  const rcm = card.k === 'receipt' ? C.receipts[card.post] : null;
  const tall = (chip ? 78 : 0) + (card.k === 'receipt' ? (rcm ? Math.round(Math.min(rcm.clip || rcm.h, 330) * 800 / rcm.w) + 38 + (card.translate ? 64 : 0) : 0) : card.k === 'quote' ? 500 : card.k === 'rows' ? card.rows.length * 120 + 20 : 580) + (stamp ? 200 : 0) + (sub ? 110 : 0);
  let y = Math.max(210, top, Math.round(720 - tall / 2));                // a card line: the group is centred between the top and the captions
  if (chip) { item(`<div class="chip red">${esc(chip.t)}</div>`, 500, y + 22, at(pl.id, chip.on), b, 'pop', {fit: 900, ...key(idx(chip))}); y += 78; }
  if (card.k === 'receipt') { const name = card.post, m = C.receipts[name];
    if (m) { const W = 800, clip = Math.min(m.clip || m.h, 330), H = Math.round(clip * W / m.w) + 12, t0 = a + .05;
      const rc = receipt(name, W, card.mark ? [[card.mark, Math.max(t0 + .5, at(pl.id, card.on, 0))]] : [], clip);
      item(rc.h, 500, y + H / 2, t0, b, 'drop', {fn: rc.fn, r: -1, ...key(idx(card))}); y += H + 26;
      if (card.translate) { item(`<div class="chip dark" style="font-size:23px">“${esc(card.translate).toUpperCase().slice(0, 62)}”</div>`, 500, y + 20, Math.max(t0 + .7, at(pl.id, card.on, 0) + .3), b, 'pop', {fit: 900, key: 'L' + li + '.tr'}); y += 64; } } }
  else if (card.k === 'quote') {
    item(`<div class="q"><s>${esc(card.label || 'IN THEIR WORDS').toUpperCase()}</s><p>“${esc(card.t)}”</p><em>— ${esc(card.who).toUpperCase()}</em></div>`, 500, y + 240, a + .15, b, 'rise', {r: -1.5, qfit: 1, ...key(idx(card))}); y += 500; }
  else if (card.k === 'rows') {
    card.rows.forEach((row, k) => item(`<div class="row ${row.tone === 'red' ? 'red' : TONES[k % 4]}" style="font-size:66px">${esc(row.t)}</div>`, 50, y + 8 + k * 120, k ? at(pl.id, row.on, .04) : Math.min(at(pl.id, row.on, .04), a + .4), b, 'slam', {tl: 1, r: k % 2 ? 1 : -1, fit: 900, key: 'L' + li + '.r' + k}));
    y += card.rows.length * 120 + 20; }
  else if (card.k === 'blocks') {
    card.items.forEach((x, k) => item(`<div class="ob ${k ? 'yellow' : ''}"><span>${esc(x.label)}</span><b>${esc(x.big)}</b></div>`, 60, y + k * 290, k ? at(pl.id, x.on, .04) : Math.min(at(pl.id, x.on, .04), a + .5), b, 'slam', {tl: 1, r: k ? 1.5 : -1.5, big: 1, key: 'L' + li + '.b' + k}));
    y += 580; }
  if (stamp && y + 190 <= 1250) { item(`<div class="stamp" style="font-size:${stamp.t.length <= 8 ? 128 : 96}px">${esc(stamp.t)}</div>`, 520, y + 100, at(pl.id, stamp.on, .04), b, 'slam', {r: r * 1.5, fit: 900, ...key(idx(stamp))}); y += 200; }
  if (sub && y + 110 <= 1250) item(`<div class="sub" style="font-size:60px">${esc(sub.t)}</div>`, 520, y + 55, at(pl.id, sub.on), b, 'pop', {r, fit: 900, ...key(idx(sub))});
});

/* text that is too wide for its place is made smaller, never cut */
function fitItems() {
  for (const it of ITEMS) {
    it.w.style.display = 'block';
    if (it.fit && it.el.offsetWidth > it.fit) { const fs = parseFloat(getComputedStyle(it.el).fontSize); it.el.style.fontSize = (fs * it.fit / it.el.offsetWidth).toFixed(1) + 'px'; }
    if (it.big) { const bEl = it.el.querySelector('b'); if (bEl && bEl.scrollWidth > 800) bEl.style.fontSize = (150 * 800 / bEl.scrollWidth).toFixed(1) + 'px'; }
    if (it.qfit) { const p = it.el.querySelector('p'); let fs = 66; while (it.el.offsetHeight > 500 && fs > 40) { fs -= 4; p.style.fontSize = fs + 'px'; } }
    it.w.style.display = 'none';
  }
}
