/* The picture engine: footage frames or moved stills, word captions, and a list of timed graphics (placed by build.js). */
const C = window.COMP, $ = id => document.getElementById(id);
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v));
const P = (t, a, d) => clamp((t - a) / d);
const lerp = (a, b, p) => a + (b - a) * p;
const outCubic = p => 1 - Math.pow(1 - p, 3);
const outExpo = p => p >= 1 ? 1 : 1 - Math.pow(2, -10 * p);
const outBack = p => { const c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(p - 1, 3) + c1 * Math.pow(p - 1, 2); };
const LN = Object.fromEntries(C.lines.map(l => [l.id, l]));
const SH = Object.fromEntries(C.shots.map(s => [s.id, s]));
const norm = w => w.toLowerCase().replace(/[.,?!:]/g, '');
const W = (id, word, nth = 1) => { let k = 0; for (const w of LN[id].words) if (norm(w.w) === norm(word) && ++k === nth) return w.s; throw new Error('no word ' + id + ' ' + word); };
const T0 = id => SH[id].t0, T1 = id => SH[id].t1;

function put(el, o) {
  if (o.o !== undefined && o.o <= 0.001) { el.style.visibility = 'hidden'; return; }
  el.style.visibility = 'visible';
  el.style.opacity = o.o === undefined ? 1 : o.o;
  el.style.transform = `translate(${o.x || 0}px,${o.y || 0}px) rotate(${o.r || 0}deg) scale(${o.s === undefined ? 1 : o.s})`;
}
const FX = {
  slam: (t, a) => ({o: P(t, a, .05), s: 1 + 1.6 * (1 - outExpo(P(t, a, .18)))}),
  pop: (t, a) => ({o: P(t, a, .08), s: .5 + .5 * outBack(P(t, a, .26))}),
  rise: (t, a) => ({o: P(t, a, .12), y: 70 * (1 - outCubic(P(t, a, .32)))}),
  left: (t, a) => ({o: P(t, a, .1), x: -140 * (1 - outCubic(P(t, a, .3)))}),
  drop: (t, a) => ({o: P(t, a, .06), y: -820 * (1 - outCubic(P(t, a, .42))), r: -3 * (1 - outCubic(P(t, a, .42)))}),
  none: () => ({}),
};

/* ---------- timed graphics ---------- */
const ITEMS = [];
// h = html of one element, (x, y) = its centre (or its top-left corner with tl), shown from a to b
function item(h, x, y, a, b, fx = 'pop', opt = {}) { ITEMS.push({h, x, y, a, b, fx, ...opt}); }
function receipt(name, width, marks, clip) {
  const m = C.receipts[name], k = width / m.w; let h = '';
  marks.forEach(([ph], mi) => (m.boxes[ph] || []).forEach((b, bi) => { h += `<i class="hl" data-m="${mi}" data-b="${bi}" data-w="${(b[2] * k).toFixed(1)}" style="left:${(b[0] * k).toFixed(1)}px;top:${(b[1] * k).toFixed(1)}px;height:${(b[3] * k).toFixed(1)}px;width:0"></i>`; }));
  return {h: `<div class="rc" style="width:${width + 12}px;height:${Math.round((clip || m.h) * k) + 12}px"><img src="receipts/${name}.png" style="width:${width}px">${h}</div>`,
    fn: (t, el) => el.querySelectorAll('.hl').forEach(i => { i.style.width = (+i.dataset.w * outCubic(P(t, marks[+i.dataset.m][1] + .2 * +i.dataset.b, .28))).toFixed(1) + 'px'; })};
}
function buildItems() { const host = $('items');
  for (const it of ITEMS) { const w = document.createElement('div'); w.className = it.tl ? 'abs' : 'at'; w.style.left = it.x + 'px'; w.style.top = it.y + 'px'; w.innerHTML = it.h; w.style.display = 'none'; w.dataset.key = it.key || ''; host.appendChild(w); it.w = w; it.el = w.firstElementChild; } }
function drawItems(t) {
  for (const it of ITEMS) {
    const on = t >= it.a && t < it.b; it.w.style.display = on ? 'block' : 'none'; if (!on) continue;
    const o = FX[it.fx](t, it.a); o.r = (o.r || 0) + (it.r || 0);
    if (it.wob) o.y = (o.y || 0) + it.wob * Math.sin((t - it.a) * 8);
    for (const tp of (it.pulse || [])) if (t >= tp) o.s = (o.s === undefined ? 1 : o.s) * (1 + .1 * (1 - outExpo(P(t, tp, .3))));
    put(it.el, o); if (it.fn) it.fn(t, it.el);
  }
}

