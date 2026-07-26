// Harness: run _CROP_JS from video_maker.py against fake DOMs that reproduce
// the exact defects seen in media/diag/ (shot 6 "The Most Ag", shot 0 "Save
// for late[r]" + breadcrumb + newsletter bar, shot 7 HBCU ad poster).
const fs = require('fs');
const src = fs.readFileSync('/home/u219414635/genzhype-repo/video_maker.py', 'utf8');
const CROP = eval('(' + src.match(/_CROP_JS = """([\s\S]*?)"""/)[1] + ')');

function R(l, t, r, b) { return {left: l, top: t, right: r, bottom: b, width: r - l, height: b - t}; }

class El {
  constructor(tag, rect, {text = '', children = [], lines = null} = {}) {
    this.tagName = tag; this._r = rect; this._lines = lines;
    this.childNodes = text ? [{nodeType: 3, textContent: text}] : [];
    this.children = children; children.forEach(c => (c.parentElement = this));
    this.parentElement = null;
  }
  getBoundingClientRect() { return this._r; }
  querySelectorAll(sel) {
    const out = [];
    const walk = (e) => e.children.forEach(c => {
      if (sel === '*' || c.tagName.toLowerCase() === sel) out.push(c);
      walk(c);
    });
    walk(this);
    return out;
  }
}

function run(name, {h1, body, docw = 1440, doch = 4000, host = 'example.com', infobox = null, expect}) {
  global.window = {scrollX: 0, scrollY: 0};
  global.location = {hostname: host};
  global.document = {
    documentElement: {scrollWidth: docw, clientWidth: docw, scrollHeight: doch, clientHeight: doch},
    body: body,
    querySelector: (sel) => (sel.indexOf('infobox') >= 0 ? infobox : null),
    createRange: () => ({
      selectNodeContents(n) { this.n = n; },
      getClientRects() { return this.n._lines || [this.n._r]; },
    }),
  };
  const out = CROP(h1);
  if (!out) { console.log(`${name}: returned null (falls back)`); return; }
  const L = out.x, R2 = out.x + out.w, T = out.y, B = out.y + out.h;
  const problems = [];
  for (const [what, r] of Object.entries(expect.mustContain || {})) {
    if (r.left < L - 0.5 || r.right > R2 + 0.5) problems.push(`${what} horizontally cut (crop ${L.toFixed(0)}..${R2.toFixed(0)} vs ${r.left}..${r.right})`);
  }
  for (const [what, r] of Object.entries(expect.mustExclude || {})) {
    const overlapX = r.left < R2 && r.right > L;
    if (!overlapX) continue;                    // not in the shot at all
    const overlaps = r.top < B && r.bottom > T;
    const straddles = (r.top < B && r.bottom > B) || (r.top < T && r.bottom > T);
    if (straddles) problems.push(`${what} STRADDLES an edge (crop y ${T.toFixed(0)}..${B.toFixed(0)} vs ${r.top}..${r.bottom})`);
    else if (overlaps && expect.strictExclude) problems.push(`${what} fully inside the shot`);
  }
  console.log(`${name}: crop ${out.w.toFixed(0)}x${out.h.toFixed(0)} at ${L.toFixed(0)},${T.toFixed(0)}  ${problems.length ? 'FAIL -> ' + problems.join('; ') : 'PASS'}`);
}

// ---- case 1: shot 6 (hiphopwired). Headline text renders WIDER than its own
// wrapper div -> the r30 crop cut "The Most Ag" at the right edge.
{
  const hlLines = [R(35, 50, 1400, 110), R(35, 120, 950, 180)];   // glyphs to x=1400
  const h1 = new El('H1', R(35, 50, 900, 180), {text: 'Soulja Boy Sends...', lines: hlLines});
  const wrap = new El('DIV', R(35, 40, 900, 200), {children: [h1]});     // NARROWER than the glyphs
  const deck = new El('P', R(35, 210, 1380, 250), {text: 'One thing about Soulja Boy...'});
  const img = new El('IMG', R(108, 390, 1045, 1010), {});
  const cap = new El('P', R(108, 1020, 400, 1050), {text: 'Source: Amir Gray / iOne'});
  const art = new El('ARTICLE', R(20, 30, 1420, 3000), {children: [wrap, deck, img, cap]});
  const body = new El('BODY', R(0, 0, 1440, 4000), {children: [art]});
  run('shot6 headline-wider-than-wrapper', {
    h1, body,
    expect: {mustContain: {headline: R(35, 50, 1400, 180), deck: R(35, 210, 1380, 250)}},
  });
}

