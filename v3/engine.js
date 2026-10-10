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
  if (s.mode === 'W') sc = 1 + .02 * p + .02 * (1 - outExpo(P(t, s.t0, .38)));   // a picture shown WHOLE keeps its edges (and the words written in it): the camera never grows it past the screen
  for (const [ta, size] of IMP) { const q = (t - ta) / .3; if (q >= 0 && q < 1) { const f = Math.round((t - ta) * 30), amp = 11 * size * (s.mode === 'D' ? .5 : 1); dx += Math.sin(f * 12.9898 + 1) * amp * (1 - q); dy += Math.cos(f * 78.233 + 2) * amp * (1 - q); } }
  cam.style.transform = `translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) scale(${sc.toFixed(4)})`;
  $('dim').style.opacity = s.dim ?? (s.mode === 'B' ? .3 : .05);
  const cr = $('credit'); cr.style.display = s.credit ? 'block' : 'none'; cr.textContent = s.credit || '';
}
function flash(t) { let o = 0;
  for (const c of CUTS) if (t >= c && !XF_AT.has(c)) o = Math.max(o, .32 * (1 - P(t, c, .1)));     // a cut with its own transition has no flash
  for (const [a, size] of IMP) if (size >= 1.2 && a > .05 && t >= a) o = Math.max(o, .5 * (1 - P(t, a, .12)));
  $('flash').style.opacity = o.toFixed(3); }

/* ---------- transitions between shots ----------
   WebGL fragment shaders ported from HyperFrames (github.com/heygen-com/hyperframes, packages/shader-transitions,
   Apache-2.0, Copyright HeyGen). The two pictures are painted on 2D canvases (the outgoing shot at its last frame,
   the incoming one at the current time), uploaded as textures, and mixed by the shader over the cut.
   Which cut gets one is a general rule read from the comp data: never the first half second, never two within three
   seconds, and only where the story moves (a new sentence on an impact, a card, a tense section, the site line). */