/* ---------- picture ---------- */
const base = $('base'), cam = $('cam'), still = $('still'), stillbg = $('stillbg'), dual = $('dual'), wA = $('winA').firstElementChild, wB = $('winB').firstElementChild;
let lastSrc = '';
async function img(el, src) { if (el.dataset.src !== src) { el.src = src; el.dataset.src = src; try { await el.decode(); } catch (e) { window.ERR = (window.ERR || 0) + 1; } } }
async function setBase(t) {
  const s = C.shots.find(s => t >= s.t0 && t < s.t1) || C.shots[C.shots.length - 1];
  const p = clamp((t - s.t0) / (s.t1 - s.t0)), film = 'FBCW'.includes(s.mode);
  base.style.visibility = film ? 'visible' : 'hidden';
  still.style.display = s.mode === 'S' ? 'block' : 'none';
  dual.style.display = s.mode === 'D' ? 'block' : 'none';
  stillbg.style.display = 'SD'.includes(s.mode) ? 'block' : 'none';
  if (film) {
    const i = Math.max(1, Math.min(s.n, Math.floor((t - s.t0) * C.fps + 1e-6) + 1)), src = `base/${s.dir}/${String(i).padStart(4, '0')}.jpg`;
    if (src !== lastSrc) { base.src = src; lastSrc = src; try { await base.decode(); } catch (e) { window.ERR = (window.ERR || 0) + 1; } }
  } else if (s.mode === 'S') {
    await img(still, s.img); await img(stillbg, s.img);
    const cx = lerp(s.k0[0], s.k1[0], p), cy = lerp(s.k0[1], s.k1[1], p), z = lerp(s.k0[2], s.k1[2], p), fy = s.fy || 960;
    still.style.transform = `translate(${(540 - cx * z).toFixed(2)}px,${(fy - cy * z).toFixed(2)}px) scale(${z.toFixed(4)})`;
  } else if (s.mode === 'D') {
    await img(stillbg, s.img);
    for (const [el, k] of [[wA, s.a], [wB, s.b]]) { await img(el, k.img || s.img); const cx = lerp(k.k0[0], k.k1[0], p), cy = lerp(k.k0[1], k.k1[1], p), z = lerp(k.k0[2], k.k1[2], p);
      el.style.transform = `translate(${(540 - cx * z).toFixed(2)}px,${(280 - cy * z).toFixed(2)}px) scale(${z.toFixed(4)})`; }
  }
  return s;
}
const CUTS = C.shots.slice(1).map(s => s.t0), IMP = C.cues.impact;
function camera(t, s) {
  const p = (t - s.t0) / (s.t1 - s.t0), punch = 'FCW'.includes(s.mode) ? .12 : s.mode === 'S' ? .05 : 0;
  let sc = 1.04 + .04 * p + punch * (1 - outExpo(P(t, s.t0, .38))), dx = 0, dy = 0;
  if (s.mode === 'D') sc = 1;
  for (const [ta, size] of IMP) { const q = (t - ta) / .3; if (q >= 0 && q < 1) { const f = Math.round((t - ta) * 30), amp = 11 * size * (s.mode === 'D' ? .5 : 1); dx += Math.sin(f * 12.9898 + 1) * amp * (1 - q); dy += Math.cos(f * 78.233 + 2) * amp * (1 - q); } }
  cam.style.transform = `translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) scale(${sc.toFixed(4)})`;
  $('dim').style.opacity = s.dim ?? (s.mode === 'B' ? .3 : .05);
  const cr = $('credit'); cr.style.display = s.credit ? 'block' : 'none'; cr.textContent = s.credit || '';
}
function flash(t) { let o = 0;
  for (const c of CUTS) if (t >= c) o = Math.max(o, .32 * (1 - P(t, c, .1)));
  for (const [a, size] of IMP) if (size >= 1.2 && a > .05 && t >= a) o = Math.max(o, .5 * (1 - P(t, a, .12)));
  $('flash').style.opacity = o.toFixed(3); }

