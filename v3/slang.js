/* THE SLANG FORMAT (a word page): a game in motion graphics, no footage. Modelled on the hand-made "Glaze Test":
   the test (three texts, pick before the reveal, 3-2-1), the meaning (a dictionary card), the forms, a quick round
   with a meter, the origin on a timeline, a real quote, a final one-word choice; the closing (three devices) comes
   from build.js. The word's theme (one colour, one emoji) supplies the look: drips at the top, a wave at the bottom,
   drip wipes between scenes, emoji bursts on every reveal. Loaded after build.js and before start(). */
if (PLAN.format === 'slang') (function () {
  const S = PLAN.slang, WORD = String(S.word).toUpperCase();
  const PAL = {pink: '#F26FB0', yellow: '#FFD400', green: '#7BD23C', blue: '#2F6BFF', orange: '#FF8A1F', purple: '#8B5CF6', brown: '#9A6A3A', red: '#C71F12', teal: '#14B8A6'};
  const ACC = PAL[S.theme.color] || PAL.pink, ON = ['blue', 'purple', 'brown', 'red'].includes(S.theme.color) ? '#fff' : '#1A1814';
  const root = document.documentElement.style; root.setProperty('--acc', ACC); root.setProperty('--onacc', ON);
  $('shade').style.display = 'none';
  const has = id => !!LN[id], span = id => spanOf(id), rndf = (i, k = 1) => { const x = Math.sin(i * 127.1 + k * 311.7) * 43758.5453; return x - Math.floor(x); };

  /* the background: paper, drips at the top, a scalloped wave at the bottom (both under TikTok's own bars) */
  const bg = document.createElement('div'); bg.id = 'sbg';
  let drips = '<rect x="-60" y="-60" width="1200" height="120" fill="var(--acc)"/>';
  for (let i = 0; i < 17; i++) drips += `<rect x="${-50 + i * 70}" y="-60" width="58" height="${150 + Math.round(rndf(i, 3) * 95)}" rx="29" fill="var(--acc)"/>`;
  let wave = '<rect x="-60" y="1640" width="1200" height="400" fill="var(--acc)"/>';
  for (let i = 0; i < 11; i++) wave += `<circle cx="${-20 + i * 112}" cy="1642" r="64" fill="var(--acc)"/>`;
  bg.innerHTML = `<svg width="1080" height="1920" viewBox="0 0 1080 1920" style="position:absolute;left:0;top:0;overflow:visible">${drips}${wave}</svg>`;
  cam.insertBefore(bg, cam.firstChild);
  const wipe = document.createElement('div'); wipe.id = 'swipe';
  for (let i = 0; i < 8; i++) wipe.innerHTML += `<i style="left:${i * 135}px"></i>`;
  $('stage').insertBefore(wipe, $('caps'));
  const burst = document.createElement('div'); burst.id = 'sburst';
  for (let i = 0; i < 16; i++) burst.innerHTML += `<span>${esc(S.theme.emoji)}</span>`;
  $('stage').insertBefore(burst, $('caps'));

  const scenes = ['hook', 'mean', 'forms', 'round1', 'origin', 'quote', 'final', 'site'].filter(has);
  const CUTS_ = scenes.slice(1).map(id => span(id)[0]);
  const DARK = id => ['origin', 'final'].includes(id), TINT = id => ['forms', 'quote'].includes(id);
  const sceneAt = t => { let cur = scenes[0]; for (const id of scenes) if (t >= span(id)[0]) cur = id; return cur; };
  const verdictAt = (id, label) => { const w0 = norm(String(label)).split(' ')[0], hit = LN[id].words.filter(w => norm(w.w) === w0); return hit.length ? hit[hit.length - 1].s - .05 : LN[id].e - .8; };
  const BURSTS = [];

  /* 1. the test */
  const [qa, qb] = [span('hook')[0], has('mean') ? span('mean')[0] : span('rev')[1]];
  const revT = LN.rev.words[LN.rev.words.length - 1].s - .04, right = S.quiz.texts.findIndex(x => x.right);
  item(`<div class="sq">${esc(S.quiz.question)}</div>`, 500, 262, qa + .05, qb, 'pop', {fit: 880, key: 'slang.q'});
  item(`<div class="stamp" style="font-size:118px;background:var(--acc);color:var(--onacc)">${esc(WORD)}?</div>`, 500, 392, qa + .25, qb, 'slam', {r: -3, fit: 860, key: 'slang.w'});
  S.quiz.texts.forEach((x, i) => item(`<div class="sb"><b>${'ABC'[i]}</b><p>${esc(x.t)}</p></div>`, 60, 500 + i * 196, qa + .55 + i * .28, qb, 'left', {tl: 1, key: 'slang.t' + i,
    fn: (t, el) => { const on = t >= revT; el.classList.toggle('yes', on && i === right); el.style.opacity = on && i !== right ? .32 : el.style.opacity;
      if (on && i === right) el.style.transform += ` scale(${(1 + .06 * (1 - outExpo(P(t, revT, .4)))).toFixed(3)})`; }}));
  item('<div class="chip dark" style="font-size:34px">PICK ONE · BEFORE THE REVEAL</div>', 500, 1130, at('hook', 'Pick'), LN.lock.e, 'pop', {key: 'slang.pick'});
  item('<div class="scount">3</div>', 500, 1140, LN.lock.e - .05, revT, 'pop', {key: 'slang.count',
    fn: (t, el) => { const p = P(t, LN.lock.e, revT - LN.lock.e), n = Math.min(2, Math.floor(p * 3)); el.textContent = 3 - n; el.style.transform += ` scale(${(1 + .25 * (1 - outExpo((p * 3) % 1))).toFixed(3)})`; }});
  item(`<div class="stamp" style="font-size:104px">IT’S ${'ABC'[right]}</div>`, 500, 1140, revT, qb, 'slam', {r: -4, key: 'slang.rev'});
  BURSTS.push([revT, 500, 500 + right * 196 + 80]);

  /* 2. the meaning: a dictionary card */
  if (has('mean')) { const [a, b] = span('mean');
    item(`<div class="sdict"><h2>${esc(S.word.toLowerCase())}</h2><i>${esc(S.meaning.pos)}</i><p>${esc(S.meaning.text)}</p><em>${esc(S.meaning.source)}</em></div>`, 60, 330, a + .12, b, 'rise', {tl: 1, r: -1.2, key: 'slang.dict',
      fn: (t, el) => { el.querySelector('p').style.backgroundSize = (P(t, a + (b - a) * .35, .7) * 100).toFixed(1) + '% 100%'; }});
    item(`<div class="semo">${esc(S.theme.emoji)}</div>`, 800, 330, a + .45, b, 'pop', {r: 12, wob: 10, key: 'slang.emo'}); }

  /* 3. the forms */
  if (has('forms')) { const [a, b] = span('forms'), n = S.forms.length;
    S.forms.forEach((f, k) => item(`<div class="sform"><b>${esc(f.t)}</b><span>${esc(f.tag)}</span></div>`, 60, 360 + k * 250, f.on ? at('forms', f.on) : a + .2 + k * (b - a - .6) / n, b, 'slam', {tl: 1, r: k % 2 ? 1.2 : -1.2, fit: 880, key: 'slang.f' + k})); }

  /* 4. the quick round, with the meter */
  const rounds = ['round1', 'round2', 'round3'].filter(has);
  if (rounds.length) { const ra = span(rounds[0])[0], rb = span(rounds[rounds.length - 1])[1], marks = [];
    rounds.forEach((id, k) => { const it = S.round.items[k], [a, b] = span(id), vt = verdictAt(id, it.is ? S.round.yes : S.round.no);
      marks.push([vt, it.pct]);
      item(`<div class="srcard"><u>${k + 1} / ${rounds.length}</u><p>${esc(it.t)}</p></div>`, 60, 330, a + .08, b, k ? 'left' : 'rise', {tl: 1, r: k % 2 ? .8 : -.8, key: 'slang.r' + k});
      item(`<div class="stamp${it.is ? '' : ' paper'}" style="font-size:96px${it.is ? ';background:var(--acc);color:var(--onacc)' : ''}">${esc(it.is ? S.round.yes : S.round.no)}</div>`, 520, 720, vt, b, 'slam', {r: it.is ? -5 : 4, fit: 820, key: 'slang.v' + k});
      BURSTS.push([vt, 520, 720]); });
    item(`<div class="smeter"><b>${esc(S.round.meter)}</b><span id="smp">0%</span><div><i id="smf"></i></div></div>`, 60, 900, ra + .2, rb, 'rise', {tl: 1, key: 'slang.meter',
      fn: t => { let v = 0; marks.forEach(([vt, pct], i) => { if (t >= vt) { const from = i ? marks[i - 1][1] : 0; v = from + (pct - from) * outBack(P(t, vt, .5)); } });
        v = clamp(v, 0, 100); $('smf').style.width = v.toFixed(1) + '%'; $('smp').textContent = Math.round(v) + '%'; }}); }

  /* 5. the origin, on a timeline */
  if (has('origin')) { const [a, b] = span('origin'), n = S.origin.length, times = S.origin.map((o, k) => o.on ? at('origin', o.on) : a + .25 + k * (b - a - .8) / n);
    item('<div class="sline"></div>', 96, 400, a + .1, b, 'none', {tl: 1, key: 'slang.line', fn: (t, el) => { el.style.height = (P(t, a + .1, Math.max(.5, times[n - 1] - a)) * (n - 1) * 250 + 40).toFixed(0) + 'px'; }});
    S.origin.forEach((o, k) => item(`<div class="spoint"><u></u>${o.when ? `<span>${esc(o.when)}</span>` : ''}<p>${esc(o.t)}</p></div>`, 60, 380 + k * 250, times[k], b, 'left', {tl: 1, key: 'slang.o' + k})); }

  /* 6. a real quote */
  if (has('quote') && S.quote) { const [a, b] = span('quote');
    item(`<div class="q" style="border-left-color:var(--acc)"><s>IN THE WILD</s><p>“${esc(S.quote.t)}”</p><em>— ${esc(S.quote.who).toUpperCase()}</em></div>`, 500, 620, a + .15, b, 'rise', {r: -1.5, qfit: 1, key: 'slang.quote'}); }

  /* 7. the final choice */
  if (has('final')) { const [a, b] = span('final'), F = S.final, wa = at('final', F.a.word), wb = at('final', F.b.word);
    const again = word => { const w0 = norm(word).split(' ')[0], hit = LN.final.words.filter(w => norm(w.w) === w0); return hit.length > 1 ? [hit[hit.length - 1].s - .04] : []; };
    item(`<div class="sset"><u>FINAL TEST</u><p>${esc(F.setup)}</p></div>`, 60, 286, a + .12, b, 'rise', {tl: 1, r: -1, key: 'slang.set'});
    item(`<div class="ob" style="background:var(--acc);color:var(--onacc)"><span>${esc(F.a.sub || '').toUpperCase()}</span><b>${esc(F.a.word).toUpperCase()}</b></div>`, 60, 588, wa - .02, b, 'slam', {tl: 1, r: -1.5, big: 1, pulse: again(F.a.word), key: 'slang.a'});
    item(`<div class="ob"><span>${esc(F.b.sub || '').toUpperCase()}</span><b>${esc(F.b.word).toUpperCase()}</b></div>`, 60, 852, wb - .02, b, 'slam', {tl: 1, r: 1.5, big: 1, pulse: again(F.b.word), key: 'slang.b'});
    item('<div class="go">COMMENT ONE WORD 👇</div>', 500, 1172, at('final', 'Comment'), b, 'pop', {r: -2, wob: 6, key: 'slang.go'}); }

  /* what runs on every picture: the scene's paper, the drip wipe on each scene change, the emoji bursts */
  item('<div></div>', 0, 0, 0, C.end + 1, 'none', {tl: 1, key: 'slang.fx', fn: t => {
    const id = sceneAt(t);
    bg.style.background = DARK(id) ? '#1A1814' : TINT(id) ? `color-mix(in srgb, ${ACC} 20%, #FBFAF8)` : '#FBFAF8';
    let cover = 0; for (const c of CUTS_) { const q = (t - c + .26) / .26; if (q > 0 && q < 2) cover = Math.max(cover, q <= 1 ? q : 2 - q); }
    [...wipe.children].forEach((bar, i) => { bar.style.height = (cover <= 0 ? 0 : clamp(cover * 1.25 - rndf(i, 9) * .25) * 2000).toFixed(0) + 'px'; });
    const live = BURSTS.filter(([bt]) => t >= bt && t < bt + .9).pop();
    [...burst.children].forEach((sp, i) => { if (!live) { sp.style.visibility = 'hidden'; return; }
      const q = (t - live[0]) / .9, ang = rndf(i, 2) * 6.283, far = 180 + rndf(i, 5) * 360;
      sp.style.visibility = 'visible'; sp.style.opacity = (1 - q * q).toFixed(3);
      sp.style.transform = `translate(${(live[1] + Math.cos(ang) * far * outCubic(q)).toFixed(1)}px,${(live[2] + Math.sin(ang) * far * outCubic(q) + 420 * q * q).toFixed(1)}px) rotate(${(rndf(i, 7) * 80 - 40 + q * 120).toFixed(0)}deg) scale(${(.7 + rndf(i, 4) * .9).toFixed(2)})`; });
  }});
})();