const GL_H = 'precision mediump float;varying vec2 v_uv;uniform sampler2D u_from,u_to;uniform float u_progress;uniform vec2 u_resolution;uniform vec3 u_accent;uniform vec3 u_accent_dark;uniform vec3 u_accent_bright;\n';
const GL_V = 'attribute vec2 a_pos;varying vec2 v_uv;void main(){v_uv=a_pos*0.5+0.5;v_uv.y=1.0-v_uv.y;gl_Position=vec4(a_pos,0,1);}';
const GL_FRAG = {
  'whip-pan': 'void main(){float fromOff=u_progress*1.5;vec3 fromC=vec3(0.);for(int i=0;i<10;i++){float f=float(i)/10.;vec2 fuv=vec2(v_uv.x+fromOff+u_progress*.08*f,v_uv.y);fromC+=texture2D(u_from,clamp(fuv,0.,1.)).rgb;}fromC/=10.;float toOff=(1.-u_progress)*1.5;vec3 toC=vec3(0.);for(int i=0;i<10;i++){float f=float(i)/10.;vec2 tuv=vec2(v_uv.x-toOff-(1.-u_progress)*.08*f,v_uv.y);toC+=texture2D(u_to,clamp(tuv,0.,1.)).rgb;}toC/=10.;gl_FragColor=vec4(mix(fromC,toC,u_progress),1.);}',
  'flash-through-white': 'void main(){vec4 A=texture2D(u_from,v_uv),B=texture2D(u_to,v_uv);float toWhite=smoothstep(0.,.45,u_progress);vec3 fromC=mix(A.rgb,vec3(1.),toWhite);float fromWhite=1.-smoothstep(.5,1.,u_progress);vec3 toC=mix(B.rgb,vec3(1.),fromWhite);gl_FragColor=vec4(mix(fromC,toC,smoothstep(.35,.65,u_progress)),1.);}',
  'chromatic-split': 'void main(){vec2 c=v_uv-.5;float fromShift=u_progress*.06;float fr=texture2D(u_from,clamp(v_uv+c*fromShift,0.,1.)).r;float fg=texture2D(u_from,v_uv).g;float fb=texture2D(u_from,clamp(v_uv-c*fromShift,0.,1.)).b;vec3 fromSplit=vec3(fr,fg,fb);float toShift=(1.-u_progress)*.06;float tr=texture2D(u_to,clamp(v_uv-c*toShift,0.,1.)).r;float tg=texture2D(u_to,v_uv).g;float tb=texture2D(u_to,clamp(v_uv+c*toShift,0.,1.)).b;vec3 toSplit=vec3(tr,tg,tb);gl_FragColor=vec4(mix(fromSplit,toSplit,u_progress),1.);}',
  'cinematic-zoom': 'void main(){vec2 d=v_uv-vec2(.5);float fromS=u_progress*.08;float toS=(1.-u_progress)*.06;float fr=0.,fg=0.,fb=0.;for(int i=0;i<12;i++){float f=float(i)/12.;fr+=texture2D(u_from,v_uv-d*(fromS*1.06)*f).r;fg+=texture2D(u_from,v_uv-d*fromS*f).g;fb+=texture2D(u_from,v_uv-d*(fromS*.94)*f).b;}vec3 fromBl=vec3(fr,fg,fb)/12.;float tr=0.,tg=0.,tb=0.;for(int i=0;i<12;i++){float f=float(i)/12.;tr+=texture2D(u_to,v_uv+d*(toS*1.06)*f).r;tg+=texture2D(u_to,v_uv+d*toS*f).g;tb+=texture2D(u_to,v_uv+d*(toS*.94)*f).b;}vec3 toBl=vec3(tr,tg,tb)/12.;gl_FragColor=vec4(mix(fromBl,toBl,u_progress),1.);}',
  'glitch': 'float rand(vec2 co){return fract(sin(dot(co,vec2(12.9898,78.233)))*43758.5453);}void main(){float inten=u_progress*(1.-u_progress)*4.;float lineY=floor(v_uv.y*60.)/60.;float lineDisp=(rand(vec2(lineY,floor(u_progress*17.)))-.5)*.18*inten;vec2 block=floor(v_uv*vec2(12.,8.));float br=rand(block+vec2(floor(u_progress*11.)));float ba=step(.83,br)*inten;vec2 bd=(vec2(rand(block*2.1),rand(block*3.7))-.5)*.35*ba;vec2 uv=clamp(v_uv+vec2(lineDisp,0.)+bd,0.,1.);float shift=inten*.035;float r=texture2D(u_from,uv+vec2(shift,0.)).r;float g=texture2D(u_from,uv).g;float b=texture2D(u_from,uv-vec2(shift,0.)).b;vec3 col=vec3(r,g,b);col-=step(.5,fract(v_uv.y*u_resolution.y*.5))*.05*inten;col*=1.+(rand(vec2(floor(u_progress*23.)))-.5)*.3*inten;float levels=mix(256.,8.,inten*.5);col=floor(col*levels)/levels;gl_FragColor=mix(vec4(col,1.),texture2D(u_to,v_uv),u_progress);}',
  'light-leak': 'vec3 aces(vec3 x){return clamp((x*(2.51*x+.03))/(x*(2.43*x+.59)+.14),0.,1.);}void main(){vec4 A=texture2D(u_from,v_uv),B=texture2D(u_to,v_uv);vec2 lp=vec2(1.3,-.2);float dist=length(v_uv-lp);float leak=clamp(exp(-dist*1.8)*u_progress*4.,0.,1.);vec3 warmColor=mix(u_accent,u_accent_bright,dist*.7);float flare=exp(-abs(v_uv.y-(-.2+v_uv.x*.3))*15.)*leak*.3;vec3 overexposed=A.rgb+warmColor*leak*3.+u_accent_bright*flare;overexposed=aces(overexposed);gl_FragColor=vec4(mix(overexposed,B.rgb,smoothstep(.15,.85,u_progress)),1.);}',
};
const inOutQuad = p => p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
function xfPlan() {
  const out = [], sections = (C.cues && C.cues.sections) || [];
  C.shots.forEach((s, i) => {
    if (!i) return;
    const prev = C.shots[i - 1], c = s.t0;
    if (c < .5 || c > C.end - .6 || !'FBCWSD'.includes(s.mode) || !'FBCWSD'.includes(prev.mode)) return;   // the slang format draws its own scenes
    const stem = a => String(a || '').replace(/_s\d+$/, '');                                   // a still cut out of a clip is the clip's own figure
    const newLine = s.line !== prev.line, sameAsset = stem(s.asset) === stem(prev.asset);
    const impact = IMP.some(([a, size]) => a - c > -.15 && a - c < 1.0 && size >= 1);        // the sentence opens on a hit (a stamp lands on one of its first words)
    const mood = (sections.find(([a]) => Math.abs(a - c) < .05) || [])[1];
    let name = null, d = .3, w = 0;
    if (newLine && s.line === 'site') { name = 'chromatic-split'; d = .4; w = 5; }                 // the sign-off: a split of colour into the site
    else if (newLine && s.card) { name = 'flash-through-white'; d = .3; w = 4; }                   // a card lands: through white
    else if (newLine && mood === 'tense') { name = 'glitch'; d = .3; w = 3; }                      // a tense section (numbers, rows) opens with a glitch
    else if (newLine && impact && !sameAsset) { name = 'whip-pan'; d = .28; w = 2; }               // a new sentence on a hit: whip to the next picture
    else if (!newLine && sameAsset && s.mode !== prev.mode) { name = 'cinematic-zoom'; d = .24; w = 1; }   // the same picture, closer or wider: a push
    if (name) out.push({at: c, d, name, w, from: prev, to: s});
  });
  // never two within three seconds: the weightier one stays (a card landing over a push)
  const kept = [];
  for (const x of out.slice().sort((p, q) => q.w - p.w || p.at - q.at)) if (!kept.some(k => Math.abs(k.at - x.at) < 3)) kept.push(x);
  return kept.sort((p, q) => p.at - q.at);
}
const XF = (function () {
  const cv = document.createElement('canvas'); cv.id = 'xf'; cv.width = 1080; cv.height = 1920;
  cv.style.cssText = 'position:absolute;left:0;top:0;width:1080px;height:1920px;display:none';
  cam.appendChild(cv);
  let gl = null;
  try { gl = cv.getContext('webgl', {preserveDrawingBuffer: true, antialias: false, premultipliedAlpha: false}); } catch (e) { gl = null; }
  if (!gl) { window.XF_OFF = 'no webgl'; return null; }
  const progs = {}, tex = [gl.createTexture(), gl.createTexture()];
  const buf = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, buf); gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
  const sh = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s)); return s; };
  const prog = name => { if (progs[name]) return progs[name];
    const p = gl.createProgram(); gl.attachShader(p, sh(gl.VERTEX_SHADER, GL_V)); gl.attachShader(p, sh(gl.FRAGMENT_SHADER, GL_H + GL_FRAG[name])); gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(p));
    return progs[name] = p; };
  const rgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16) / 255);
  const ACC = rgb('#C71F12'), ACCD = rgb('#1A1814'), ACCB = rgb('#FF3B2F');
  for (const [i, t] of tex.entries()) { gl.activeTexture(gl.TEXTURE0 + i); gl.bindTexture(gl.TEXTURE_2D, t); for (const [k, v] of [[gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE], [gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE], [gl.TEXTURE_MIN_FILTER, gl.LINEAR], [gl.TEXTURE_MAG_FILTER, gl.LINEAR]]) gl.texParameteri(gl.TEXTURE_2D, k, v); }
  function draw(name, from, to, p) {
    const pg = prog(name); gl.useProgram(pg); gl.viewport(0, 0, 1080, 1920);
    for (const [i, src] of [[0, from], [1, to]]) { gl.activeTexture(gl.TEXTURE0 + i); gl.bindTexture(gl.TEXTURE_2D, tex[i]); gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, src); }
    gl.uniform1i(gl.getUniformLocation(pg, 'u_from'), 0); gl.uniform1i(gl.getUniformLocation(pg, 'u_to'), 1);
    gl.uniform1f(gl.getUniformLocation(pg, 'u_progress'), p); gl.uniform2f(gl.getUniformLocation(pg, 'u_resolution'), 1080, 1920);
    gl.uniform3fv(gl.getUniformLocation(pg, 'u_accent'), ACC); gl.uniform3fv(gl.getUniformLocation(pg, 'u_accent_dark'), ACCD); gl.uniform3fv(gl.getUniformLocation(pg, 'u_accent_bright'), ACCB);
    const a = gl.getAttribLocation(pg, 'a_pos'); gl.bindBuffer(gl.ARRAY_BUFFER, buf); gl.enableVertexAttribArray(a); gl.vertexAttribPointer(a, 2, gl.FLOAT, false, 0, 0);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  }
  return {cv, draw};
})();
const PICS = {};
function pic(src) { return PICS[src] || (PICS[src] = new Promise(res => { const im = new Image(); im.onload = () => res(im); im.onerror = () => { window.ERR = (window.ERR || 0) + 1; res(null); }; im.src = src; })); }
function canvas2d() { const c = document.createElement('canvas'); c.width = 1080; c.height = 1920; return c; }
function cover(ctx, im, x, y, w, h) { const k = Math.max(w / im.naturalWidth, h / im.naturalHeight), dw = im.naturalWidth * k, dh = im.naturalHeight * k; ctx.drawImage(im, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh); }
async function paint(s, t, cv) {
  // the picture of one shot at time t, as setBase() shows it, on a 2D canvas
  const ctx = cv.getContext('2d'); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.filter = 'none'; ctx.fillStyle = '#0b0a09'; ctx.fillRect(0, 0, 1080, 1920);
  const p = clamp((t - s.t0) / (s.t1 - s.t0));
  if ('FBCW'.includes(s.mode)) {
    const i = Math.max(1, Math.min(s.n, Math.floor((t - s.t0) * C.fps + 1e-6) + 1)), im = await pic(`base/${s.dir}/${String(i).padStart(4, '0')}.jpg`);
    if (im) ctx.drawImage(im, 0, 0, 1080, 1920);
    return cv;
  }
  const bg = await pic(s.img); if (!bg) return cv;
  ctx.filter = 'blur(30px) brightness(.42) saturate(.9)'; cover(ctx, bg, -220, -120, 1520, 2160); ctx.filter = 'none';
  if (s.mode === 'S') {
    const cx = lerp(s.k0[0], s.k1[0], p), cy = lerp(s.k0[1], s.k1[1], p), z = lerp(s.k0[2], s.k1[2], p), fy = s.fy || 960;
    ctx.setTransform(z, 0, 0, z, 540 - cx * z, fy - cy * z); ctx.drawImage(bg, 0, 0);
  } else if (s.mode === 'D') {
    for (const [k, top] of [[s.a, 140], [s.b, 720]]) { const im = k.img ? await pic(k.img) : bg; if (!im) continue;
      const cx = lerp(k.k0[0], k.k1[0], p), cy = lerp(k.k0[1], k.k1[1], p), z = lerp(k.k0[2], k.k1[2], p);
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.beginPath(); ctx.rect(0, top, 1080, 560); ctx.clip(); ctx.fillStyle = '#000'; ctx.fillRect(0, top, 1080, 560);
      ctx.setTransform(z, 0, 0, z, 540 - cx * z, top + 280 - cy * z); ctx.drawImage(im, 0, 0); ctx.restore();
      ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = '#FBFAF8'; ctx.fillRect(0, top, 1080, 6); ctx.fillRect(0, top + 554, 1080, 6); }
  }
  return cv;
}
const XFS = XF ? xfPlan() : [], XF_AT = new Set(XFS.map(x => x.at)), toCv = canvas2d();
async function xfade(t) {
  const x = XFS.find(x => t >= x.at && t < x.at + x.d);
  if (!x) { if (XF) XF.cv.style.display = 'none'; return; }
  if (!x.from_cv) x.from_cv = await paint(x.from, x.at - 1 / C.fps, canvas2d());     // the outgoing shot, frozen on its last frame
  await paint(x.to, t, toCv);
  XF.draw(x.name, x.from_cv, toCv, inOutQuad((t - x.at) / x.d));
  XF.cv.style.display = 'block';
}

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
      // a group is one breath: three words or 17 letters, a pause of half a second, or 2.5 s of speech end it (caption grouping as in HyperFrames)
      const prev = cur[cur.length - 1];
      const name = prev && /^[A-Z]/.test(prev.raw) && /^[A-Z]/.test(k.raw) && !prev.p && cur.length > 1 && cur.length < 4 && len <= 24 && k.s - prev.e < .3;      // a name of two words stays in one group ("KAI CENAT")
      if (cur.length && !name && (cur.length >= 3 || len > 17 || k.s - cur[cur.length - 1].e >= .5 || k.e - cur[0].s > 2.5)) flush();
      cur.push(k);
      if (/[.?!:,]/.test(k.p)) flush();
    }
    flush();
  }
  // a group shorter than half a second on its own joins the next one (never a flash of two words)
  for (let i = 0; i + 1 < CHUNKS.length; i++) { const c = CHUNKS[i], nx = CHUNKS[i + 1];
    if (c.line === nx.line && c.words[c.words.length - 1].e - c.words[0].s < .35 && c.words.length + nx.words.length <= 4 && (c.words.concat(nx.words).reduce((a, x) => a + x.t.length + 1, 0) <= 24)) { nx.words = c.words.concat(nx.words); CHUNKS.splice(i, 1); i--; } }
  const EMPH = w => /\d/.test(w.t) || w.t.startsWith('@') || (/^[A-Z][A-Z0-9'&-]+$/.test(w.raw) && w.raw.length >= 2);
  CHUNKS.forEach((c, i) => {
    const nx = CHUNKS[i + 1], le = c.words[c.words.length - 1].e, last = c.words[c.words.length - 1];
    c.s = c.words[0].s - .08; c.e = nx ? Math.min(nx.words[0].s - .05, le + .6) : le + .6;   // enters just before its first word, lingers .6 s, never into the next group
    if (c.e - c.s < .5 && !(nx && nx.words[0].s - .05 < c.s + .5)) c.e = c.s + .5;         // half a second on screen at least
    c.gap = nx ? nx.words[0].s - .05 - c.e >= .25 : true;                                    // an exit only in a gap, never during speech
    const hit = IMP.some(([a, size]) => size >= .9 && c.words[0].s - a > -.05 && c.words[0].s - a < .35);
    // the entry move follows the tone of the group: a hit or a question/exclamation pops, numbers swipe in, every seventh burns in (variety), the rest fade up
    c.mv = c.words.some(w => /\d/.test(w.t)) ? 'swipe' : (hit || /[?!]/.test(last.p)) ? 'pop' : i % 7 === 6 ? 'burn' : 'fadeup';
    // one emphasis word per group: a number, a handle, a name in capitals, or (on a hit) the longest word of 7+ letters
    let em = c.words.findIndex(EMPH);
    if (em < 0 && hit) { const L = c.words.map(w => w.t.length), mx = Math.max(...L); if (mx >= 7) em = L.indexOf(mx); }
    c.em = em;
  });
}
let capNow = -1;
const CAP_D = {fadeup: .2, pop: .22, swipe: .3, burn: 0};
function capMove(c, t) {
  // the entry move (and its reverse at 60% length as the exit, only in a gap): opacity, y, scale, clip
  const d = CAP_D[c.mv], m = {o: 1, y: 0, s: 1, clip: 0};
  const q = d ? P(t, c.s, d) : 1, xd = .6 * d, qx = (c.gap && d && t > c.e - xd) ? P(t, c.e - xd, xd) : 0;
  if (c.mv === 'fadeup') { const e = outCubic(q); m.o = e * (1 - qx); m.y = 8 * (1 - e) - 8 * qx; }
  else if (c.mv === 'pop') { const e = outBack(q); m.o = Math.min(1, q * 3) * (1 - qx); m.s = (.85 + .15 * e) * (1 - .15 * qx); }
  else if (c.mv === 'swipe') { const e = q < .5 ? 16 * Math.pow(q, 5) : 1 - Math.pow(-2 * q + 2, 5) / 2; m.clip = Math.max(1 - e, qx); m.side = qx ? 'l' : 'r'; }
  return m;
}
function caps(t, s) {
  const line = $('capline'); $('caps').style.top = (s.capY || 1284) + 'px';
  const i = CHUNKS.findIndex(c => t >= c.s && t < c.e);
  if (i < 0) { line.style.visibility = 'hidden'; capNow = -1; return; }
  const c = CHUNKS[i];
  if (i !== capNow) {
    line.innerHTML = c.words.map(w => `<span>${w.t}${/[?!]/.test(w.p) ? w.p.replace(/[^?!]/g, '') : ''}</span>`).join('');
    line.style.transform = 'none'; line.style.opacity = 1; line.style.clipPath = 'none';
    line.classList.toggle('bite', !!LN[c.line].bite);          // the story's own clip is speaking: its words are boxed in yellow, not red
    const w = line.scrollWidth; line.dataset.k = w > 836 ? (836 / w).toFixed(4) : 1;
    capNow = i;
  }
  line.style.visibility = 'visible';
  const k = +line.dataset.k, m = capMove(c, t);
  line.style.opacity = m.o.toFixed(3);
  line.style.transform = `translateY(${m.y.toFixed(1)}px) scale(${(k * m.s).toFixed(4)})`;
  line.style.clipPath = m.clip > 0 ? (m.side === 'l' ? `inset(0 0 0 ${(100 * m.clip).toFixed(1)}%)` : `inset(0 ${(100 * m.clip).toFixed(1)}% 0 0)`) : 'none';
  let on = 0; c.words.forEach((w, j) => { if (t >= w.s - .03) on = j; });
  [...line.children].forEach((el, j) => { el.classList.toggle('on', j === on); el.classList.toggle('em', j === c.em && j !== on && t >= c.words[j].e); el.style.transform = j === on ? `scale(${(1 + .12 * (1 - outCubic(P(t, c.words[j].s - .03, .14)))).toFixed(3)}) rotate(-1.5deg)` : 'none'; });
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
  window.render = async function (t) { const s = await setBase(t); await xfade(t); camera(t, s); drawItems(t); caps(t, s); flash(t); grain(t); return s.id; };
  window.transitions = () => XFS.map(x => [x.at, x.d, x.name, x.from.id, x.to.id]);
  window.READY = document.fonts.ready.then(() => Promise.all(['900 40px Fraunces', 'italic 900 40px Fraunces', '800 40px Spartan', '700 20px Mono'].map(f => document.fonts.load(f)))).then(() => true);
}