/* ---------- captions: a few words at a time, the spoken one boxed ---------- */
const CHUNKS = [];
function buildCaps() {
  for (const ln of C.lines) {
    let idx = 0, toks = [];
    for (const w of ln.words) {
      const i = ln.text.indexOf(w.w, idx); let punct = '';
      if (i >= 0) { idx = i + w.w.length; const m = ln.text.slice(idx).match(/^[^\sA-Za-z0-9]+/); if (m) punct = m[0]; }
      toks.push({t: REP[w.w] || w.w, raw: w.w, p: punct, s: w.s, e: w.e});
    }
    for (const [seq, text] of (MERGE[ln.id] || [])) for (let i = 0; i + seq.length <= toks.length; i++)
      if (seq.every((x, j) => toks[i + j].raw === x)) { const last = toks[i + seq.length - 1]; toks.splice(i, seq.length, {t: text, raw: text, p: last.p, s: toks[i].s, e: last.e}); break; }
    let cur = [];
    const flush = () => { if (cur.length) { CHUNKS.push({line: ln.id, words: cur}); cur = []; } };
    for (const k of toks) {
      const len = cur.reduce((a, x) => a + x.t.length + 1, 0) + k.t.length;
      if (cur.length && (cur.length >= 3 || len > 17)) flush();
      cur.push(k);
      if (/[.?!:,]/.test(k.p)) flush();
    }
    flush();
  }
  CHUNKS.forEach((c, i) => { const nx = CHUNKS[i + 1], le = c.words[c.words.length - 1].e; c.s = c.words[0].s - .06; c.e = nx ? Math.min(nx.words[0].s - .06, le + .5) : le + .6; });
}
let capNow = -1;
function caps(t, s) {
  const line = $('capline'); $('caps').style.top = (s.capY || 1284) + 'px';
  const i = CHUNKS.findIndex(c => t >= c.s && t < c.e);
  if (i < 0) { line.style.visibility = 'hidden'; capNow = -1; return; }
  const c = CHUNKS[i];
  if (i !== capNow) {
    line.innerHTML = c.words.map(w => `<span>${w.t}${/[?!]/.test(w.p) ? w.p.replace(/[^?!]/g, '') : ''}</span>`).join('');
    line.style.transform = 'none';
    const w = line.scrollWidth; line.dataset.k = w > 836 ? (836 / w).toFixed(4) : 1;
    capNow = i;
  }
  line.style.visibility = 'visible';
  const k = +line.dataset.k, enter = .92 + .08 * outBack(P(t, c.s, .12));
  line.style.transform = `scale(${(k * enter).toFixed(4)})`;
  let on = 0; c.words.forEach((w, j) => { if (t >= w.s - .03) on = j; });
  [...line.children].forEach((el, j) => { el.classList.toggle('on', j === on); el.style.transform = j === on ? `scale(${(1 + .12 * (1 - outCubic(P(t, c.words[j].s - .03, .14)))).toFixed(3)}) rotate(-1.5deg)` : 'none'; });
}

/* ---------- grain ---------- */
(function () { const cv = document.createElement('canvas'); cv.width = 270; cv.height = 480; const x = cv.getContext('2d'), im = x.createImageData(270, 480); let s = 1234567;
  for (let i = 0; i < im.data.length; i += 4) { s = (s * 1664525 + 1013904223) >>> 0; const v = (s >>> 24); im.data[i] = im.data[i + 1] = im.data[i + 2] = v; im.data[i + 3] = 255; }
  x.putImageData(im, 0, 0); $('grain').style.backgroundImage = `url(${cv.toDataURL()})`; })();
function grain(t) { const f = Math.round(t * 30); $('grain').style.backgroundPosition = `${(f * 37) % 61}px ${(f * 53) % 59}px`; }

function start() {
  buildItems(); if (typeof fitItems === 'function') fitItems(); buildCaps();
  // what is on screen right now and where (the layout check reads this)
  window.rects = () => { const out = []; for (const it of ITEMS) { if (it.w.style.display === 'none' || it.el.style.visibility === 'hidden') continue; const r = it.el.getBoundingClientRect(); out.push({key: it.key || '', x: r.x, y: r.y, w: r.width, h: r.height}); }
    const c = $('capline'); if (c.style.visibility !== 'hidden' && c.children.length) { const r = c.getBoundingClientRect(); out.push({key: 'caption', x: r.x, y: r.y, w: r.width, h: r.height}); } return out; };
  window.spans = () => ITEMS.map(it => [it.a, it.b]);
  window.render = async function (t) { const s = await setBase(t); camera(t, s); drawItems(t); caps(t, s); flash(t); grain(t); return s.id; };
  window.READY = document.fonts.ready.then(() => Promise.all(['900 40px Fraunces', 'italic 900 40px Fraunces', '800 40px Spartan', '700 20px Mono'].map(f => document.fonts.load(f)))).then(() => true);
}