// ---- case 2: shot 0 (thefocus-style). Breadcrumb straddled the TOP edge, a
// "Save for later" button sat outside the column on the right, and a yellow
// newsletter bar was sliced by the BOTTOM edge.
{
  const hlLines = [R(24, 60, 1050, 110), R(24, 118, 420, 168)];
  const h1 = new El('H1', R(24, 60, 1050, 168), {text: 'Soulja Boy launches...', lines: hlLines});
  const crumb = new El('DIV', R(24, 20, 300, 52), {text: 'ENTERTAINMENT / TWITCH'});
  const save = new El('BUTTON', R(1200, 190, 1420, 240), {text: 'Save for later'});
  const img = new El('IMG', R(24, 240, 640, 800), {});
  const news = new El('DIV', R(24, 812, 640, 900), {text: 'Get Notifications for Entertainment News'});
  const col = new El('DIV', R(24, 10, 1080, 2000), {children: [crumb, h1, img, news]});
  const body = new El('BODY', R(0, 0, 1440, 4000), {children: [col, save]});
  run('shot0 breadcrumb+newsletter+outside-button', {
    h1, body, strictExclude: true, expect: {
      mustContain: {headline: R(24, 60, 1050, 168)},
      mustExclude: {breadcrumb: R(24, 20, 300, 52), newsletterBar: R(24, 812, 640, 900),
                    saveButton: R(1200, 190, 1420, 240)},
    },
  });
}

// ---- case 4: r27's balleralert regression — a signup/ad box in the right rail
// BESIDE the headline must stay out of the shot (this is what r29's full-width
// band re-admitted and what failed run #144 on criterion g).
{
  const hlLines = [R(60, 80, 700, 130), R(60, 140, 520, 190)];
  const h1 = new El('H1', R(60, 80, 700, 190), {text: 'Headline in a column', lines: hlLines});
  const img = new El('IMG', R(60, 220, 700, 700), {});
  const col = new El('DIV', R(50, 60, 720, 2200), {children: [h1, img]});
  const rail = new El('ASIDE', R(780, 60, 1400, 900), {text: 'Get Your Baller Alerts'});
  const body = new El('BODY', R(0, 0, 1440, 4000), {children: [col, rail]});
  run('sidebar signup box beside the headline', {
    h1, body, strictExclude: true,
    expect: {mustContain: {headline: R(60, 80, 700, 190)},
             mustExclude: {rightRail: R(780, 60, 1400, 900)}},
  });
}

// ---- case 5 (r33): Wikipedia. The delivered El Risitas video showed a full
// Wikipedia article scaled down to an unreadable grey block. The crop must
// take the INFOBOX (portrait + name + dates), not the body wall.
{
  const hlLines = [R(40, 40, 300, 90)];
  const h1 = new El('H1', R(40, 40, 300, 90), {text: 'El Risitas', lines: hlLines});
  const bodyText = new El('P', R(40, 120, 780, 900), {text: 'Juan Joya Borja was a Spanish comedian...'});
  const infobox = new El('TABLE', R(820, 40, 1180, 700), {text: 'El Risitas born 1956'});
  const col = new El('DIV', R(30, 30, 1190, 3000), {children: [h1, bodyText, infobox]});
  const body = new El('BODY', R(0, 0, 1440, 4000), {children: [col]});
  run('wikipedia -> infobox not body wall', {
    h1, body, host: 'en.wikipedia.org', infobox,
    expect: {mustContain: {headline: R(40, 40, 300, 90), infobox: R(820, 40, 1180, 700)}},
  });
}

// ---- case 3: shot 7 (bossip-style). An "HBCU AWARE FEST" ad poster sits far
// below the headline; r30 picked it as the "lead image" and swept the whole
// article body + ad into the shot.
{
  const hlLines = [R(24, 30, 1055, 55), R(24, 60, 1050, 90), R(24, 92, 300, 120)];
  const h1 = new El('H1', R(24, 30, 1055, 120), {text: 'SOULJA BOY GETS KICKED OUT...', lines: hlLines});
  const share = new El('DIV', R(92, 225, 990, 265), {text: 'SHARE TWEET'});
  const para = new El('P', R(92, 300, 990, 330), {text: 'Soulja Boy was persona non grata...'});
  const adPoster = new El('IMG', R(318, 875, 766, 1500), {});     // 755px below the headline
  const col = new El('DIV', R(24, 20, 1060, 2400), {children: [h1, share, para, adPoster]});
  const body = new El('BODY', R(0, 0, 1440, 4000), {children: [col]});
  run('shot7 far-below ad poster', {
    h1, body, strictExclude: true,
    expect: {mustContain: {headline: R(24, 30, 1055, 120)},
             mustExclude: {adPoster: R(318, 875, 766, 1500)}},
  });
}
