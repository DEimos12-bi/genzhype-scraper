/* GenZHype v2 ENGINE: one generic composition driven by the plan (comp.js = window.COMP: shots, lines with word
   timings, and the director's plan). The owner's hand-made comp.html files, generalised: the same brand chrome, the
   same caption engine, the same camera; the scenes come from the plan's overlays (a component + the word it lands on)
   and the format's chrome (breakdown = sections rail, vote = ballot + exhibits). Special moments are a registry. */
const C = window.COMP, PL = C.plan, $ = id => document.getElementById(id);
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v));
const P = (t, a, d) => clamp((t - a) / d);
const outCubic = p => 1 - Math.pow(1 - p, 3);
const outExpo = p => p >= 1 ? 1 : 1 - Math.pow(2, -10 * p);
const outBack = p => { const c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(p - 1, 3) + c1 * Math.pow(p - 1, 2); };
const inOut = p => p < .5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2;
const inCubic = p => p * p * p;
const LN = Object.fromEntries(C.lines.map(l => [l.id, l]));
const SH = Object.fromEntries(C.shots.map(s => [s.id, s]));
const norm = w => (w || '').toLowerCase().replace(/[.,?!:;"“”]/g, '');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
/* the time a word of a line is spoken; a word the voice did not keep falls back to the line start */
function W(id, word, nth = 1) { const ln = LN[id]; if (!ln) return 0; let k = 0; const want = norm(word).split(' ')[0];
  for (const w of ln.words) if (norm(w.w) === want && ++k === nth) return w.s;
  for (const w of ln.words) if (want && norm(w.w).startsWith(want.slice(0, 5))) return w.s;
  return ln.s; }
const T0 = id => SH[id] ? SH[id].t0 : 0, T1 = id => SH[id] ? SH[id].t1 : C.end;
function put(el, o) {
  if (o.o !== undefined && o.o <= 0.001) { el.style.visibility = 'hidden'; return; }
  el.style.visibility = 'visible';
  el.style.opacity = o.o === undefined ? 1 : o.o;
  el.style.transform = `translate(${o.x || 0}px,${o.y || 0}px) rotate(${o.r || 0}deg) scale(${o.s === undefined ? 1 : o.s})`;
}
const slam = (t, a, d = .18) => ({o: P(t, a, .05), s: 1 + 1.6 * (1 - outExpo(P(t, a, d)))});
const pop = (t, a, d = .26) => ({o: P(t, a, .08), s: .5 + .5 * outBack(P(t, a, d))});
const rise = (t, a, d = .3, dist = 60) => ({o: P(t, a, .12), y: dist * (1 - outCubic(P(t, a, d)))});
const slide = (t, a, d = .34, from = -140) => ({o: P(t, a, .1), x: from * (1 - outCubic(P(t, a, d)))});

/* ---------- picture ---------- */
const base = $('base'), cam = $('cam');
let lastSrc = '';
function shotAt(t) { return C.shots.find(s => t >= s.t0 && t < s.t1) || C.shots[C.shots.length - 1]; }
async function setBase(t, s) {
  if (s.mode === 'N') { base.style.visibility = 'hidden'; return; }
  const i = Math.max(1, Math.min(s.n, Math.floor((t - s.t0) * C.fps + 1e-6) + 1));
  const src = `base/${s.dir}/${String(i).padStart(4, '0')}.jpg`;
  if (src !== lastSrc) { base.src = src; lastSrc = src; try { await base.decode(); } catch (e) { window.ERR = (window.ERR || 0) + 1; } }
  base.style.visibility = 'visible';
}
/* every overlay that slams gives the camera a shake; every cut a flash */
const HITS = [], FLASH = [];
for (const s of C.shots) { if (s.t0 > 0) FLASH.push([s.t0, .55]); }
function camera(t, s) {
  const p = (t - s.t0) / Math.max(.01, s.t1 - s.t0);
  let sc = 1.03 + .05 * p + (s.id === C.shots[0].id ? .16 : .08) * (1 - outExpo(P(t, s.t0, .45))), dx = 0, dy = 0;
  if (s.mode === 'P') sc = 1.06 + .12 * p;                       // a photo drifts in slowly
  for (const [ta, amp] of HITS) { const q = (t - ta) / .32; if (q >= 0 && q < 1) { const f = Math.round((t - ta) * 30); dx += Math.sin(f * 12.9898 + 1) * amp * (1 - q); dy += Math.cos(f * 78.233 + 2) * amp * (1 - q); } }
  cam.style.transform = `translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) scale(${sc.toFixed(4)})`;
  cam.style.filter = s.mode === 'S' ? 'contrast(1.12) saturate(.85)' : 'none';
  const heavy = (OV[s.id] || []).some(o => ['receipt', 'post', 'compare', 'quote', 'options', 'exhibit', 'status'].includes(o.comp));
  $('dim').style.opacity = s.mode === 'N' ? 0 : (s.mode === 'B' ? .3 : (heavy ? .34 : .04));
  $('shade').style.display = s.mode === 'N' ? 'none' : 'block';
  const card = $('card'); card.style.display = s.kind === 'card' ? 'block' : 'none';
  card.className = (PL.card_dark ? 'dark' : '');
}
function flash(t) { let o = 0; for (const [a, k] of FLASH) if (t >= a) o = Math.max(o, k * (1 - P(t, a, .14))); $('flash').style.opacity = o.toFixed(3); }

/* ---------- the plan's overlays: built once, animated per frame ---------- */
const OV = {};   // shot id -> overlays with their DOM
const layer = $('layer');
function el(tag, cls, html) { const e = document.createElement(tag); if (cls) e.className = cls; if (html !== undefined) e.innerHTML = html; return e; }
function wrapAt(x, y, inner) { const w = el('div', 'at'); w.style.left = x + 'px'; w.style.top = y + 'px'; w.appendChild(inner); return w; }
function fitFont(e, maxW, maxPx, minPx) { let px = maxPx; e.style.fontSize = px + 'px'; while (px > minPx && e.scrollWidth > maxW) { px -= 4; e.style.fontSize = px + 'px'; } }
function build(line, o, idx) {
  const sid = line.id, ln = LN[sid] || {}, idxL = PL.lines.findIndex(l => l.id === sid), nxt = PL.lines[idxL + 1];
  const hostShot = SH[sid] || {t0: ln.s || 0, t1: nxt && LN[nxt.id] ? LN[nxt.id].s : C.end};   // a card line: its voice window
  const on = o.on ? W(sid, o.on) - .06 : hostShot.t0 + .05;
  const off = o.off ? W(sid, o.off) : hostShot.t1;
  const r = {comp: o.comp, on: Math.max(0, on), off, nodes: [], anim: 'pop', data: o};
  const slot = idx % 3;   // where in the picture stacked overlays go: 0 = upper, 1 = middle, 2 = lower
  switch (o.comp) {
    case 'stamp': { const e = el('div', 'stamp' + (o.tone ? ' ' + o.tone : ''), esc(o.text)); fitFont(e, 980, 170, 70); const w = wrapAt(540, [520, 840, 700][slot], e); layer.appendChild(w); r.nodes.push(e); r.anim = 'slam'; r.rot = -6 + 3 * (idx % 3); HITS.push([r.on, 18]); FLASH.push([r.on, .7]); break; }
    case 'kicker': { if (PL.kicker && norm(o.text) === norm(PL.kicker)) return null; const e = el('div', 'kick', esc(o.text)); const w = el('div', 'abs'); w.style.left = '60px'; w.style.top = (420 + 70 * slot) + 'px'; w.appendChild(e); layer.appendChild(w); r.nodes.push(w); r.anim = 'slide'; break; }
    case 'chip': { const e = el('div', 'chip dark', esc(o.text)); e.style.fontSize = '32px'; const w = wrapAt(540, [440, 640, 1120][slot], e); layer.appendChild(w); r.nodes.push(e); break; }
    case 'plate': { const same = (OV[sid] || []).filter(x => x.comp === 'plate'); if (same.some(x => norm(x.data.name) === norm(o.name))) return null; const e = el('div', 'plate', `<div class="pk">${esc(o.role || '')}</div><div class="pn">${esc(o.name)}</div><div class="tags">${o.tag ? `<span class="red">${esc(o.tag)}</span>` : ''}</div>`);
      e.style.top = (968 - 300 * same.length) + 'px'; layer.appendChild(e); fitFont(e.querySelector('.pn'), 940, 128, 60); r.nodes.push(e); r.anim = 'plate'; break; }
    case 'note': { if ((line.overlays || []).some(x => x.comp === 'receipt')) return null; const big = (line.overlays || []).some(x => ['quote', 'post', 'compare', 'options'].includes(x.comp)); const nPl = (line.overlays || []).filter(x => x.comp === 'plate').length; const e = el('div', 'note', `<u>${esc(o.label || 'SOURCE')}</u>${esc(o.text)}`); e.style.top = (big ? 1230 : (nPl > 1 ? [400, 510, 400][slot] : [430, 560, 690][slot])) + 'px'; layer.appendChild(e); r.nodes.push(e); r.anim = 'slide'; break; }
    case 'quote': { const e = el('div', 'qc', `<s>${esc(o.who ? ('“' + (o.who.toUpperCase()) + '”').replace(/[“”]/g, '') : 'QUOTE')}</s><p>“${esc(o.text)}”</p><em>${esc(o.source || o.who || '')}${o.date ? ' · ' + esc(o.date) : ''}</em>`);
      e.style.top = '700px'; layer.appendChild(e); fitFont(e.querySelector('p'), 880, 92, 44); e.style.top = (960 - e.offsetHeight / 2) + 'px'; r.nodes.push(e); r.anim = 'rise'; break; }
    case 'status': { const e = el('div', 'st' + (o.tone ? ' ' + o.tone : ''), (o.small ? `<small>${esc(o.small)}</small>` : '') + esc(o.text)); e.style.top = [430, 640, 850][slot] + 'px'; layer.appendChild(e); fitFont(e, 960, 84, 44); r.nodes.push(e); r.anim = 'slam'; r.rot = [-3, 2, -2][slot]; HITS.push([r.on, 9]); break; }
    case 'counter': { const e = el('div', 'counter', `<span class="n">0</span><small>${esc(o.label || '')}</small>`); const w = wrapAt(540, 900, e); layer.appendChild(w); r.nodes.push(e); r.anim = 'counter'; r.from = +o.from || 0; r.to = +o.to || 0; r.prefix = o.prefix || ''; r.suffix = o.suffix || ''; break; }
    case 'exhibit': { const e = el('div', 'ex', `<s>EXHIBIT</s><i>${esc(o.letter)}</i><b>${esc(o.name)}</b>`); const w = wrapAt(540, 800, e); layer.appendChild(w); r.nodes.push(w); r.anim = 'exhibit'; HITS.push([r.on + .1, 16]); break; }
    case 'compare': { const e = el('div', 'cmp'); (o.rows || []).slice(0, 4).forEach((row, i) => { const d = el('div', 'row', `<b>${esc(row.name)}</b><i>${esc(row.sub || '')}</i><span class="${/still|works|ok|yes|allowed/i.test(row.state || '') ? 'ok' : ''}">${esc(row.state || '')}</span>`); d.dataset.i = i; e.appendChild(d); });
      layer.appendChild(e); r.nodes.push(e); r.anim = 'rows'; break; }
    case 'options': { const items = (o.items || PL.options || []).slice(0, 3); const box = el('div'); items.forEach((it, i) => { const d = el('div', 'vb ' + ['a', 'b', 'c'][i], `<u>${esc(it.letter || 'ABC'[i])}</u><b>${esc(it.name)}</b><span>${esc(it.sub || '')}</span>`); d.style.top = (400 + 226 * i) + 'px'; d.dataset.i = i; box.appendChild(d); fitFont(d.querySelector('b'), 760, 84, 40); });
      const go = el('div', 'go', esc(o.comment || PL.ending.comment || 'COMMENT ONE LETTER 👇')); const gw = wrapAt(540, 400 + 226 * items.length + 90, go); gw.dataset.go = 1; box.appendChild(gw); layer.appendChild(box); r.nodes.push(box); r.anim = 'options'; break; }
    case 'post': { const po = (PL.posts_material || [])[+String(o.asset || '').replace(/\D/g, '') || 0] || {}; const e = el('div', 'post', `<b>${esc(po.name || o.name || '')}</b><i>${esc((po.handle || o.handle || '') + ' · X' + (po.date ? ' · ' + po.date : ''))}</i><p>${esc(o.text || po.text || '')}</p>${po.likes ? `<span class="n">${Number(po.likes).toLocaleString('en-US')} LIKES</span>` : ''}`);
      layer.appendChild(e); r.nodes.push(e); r.anim = 'rise'; break; }
    case 'receipt': { const a = PL.assets[o.asset] || {}; const line = o.line || PL.proof.line || ''; const src = PL.proof.source || a.credit || ''; const e = el('div', 'rc');
      e.innerHTML = `<div class="head"><span>RECEIPT · ${esc((src || '').toUpperCase().slice(0, 40))}</span><b>${esc((PL.proof.date || '').toUpperCase() || 'SOURCE')}</b></div>` + (a.file ? `<div class="shot"><img src="assets/${esc(a.file)}" alt=""></div>` : '') + `<div class="line"><mark></mark></div><div class="foot"><span>${esc((a.url || PL.proof.url || '').replace(/^https?:\/\//, '').slice(0, 60))}</span><span>SCREENSHOT · UNEDITED</span></div><div class="ostamp">${esc(o.stamp || 'OFFICIAL')}</div>`;
      layer.appendChild(e); r.nodes.push(e); r.anim = 'receipt'; r.words = line.split(' '); r.mark = e.querySelector('mark'); r.wordsAt = [];
      // the highlighted line is typed word by word along the spoken words of the line that follows the proof
      const spoken = LN[sid].words; r.wordsAt = r.words.map((w, i) => { const k = spoken.find(sw => norm(sw.w) === norm(w)); return k ? k.s : r.on + .12 + i * .14; });
      const rects = a.rects ? Object.values(a.rects).flat() : []; const shot = e.querySelector('.shot');
      if (shot && rects.length && a.w) { rects.forEach(([x, y, w, h]) => { const m = el('div', 'mk', '<i></i>'); const k = 930 / a.w; m.style.left = (x * k) + 'px'; m.style.top = (y * k) + 'px'; m.style.width = (w * k) + 'px'; m.style.height = (h * k) + 'px'; shot.appendChild(m); r.marks = r.marks || []; r.marks.push(m); });
        const ys = rects.map(r2 => r2[1] * (930 / a.w)); const cy = Math.max(0, Math.min(...ys) - 120); shot.scrollTop = 0; shot.dataset.cy = cy; }
      break; }
    case 'headline': { const e = el('div', 'hl', esc(o.text)); layer.appendChild(e); fitFont(e, 960, 96, 60); const nPl = (line.overlays || []).filter(x => x.comp === 'plate').length; e.style.top = (nPl ? Math.max(380, 930 - 300 * nPl - e.offsetHeight) : 960 - e.offsetHeight / 2 - 80) + 'px'; r.nodes.push(e); r.anim = 'rise'; break; }
    default: { const e = el('div', 'chip dark', esc(o.text || o.comp)); layer.appendChild(wrapAt(540, 640, e)); r.nodes.push(layer.lastChild); }
  }
  for (const n of r.nodes) n.style.visibility = 'hidden';
  (OV[sid] = OV[sid] || []).push(r);
  return r;
}
const BIG = ['receipt', 'quote', 'post', 'compare', 'options', 'counter', 'exhibit', 'status', 'note', 'stamp'];
for (const line of PL.lines) { const ov = (line.overlays || []).slice(); if ((line.visual || {}).asset === 'card' && !ov.some(o => BIG.includes(o.comp))) ov.push({comp: 'headline', text: line.text}); ov.forEach((o, i) => { try { build(line, o, i); } catch (e) { window.ERR = (window.ERR || 0) + 1; console.error('overlay', line.id, o.comp, e); } }); }

function animate(t) {
  for (const sid in OV) for (const r of OV[sid]) {
    const on = t >= r.on && t < r.off;
    for (const n of r.nodes) { if (!on) { n.style.visibility = 'hidden'; continue; }
      switch (r.anim) {
        case 'slam': put(n, {...slam(t, r.on, .2), r: r.rot || -5}); break;
        case 'slide': put(n, slide(t, r.on)); break;
        case 'rise': put(n, rise(t, r.on, .34, 90)); break;
        case 'plate': { n.style.visibility = 'visible'; put(n.querySelector('.pk'), pop(t, r.on)); put(n.querySelector('.pn'), rise(t, r.on + .05, .32, 70)); const tg = n.querySelector('.tags span'); if (tg) put(tg, pop(t, r.on + .3)); break; }
        case 'counter': { const p = inOut(P(t, r.on, 1.1)); const v = Math.round(r.from + (r.to - r.from) * p); n.querySelector('.n').textContent = r.prefix + v.toLocaleString('en-US') + r.suffix; put(n, {...pop(t, r.on, .3), r: -2}); break; }
        case 'exhibit': { const a = r.on + .05, out = inCubic(P(t, r.off - .3, .28)); const s = (1 + 1.5 * (1 - outExpo(P(t, a, .2)))) * (1 - .84 * out); n.style.visibility = t >= a ? 'visible' : 'hidden'; n.style.opacity = 1 - .5 * out; n.style.transform = `translate(-50%,-50%) translate(0,${(-595 * out).toFixed(1)}px) rotate(${(-4 * (1 - out)).toFixed(2)}deg) scale(${s.toFixed(4)})`; break; }
        case 'rows': { n.style.visibility = 'visible'; [...n.children].forEach((d, i) => put(d, slide(t, r.on + .22 * i, .34, -160))); break; }
        case 'options': { n.style.visibility = 'visible'; const go = n.querySelector('[data-go]'); const goAt = r.data.go_on ? W(sid, r.data.go_on) - .05 : r.on + .9;
          [...n.children].forEach((d, i) => { if (d.dataset.go) { put(d, {o: P(t, goAt, .08), y: 10 * Math.sin((t - goAt) * 8), s: .6 + .4 * outBack(P(t, goAt, .25)), r: -2}); return; }
            const a = r.on + .25 * i, pulse = t >= goAt ? 1 + .035 * Math.max(0, Math.sin((t - goAt) * 8 - i * 1.2)) : 1; const o = slam(t, a, .2); put(d, {o: o.o, s: o.s * pulse, r: [-1.5, 1, -1][i]}); }); break; }
        case 'receipt': { const p = outCubic(P(t, r.on + .02, .5)); n.style.visibility = 'visible'; n.style.opacity = 1; const sc = 1 + .05 * P(t, r.on + .6, 3);
          n.style.transform = `translateY(${((1 - p) * 1650).toFixed(1)}px) rotate(${(-4 * (1 - p)).toFixed(2)}deg) scale(${sc.toFixed(4)})`;
          let k = 0; r.wordsAt.forEach((at, i) => { if (t >= at - .03) k = i + 1; }); r.mark.textContent = r.words.slice(0, k).join(' ');
          const shot = n.querySelector('.shot'); if (shot) shot.scrollTop = (+shot.dataset.cy || 0) * outCubic(P(t, r.on + .6, .8));
          (r.marks || []).forEach(m => { const q = P(t, r.on + 1.0, 1.2); m.style.visibility = q > 0 ? 'visible' : 'hidden'; m.firstChild.style.width = (100 * q).toFixed(1) + '%'; });
          const st = n.querySelector('.ostamp'); if (st) put(st, {...slam(t, r.on + 2.1, .22), r: -9}); break; }
        default: put(n, pop(t, r.on));
      }
    }
  }
}

/* ---------- chrome: brand, kicker, credit, the format's rail ---------- */
const FMT = PL.format;
const SECTIONS = (PL.sections || []).slice(0, 5), OPTIONS = (PL.options || []).slice(0, 3);
if (FMT === 'breakdown') { const rail = $('rail'); SECTIONS.forEach(s => rail.appendChild(el('div', '', esc(s)))); $('ballot').style.display = 'none'; }
else { const b = $('ballot'); OPTIONS.forEach((o, i) => b.appendChild(el('div', '', `<u>${esc(o.letter || 'ABC'[i])}</u>${esc(o.name)}`))); $('rail').style.display = 'none'; }
const SECTION_AT = [];   // when each section starts: the first shot whose line carries that section index
PL.lines.forEach(l => { const s = +(l.section || 0); if (SECTION_AT[s] === undefined && SH[l.id]) SECTION_AT[s] = SH[l.id].t0; });
const EXHIBIT_AT = [];   // when each exhibit was shown (vote): its ballot entry lights up after it
for (const sid in OV) for (const r of OV[sid]) if (r.comp === 'exhibit') EXHIBIT_AT.push(r.off - .08);
EXHIBIT_AT.sort((a, b) => a - b);
function chrome(t, s) {
  const paper = s.mode === 'N' && s.kind === 'site', early = t < (SH[PL.lines[1] ? PL.lines[1].id : PL.lines[0].id] || {t0: 2}).t0;
  const k = $('kicker'); k.style.display = early && PL.kicker ? 'block' : 'none'; k.firstChild.textContent = PL.kicker || '';
  if (FMT === 'breakdown') { const rail = $('rail'); rail.style.display = (paper || early) ? 'none' : 'flex';
    [...rail.children].forEach((d, i) => { const a = SECTION_AT[i] ?? 1e9, b = SECTION_AT[i + 1] ?? 1e9; d.classList.toggle('on', t >= a && t < b); d.classList.toggle('done', t >= b); d.style.transform = `scale(${(t >= a && t < a + .35 ? 1 + .12 * (1 - outExpo(P(t, a, .35))) : 1).toFixed(3)})`; }); }
  else { const b = $('ballot'); b.style.display = (paper || early) ? 'none' : 'flex';
    [...b.children].forEach((d, i) => { const on = EXHIBIT_AT[i] !== undefined && t >= EXHIBIT_AT[i]; d.classList.toggle('log', on); d.style.transform = `scale(${(on ? 1 + .25 * (1 - outExpo(P(t, EXHIBIT_AT[i], .35))) : 1).toFixed(3)})`; }); }
  $('brand').style.display = paper ? 'none' : 'block';
  const cr = $('credit'); cr.style.display = (paper || !s.credit || s.mode === 'B' || s.kind === 'card') ? 'none' : 'block'; cr.textContent = s.credit || '';
  special(t, s, paper);
}

/* ---------- special moments (the director picks one; registry) ---------- */
const SPEC = PL.special || {}, SPP = SPEC.params || {};
function clockParts(m) { m = ((Math.round(m) % 1440) + 1440) % 1440; const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, '0'); return [(h % 12 || 12) + ':' + mm, h < 12 ? 'AM' : 'PM']; }
function special(t, s, paper) {
  const clock = $('clock');
  if (SPEC.id === 'rewind_clock' && SPP.from_min !== undefined && SPP.to_min !== undefined) {
    // the clock shows the later time first, runs back to the earlier one over the line named in params.rewind_line
    const rl = SH[SPP.rewind_line] || C.shots[1] || C.shots[0]; const a = rl.t0, b = rl.t0 + Math.min(1.2, (rl.t1 - rl.t0) * .6);
    const m = t < a ? SPP.from_min : SPP.from_min + (SPP.to_min - SPP.from_min) * inOut(P(t, a, b - a)); const [hm, ap] = clockParts(m);
    clock.style.display = paper ? 'none' : 'block'; clock.innerHTML = `${hm} ${ap}<small> ${esc(SPP.label || '')}</small>`;
    if (!$('bigclock')) { const e = el('div', '', ''); e.id = 'bigclock'; layer.appendChild(wrapAt(520, 1085, e)); }
    const big = $('bigclock'), show = t < b + .8; big.parentNode.style.visibility = show ? 'visible' : 'hidden'; big.innerHTML = `${hm}<small>${ap}</small>`;
    put(big, {s: (t >= a && t < b ? 1.06 : 1) * (1 + .5 * (1 - outExpo(P(t, 0, .3)))), r: -2});
    return;
  }
  clock.style.display = 'none';
  if (SPEC.id === 'meter') { const m = $('meter'); m.style.display = paper ? 'none' : 'block'; m.querySelector('.l').textContent = SPP.left || 'YES'; m.querySelector('.r').textContent = SPP.right || 'NO';
    const keys = (SPP.keys || []).map(k => [W(k.line, k.on) - .05, +k.value]); let v = .5; keys.forEach(([a, to], i) => { if (t >= a) { const from = i ? keys[i - 1][1] : .5; v = from + (to - from) * outBack(P(t, a, .45)); } });
    const x = clamp(v, .04, .96); $('mt-f').style.width = (x * 100).toFixed(2) + '%'; $('mt-k').style.left = (186 + 588 * x).toFixed(1) + 'px'; $('rail').style.display = 'none'; $('ballot').style.display = 'none'; }
  if (SPEC.id === 'drip_wipe') { const host = $('wipe'); if (!host.children.length) for (let i = 0; i < 9; i++) { const e = el('i'); e.style.left = (i * 122 - 9) + 'px'; e.style.width = '136px'; host.appendChild(e); }
    const cut = C.shots.map(sh => sh.t0).find(a => t >= a - .3 && t < a + .34);
    [...host.children].forEach((e, i) => { if (cut === undefined) { e.style.height = '0px'; return; } const q = clamp((t - (cut - .3) - i * .02) / .3); const h = q < .5 ? outCubic(q * 2) * 2100 : (1 - inCubic((q - .5) * 2)) * 2100; e.style.height = Math.max(0, h).toFixed(0) + 'px'; }); }
}

/* ---------- captions (the owner's engine: 3 words a chunk, the key word in the red box) ---------- */
const MERGE = PL.caption_merge || {};
const CHUNKS = [];
for (const ln of C.lines) {
  let idx = 0, toks = [];
  for (const w of ln.words) {
    const i = ln.text.indexOf(w.w, idx); let punct = '';
    if (i >= 0) { idx = i + w.w.length; const m = ln.text.slice(idx).match(/^[^\sA-Za-z0-9]+/); if (m) punct = m[0]; }
    toks.push({t: w.w, p: punct, s: w.s, e: w.e});
  }
  for (const [seq, text] of (MERGE[ln.id] || [])) for (let i = 0; i + seq.length <= toks.length; i++)
    if (seq.every((x, j) => toks[i + j] && toks[i + j].t === x)) { const last = toks[i + seq.length - 1]; toks.splice(i, seq.length, {t: text, p: last.p, s: toks[i].s, e: last.e}); break; }
  let cur = [];
  const flush = () => { if (cur.length) { CHUNKS.push({line: ln.id, words: cur}); cur = []; } };
  for (const k of toks) { const len = cur.reduce((a, x) => a + x.t.length + 1, 0) + k.t.length; if (cur.length && (cur.length >= 3 || len > 17)) flush(); cur.push(k); if (/[.?!:,]/.test(k.p)) flush(); }
  flush();
}
CHUNKS.forEach((c, i) => { const nx = CHUNKS[i + 1], le = c.words[c.words.length - 1].e; c.s = c.words[0].s - .06; c.e = nx ? Math.min(nx.words[0].s - .06, le + .5) : le + .6; });
const MUTE = [];   // the exhibits and the vote boxes speak for themselves
for (const sid in OV) for (const r of OV[sid]) if (r.comp === 'exhibit' || r.comp === 'options') MUTE.push([r.on, r.off]);
let capNow = -1;
function caps(t, s) {
  const line = $('capline');
  let i = CHUNKS.findIndex(c => t >= c.s && t < c.e);
  if (MUTE.some(([a, b]) => t >= a && t < b)) i = -1;
  if (i < 0) { line.style.visibility = 'hidden'; capNow = -1; return; }
  const c = CHUNKS[i];
  if (i !== capNow) { line.innerHTML = c.words.map(w => `<span>${esc(w.t)}${/[?!]/.test(w.p) ? w.p.replace(/[^?!]/g, '') : ''}</span>`).join(''); line.style.transform = 'none'; const w = line.scrollWidth; line.dataset.k = w > 936 ? (936 / w).toFixed(4) : 1; capNow = i; }
  line.style.visibility = 'visible';
  line.style.color = s.kind === 'card' && !PL.card_dark ? 'var(--ink)' : '#fff';
  line.className = s.kind === 'card' && !PL.card_dark ? '' : 'ring';
  const k = +line.dataset.k, enter = .92 + .08 * outBack(P(t, c.s, .12));
  line.style.transform = `scale(${(k * enter).toFixed(4)})`;
  let on = 0; c.words.forEach((w, j) => { if (t >= w.s - .03) on = j; });
  [...line.children].forEach((e, j) => { e.classList.toggle('on', j === on); e.style.transform = j === on ? `scale(${(1 + .12 * (1 - outCubic(P(t, c.words[j].s - .03, .14)))).toFixed(3)}) rotate(-1.5deg)` : 'none'; });
}

/* ---------- the closing card ---------- */
const SITE = C.shots.find(s => s.kind === 'site');
if (SITE) { const a = PL.assets.site || {}; if (a.file) $('ct-page').src = 'assets/' + a.file; const ticks = PL.ending && PL.ending.ticks; if (ticks && ticks[0]) $('ct-c1').innerHTML = '<u>✓</u>' + esc(ticks[0]); if (ticks && ticks[1]) $('ct-c2').innerHTML = '<u>✓</u>' + esc(ticks[1]); }
function scSite(t) { const paper = $('paper'); if (!SITE || t < SITE.t0) { paper.style.display = 'none'; return; } const a = SITE.t0; const ln = LN[SITE.id];
  paper.style.display = 'block'; paper.style.transform = `translateY(${((1 - outCubic(P(t, a, .26))) * 1920).toFixed(1)}px)`;
  put($('ct-mark'), rise(t, a + .12, .36, 50)); put($('ct-tag'), rise(t, a + .3, .36, 30));
  const q = outCubic(P(t, a + .16, .5)); $('ct-phone').style.transform = `translateY(${((1 - q) * 900).toFixed(1)}px) rotate(${(-3 + 3 * q).toFixed(2)}deg)`;
  $('ct-page').style.transform = `translateY(${(-inOut(P(t, a + .5, C.end - a - .9)) * 1500).toFixed(1)}px)`;
  const mid = ln ? ln.s + (ln.e - ln.s) * .35 : a + 1; put($('ct-c1'), {...pop(t, a + .6), r: -6}); put($('ct-c2'), {...pop(t, mid), r: 5});
  const urlAt = ln ? W(ln.id, 'Gen') : a + 1.5; put($('ct-url'), {...slam(t, Math.max(a + .8, urlAt - .04), .22), r: -2});
  const lb = ln ? W(ln.id, 'Link') : a + 2.5; put($('ct-bio'), {o: P(t, lb - .05, .08), x: 16 * Math.sin((t - lb) * 9), s: .6 + .4 * outBack(P(t, lb - .05, .25)), r: 3}); }

/* ---------- grain ---------- */
(function () { const cv = document.createElement('canvas'); cv.width = 270; cv.height = 480; const x = cv.getContext('2d'), im = x.createImageData(270, 480); let s0 = 1234567;
  for (let i = 0; i < im.data.length; i += 4) { s0 = (s0 * 1664525 + 1013904223) >>> 0; const v = (s0 >>> 24); im.data[i] = im.data[i + 1] = im.data[i + 2] = v; im.data[i + 3] = 255; }
  x.putImageData(im, 0, 0); $('grain').style.backgroundImage = `url(${cv.toDataURL()})`; })();
function grain(t) { const f = Math.round(t * 30); $('grain').style.backgroundPosition = `${(f * 37) % 61}px ${(f * 53) % 59}px`; }

window.render = async function (t) {
  const s = shotAt(t);
  await setBase(t, s);
  camera(t, s); chrome(t, s); animate(t); scSite(t); caps(t, s); flash(t); grain(t);
  return s.id;
};
window.READY = document.fonts.ready.then(() => Promise.all(['900 40px Fraunces', 'italic 900 40px Fraunces', '800 40px Spartan', '700 20px Mono'].map(f => document.fonts.load(f)))).then(() => Promise.all([...document.images].map(i => i.src ? i.decode().catch(() => 0) : 0))).then(() => true);
