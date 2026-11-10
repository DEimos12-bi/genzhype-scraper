/* THE TWO AIs (V3_STYLE=duo, owner 2026-11-10): Dots tells the story, Blu says the takes. They live in the band under the
   captions and follow the voice: the one who speaks leans in and moves its mouth with the sound, the other one looks at
   them; when the story's own clip plays or nobody talks, both look up at the picture; a slam on screen makes both jump.
   Pure function of t (the layout check asks for any second in any order). Off when the video is not a duo video. */
(function () {
  const C = window.COMP, D = C.duo, host = document.getElementById('duo');
  window.duoDraw = null;
  if (!host) return;
  if (!D) { host.style.display = 'none'; return; }
  host.style.display = 'block';
  const $ = id => document.getElementById(id);
  const cl = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x)), lerp = (a, b, k) => a + (b - a) * k;
  const eo = k => 1 - Math.pow(1 - cl(k), 3), eb = k => { k = cl(k); const c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(k - 1, 3) + c1 * Math.pow(k - 1, 2); };
  const ramp = (t, t0, d) => cl((t - t0) / d);
  // who is talking when: the words of each voice, joined into spans (a pause under .3 s stays one span)
  const SPANS = [];
  for (const l of C.lines) for (const w of l.words) {
    const who = l.bite ? 'X' : (w.who || 'D'), p = SPANS[SPANS.length - 1];
    if (p && p.who === who && w.s - p.e < .3) p.e = w.e; else SPANS.push({who, s: w.s, e: w.e});
  }
  SPANS.sort((a, b) => a.s - b.s);
  const IMP = (((C.cues || {}).impact) || []).filter(x => (x[1] || 0) >= .8).map(x => x[0]);
  const spanAt = t => SPANS.find(s => t >= s.s - .06 && t <= s.e + .1) || null;
  // what each one does in a state: [eye openness, brow raise, brow angle, gaze x, gaze y]
  function pose(X, sp) {
    const other = X === 'D' ? 1 : -1;                       // Dots sits left and looks right at Blu; Blu looks left at Dots
    if (!sp || sp.who === 'X') return [1.0, 4, 0, other * .45, -1];                       // both watch the picture
    if (sp.who === X) return X === 'D' ? [1.12, 10, -2, other * .35, .05] : [.6, -5, 1, other * .5, .08];   // Dots: bright; Blu: deadpan
    return X === 'D' ? [.72, -1, 4, other, .08] : [1.0, 2, 0, other, .08];                // listening: Dots a little smug, Blu attentive
  }
  function state(X, t) {
    const sp = spanAt(t), start = sp ? sp.s - .06 : (SPANS.filter(s => s.e + .1 < t).pop() || {e: -1}).e + .1;
    const prev = spanAt(start - .05), a = pose(X, prev), b = pose(X, sp), k = eo(ramp(t, start, .2));
    const v = a.map((x, i) => lerp(x, b[i], k));
    const speak = !!(sp && sp.who === X && t >= sp.s - .02 && t <= sp.e + .05);
    let jolt = 0; for (const i of IMP) jolt = Math.max(jolt, Math.sin(Math.PI * ramp(t, i + .02, .34)));
    return {open: v[0] + .3 * jolt, raise: v[1] + 14 * jolt, ang: v[2] * (1 - jolt), gx: v[3], gy: v[4] * (1 - jolt * .6), speak, jolt,
            amp: speak ? (D.amp[Math.round(t * C.fps)] || 0) : 0, hop: sp && sp.who === X ? Math.sin(Math.PI * ramp(t, sp.s - .02, .3)) : 0,
            lean: sp && sp.who === X ? eo(ramp(t, sp.s - .02, .22)) * (1 - ramp(t, sp.e + .05, .2)) : 0};
  }
  const blink = (t, ph) => { const x = (t + ph) % 3.6; return x < .13 ? .08 + .92 * Math.abs(x - .065) / .065 : 1; };
  function eyes(p, s, cy, rx, ry, pr, cxL, cxR, bl, by) {
    const open = cl(s.open * bl, .06, 1.4), eh = ry * Math.min(open, 1.14);
    for (const [side, cx] of [['L', cxL], ['R', cxR]]) {
      $(p + 'E' + side).setAttribute('ry', eh.toFixed(2));
      const pup = $(p + 'P' + side), r = Math.min(pr, eh * .82);
      pup.setAttribute('r', r.toFixed(2));
      pup.setAttribute('cx', (cx + s.gx * (rx - pr) * .85).toFixed(2));
      pup.setAttribute('cy', (cy + s.gy * Math.max(0, eh - r) * .85).toFixed(2));
      const sgn = side === 'L' ? 1 : -1, y = by - s.raise + (1.14 - Math.min(open, 1.14)) * 8, br = $(p + 'B' + side);
      br.setAttribute('y1', (y - s.ang * sgn).toFixed(2)); br.setAttribute('y2', (y + s.ang * sgn).toFixed(2));
    }
  }
  function mouth(p, s, rx0) {
    const m = $(p + 'Mo');
    m.setAttribute('ry', (s.speak ? 5 + s.amp * 27 : (s.open < .66 ? 3.5 : 5)).toFixed(2));
    m.setAttribute('rx', (s.speak ? rx0 + 4 - s.amp * 6 : rx0).toFixed(2));
  }
  window.duoDraw = function (t) {
    const d = state('D', t), b = state('B', t);
    eyes('dd', d, 182, 25, 34, 14, 162, 238, blink(t, 2.1), 132);
    eyes('db', b, 176, 32, 42, 17, 130, 230, blink(t, .4), 116);
    mouth('dd', d, 20); mouth('db', b, 24);
    const inD = 1 - eb(ramp(t, .04, .42)), inB = 1 - eb(ramp(t, .14, .42));              // they pop up from below in the first half second
    const dy = Math.sin(t * 1.5 + 1.3) * 4 - d.hop * 20 - d.amp * 8 - d.jolt * 20 + inD * 340;
    const by = Math.sin(t * 1.7) * 4 - b.hop * 20 - b.amp * 8 - b.jolt * 20 + inB * 340;
    const sc = s => .93 + .12 * s.lean;
    $('duoD').style.transform = `translateY(${dy.toFixed(2)}px) rotate(${(d.lean * 3).toFixed(2)}deg) scale(${(sc(d) * (1 + d.amp * .02)).toFixed(4)},${(sc(d) * (1 - d.amp * .02 + d.jolt * .05)).toFixed(4)})`;
    $('duoB').style.transform = `translateY(${by.toFixed(2)}px) rotate(${(-b.lean * 3).toFixed(2)}deg) scale(${(sc(b) * (1 + b.amp * .025)).toFixed(4)},${(sc(b) * (1 - b.amp * .02 + b.jolt * .05)).toFixed(4)})`;
    const wave = i => d.speak ? -Math.abs(Math.sin(t * 9.5 - i * 1.1)) * (10 + d.amp * 26) : Math.sin(t * 2.1 + i * 1.7) * 6;   // Dots' side dots: a typing wave when she talks
    $('ddL').setAttribute('cy', (232 + wave(0)).toFixed(2)); $('ddR').setAttribute('cy', (232 + wave(2)).toFixed(2));
    $('dbCur').setAttribute('opacity', b.speak ? 1 : (Math.floor(t * 2.2) % 2 ? 1 : .22));
    $('duoPD').style.opacity = (1 - inD).toFixed(3); $('duoPB').style.opacity = (1 - inB).toFixed(3);
    $('duoPD').classList.toggle('on', d.speak); $('duoPB').classList.toggle('on', b.speak);
  };
})();
