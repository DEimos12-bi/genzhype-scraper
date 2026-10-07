"""v2 receipts (read only): a phone-width screenshot of a source page, with the rectangles of the lines to highlight,
and the GenZHype page for the closing card. The owner's receipts.py generalised.
usage: receipts.py <workdir>   (reads plan.json assets of kind receipt/site; writes assets/<id>.png + <id>.json)"""
import json
import os
import sys

from playwright.sync_api import sync_playwright

HIDE = ('[id*=onetrust],[class*=cookie],[class*=Cookie],[class*=consent],[id*=sp_message],[class*=gdpr],[id*=gdpr],'
        '[class*=newsletter],[class*=paywall],[class*=ad-],[id*=ad-],[class*=Ad-],iframe{display:none!important}')
UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'


def say(*a):
    print(*a, flush=True)


def main():
    work = sys.argv[1]
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True, args=['--no-sandbox'])
        ctx = b.new_context(viewport={'width': 540, 'height': 1100}, device_scale_factor=2, locale='en-US', user_agent=UA)
        page = ctx.new_page()
        for aid, a in plan['assets'].items():
            kind = a.get('kind')
            if kind not in ('receipt', 'site') or not a.get('url'):
                continue
            try:
                page.goto(a['url'], wait_until='domcontentloaded', timeout=45000)
                page.wait_for_timeout(3500)
                page.add_style_tag(content=HIDE)
                page.wait_for_timeout(500)
                if kind == 'site':
                    h = page.evaluate('document.documentElement.scrollHeight')
                    page.screenshot(path=os.path.join(adir, aid + '.png'), clip={'x': 0, 'y': 0, 'width': 540, 'height': min(h, 4600)}, full_page=True)
                    a['file'] = aid + '.png'; say('site shot', h)
                    continue
                phrases = [x for x in a.get('highlight', []) if x]
                # find the element that holds the first highlight phrase (a block a phone can read), else the headline
                info = page.evaluate("""(phrases) => {
                  const norm = s => s.replace(/\\s+/g, ' ').trim().toLowerCase();
                  const leaf = [...document.querySelectorAll('p,li,h1,h2,h3,h4,div,span,td')].filter(e => e.children.length <= 3 && phrases.some(ph => norm(e.textContent).includes(norm(ph))));
                  let el = leaf.length ? leaf[0] : document.querySelector('h1');
                  if (!el) return {found: false, title: document.title};
                  let card = el;
                  for (let i = 0; i < 6 && card.parentElement && card.parentElement !== document.body; i++) {
                    const r = card.parentElement.getBoundingClientRect(); if (r.height > 1400) break; card = card.parentElement; if (card.innerText.length > 900) break; }
                  card.setAttribute('data-shot', '1'); card.scrollIntoView({block: 'center'});
                  return {found: true, text: card.innerText.slice(0, 1500), w: card.getBoundingClientRect().width, h: card.getBoundingClientRect().height};
                }""", phrases)
                say(aid, json.dumps(info)[:300])
                if not info.get('found'):
                    continue
                page.wait_for_timeout(600)
                page.locator('[data-shot="1"]').first.screenshot(path=os.path.join(adir, aid + '.png'))
                rects = page.evaluate("""(phrases) => { const c = document.querySelector('[data-shot]'); const r = c.getBoundingClientRect(); const out = {};
                  const norm = s => s.replace(/\\s+/g, ' ').toLowerCase();
                  const walker = document.createTreeWalker(c, NodeFilter.SHOW_TEXT);
                  for (let n = walker.nextNode(); n; n = walker.nextNode()) for (const ph of phrases) { const i = norm(n.textContent).indexOf(norm(ph)); if (i < 0) continue;
                    const rg = document.createRange(); rg.setStart(n, i); rg.setEnd(n, Math.min(n.textContent.length, i + ph.length));
                    out[ph] = [...rg.getClientRects()].map(b => [Math.round((b.x - r.x) * 2), Math.round((b.y - r.y) * 2), Math.round(b.width * 2), Math.round(b.height * 2)]); }
                  return out; }""", phrases)
                json.dump({'info': info, 'rects': rects, 'w': info['w'] * 2, 'h': info['h'] * 2}, open(os.path.join(adir, aid + '.json'), 'w', encoding='utf-8'), ensure_ascii=False)
                a['file'] = aid + '.png'; a['rects'] = rects
                say(aid, 'rects for', list(rects.keys()))
            except Exception as e:  # noqa: BLE001
                say(aid, 'FAILED', str(e)[:200])
        b.close()
    json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


if __name__ == '__main__':
    main()
