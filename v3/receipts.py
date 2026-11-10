"""THE RECEIPTS: each post the plan shows as proof is photographed from X's own public embed page (read only), with
the exact boxes of the words to highlight. Writes receipts/postN.png + postN.json {w, h, clip, boxes}.
A post that cannot be photographed is taken out of the plan (the line keeps its footage and its other graphics).
usage: receipts.py <work folder>"""
import json
import os
import sys

from render import open_browser

JS = """(phrases) => {
  const art = document.querySelector('article'), R = art.getBoundingClientRect();
  const walker = document.createTreeWalker(art, NodeFilter.SHOW_TEXT); const nodes = []; let n; let text = '';
  while ((n = walker.nextNode())) { nodes.push([n, text.length]); text += n.nodeValue; }
  const at = i => { for (let k = nodes.length - 1; k >= 0; k--) if (nodes[k][1] <= i) return [nodes[k][0], i - nodes[k][1]]; };
  const out = {};
  for (const ph of phrases) { const i = text.indexOf(ph); if (i < 0) { out[ph] = null; continue; }
    const r = document.createRange(); const a = at(i), z = at(i + ph.length - 1); r.setStart(a[0], a[1]); r.setEnd(z[0], z[1] + 1);
    out[ph] = [...r.getClientRects()].map(q => [q.x - R.x, q.y - R.y, q.width, q.height]).filter(q => q[2] > 2); }
  const media = art.querySelector('img[src*="pbs.twimg.com/media"], img[src*="video_thumb"], video, [data-testid="tweetPhoto"], [data-testid="videoPlayer"]');
  const tt = art.querySelector('[data-testid="tweetText"]');
  let clip = R.height;                                         // the card is cut under the words: its picture or video is footage already
  if (media) clip = Math.max(120, media.getBoundingClientRect().y - R.y - 6); else if (tt) clip = Math.min(R.height, tt.getBoundingClientRect().bottom - R.y + 54);
  return {rect: [R.x, R.y, R.width, R.height], clip, boxes: out}; }"""


ARTICLE_JS = """(phrases) => {
  const root = document.querySelector('article') || document.querySelector('main') || document.body;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT); const nodes = []; let n; let text = '';
  while ((n = walker.nextNode())) { if (n.parentElement && ['SCRIPT','STYLE','NOSCRIPT'].includes(n.parentElement.tagName)) continue; nodes.push([n, text.length]); text += n.nodeValue; }
  const at = i => { for (let k = nodes.length - 1; k >= 0; k--) if (nodes[k][1] <= i) return [nodes[k][0], i - nodes[k][1]]; };
  // the words are looked for whatever the page's spacing, case or quote marks: only letters and digits count, each with its place in the page
  const keep = [], back = []; for (let i = 0; i < text.length; i++) { const c = text[i].toLowerCase(); if (/[a-z0-9]/.test(c)) { keep.push(c); back.push(i); } }
  const bare = keep.join(''), strip = s => s.toLowerCase().replace(/[^a-z0-9]/g, '');
  const out = {}; let top = null;
  for (const ph of phrases) { const want = strip(ph), k = want.length >= 6 ? bare.indexOf(want) : -1; if (k < 0) { out[ph] = null; continue; }
    const i = back[k], j = back[k + want.length - 1];
    const r = document.createRange(); const a = at(i), z = at(j); r.setStart(a[0], a[1]); r.setEnd(z[0], z[1] + 1);
    const rects = [...r.getClientRects()].filter(q => q.width > 2);
    if (rects.length && top === null) top = rects[0].y + window.scrollY;
    out[ph] = rects.map(q => [q.x, q.y + window.scrollY, q.width, q.height]); }
  const h1 = document.querySelector('h1'); const h1y = h1 ? h1.getBoundingClientRect().y + window.scrollY : 0;
  return {top, h1y, boxes: out, height: document.documentElement.scrollHeight}; }"""


def photograph_article(page, url, marks, dest_png, dest_json, log):
    """A phone-width screenshot of the article around the sentence to highlight (the headline when no phrase is found),
    with the boxes of the phrase relative to the shot."""
    page.goto(url, wait_until='domcontentloaded', timeout=45000)
    page.wait_for_timeout(2500)
    page.evaluate("document.querySelectorAll('[class*=cookie],[id*=cookie],[class*=consent],[id*=consent],[class*=newsletter],[class*=popup],[class*=modal]').forEach(e => { if (e !== document.body && e !== document.documentElement && !e.matches('article, main') && !e.querySelector('article, main, h1')) e.remove(); })")      # a banner goes; never the page itself (a site whose <body> carries a class with "modal" in it lost its whole page here)
    info = page.evaluate(ARTICLE_JS, [m for m in marks if m])
    anchor = info['top'] if info['top'] is not None else info['h1y']
    y0 = max(0, int(anchor) - 260)
    hgt = 1000
    page.evaluate('window.scrollTo(0, %d)' % y0)
    page.wait_for_timeout(600)
    info = page.evaluate(ARTICLE_JS, [m for m in marks if m])              # measured again after the scroll (lazy layouts move)
    anchor = info['top'] if info['top'] is not None else info['h1y']
    y0 = max(0, int(anchor) - 260)
    page.evaluate('window.scrollTo(0, %d)' % y0)
    page.wait_for_timeout(400)
    boxes = {ph: ([[b[0], b[1] - y0, b[2], b[3]] for b in bx if 0 <= b[1] - y0 <= hgt] if bx else None) for ph, bx in info['boxes'].items()}
    found = [b for bx in boxes.values() for b in (bx or [])]
    top = 0
    if found:                                                  # THE PROOF IS THE SENTENCE, not the outlet's page: the card is cut to the lines that carry it
        top = int(max(0, min(b[1] for b in found) - 44))
        hgt = int(min(hgt - top, max(b[1] + b[3] for b in found) + 44 - top))
        boxes = {ph: ([[b[0], b[1] - top, b[2], b[3]] for b in bx] if bx else None) for ph, bx in boxes.items()}
    else:
        hgt = 420                                              # the words were not found: the headline alone, not a page of the outlet
    page.screenshot(path=dest_png, clip={'x': 0, 'y': top, 'width': 560, 'height': hgt}, full_page=False)
    json.dump({'w': 560, 'h': hgt, 'clip': hgt, 'boxes': boxes}, open(dest_json, 'w', encoding='utf-8'), ensure_ascii=False)
    return boxes


def photograph_reddit(page, post, marks, dest_png, dest_json):
    """The post as Reddit's embed shows it, from its header down to the end of its title (the player under it is left
    out: the clip itself plays in the video), with the boxes of the words to highlight."""
    sub, pid = post['handle'].split('/', 1)[1], post['id']
    page.goto('https://embed.reddit.com/r/%s/comments/%s/_/?embed=true&ref_source=embed&ref=share&showmedia=true&theme=light' % (sub, pid), wait_until='domcontentloaded', timeout=45000)
    page.wait_for_timeout(3000)
    head = post['text'][:38]
    info = page.evaluate(ARTICLE_JS, [head] + [m for m in marks if m])
    h1 = page.evaluate("(() => { const h = document.querySelector('h1'); if (!h) return null; const r = h.getBoundingClientRect(); return r.bottom + window.scrollY; })()")
    bottom = int(min(900, max(120, (h1 or 150) + 16)))
    page.evaluate('window.scrollTo(0, 0)')
    page.screenshot(path=dest_png, clip={'x': 0, 'y': 0, 'width': 560, 'height': bottom})
    boxes = {ph: ([b for b in bx if b[1] < bottom] if bx else None) for ph, bx in info['boxes'].items() if ph != head or ph in marks}
    json.dump({'w': 560, 'h': bottom, 'clip': bottom, 'boxes': boxes}, open(dest_json, 'w', encoding='utf-8'), ensure_ascii=False)
    return boxes


def looks_empty(png, meta):
    """Whether a receipt's screenshot shows nothing to read: mostly one dark or flat colour (a video player that had not
    loaded, a blocked page), or no contrast where the highlighted words should be. Such a picture is not a proof."""
    try:
        from PIL import Image
        im = Image.open(png).convert('L')
    except Exception:  # noqa: BLE001
        return True
    import statistics
    px = list(im.resize((80, max(1, int(80 * im.height / max(1, im.width))))).getdata())
    if not px or statistics.pstdev(px) < 14 or sum(1 for v in px if v < 40) / len(px) > 0.75:
        return True
    sx = im.width / max(1, float(meta.get('w') or im.width))
    spread = []
    for rects in (meta.get('boxes') or {}).values():
        for x, y, w, h in rects or []:
            if w < 40:                                         # the end of a line (a space, a dot): too small to judge
                continue
            box = im.crop((int(x * sx), int(y * sx), int((x + w) * sx), int((y + h) * sx)))
            if box.width > 4 and box.height > 4:
                spread.append(statistics.pstdev(list(box.getdata())))
    return bool(spread) and max(spread) < 18                   # where the words should be, one flat colour everywhere: they are not on the picture


def main(work, log=lambda *a: print(*a, flush=True)):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    mat = json.load(open(os.path.join(work, 'material.json'), encoding='utf-8'))
    want = {}
    for ln in plan['lines']:
        for o in ln['overlays']:
            if o['k'] == 'receipt':
                want.setdefault(o['post'], set()).add(o.get('mark') or '')
    if not want:
        log('receipts: the plan shows no post'); return
    rdir = os.path.join(work, 'receipts'); os.makedirs(rdir, exist_ok=True)
    browser, pw = open_browser()
    page = browser.new_context(viewport={'width': 560, 'height': 1500}, device_scale_factor=2, locale='en-US').new_page()
    failed = set()
    for name, marks in want.items():
        if name.startswith('source'):                          # an outlet's article, the sentence highlighted
            src = (mat.get('sources') or [])[int(name[6:])]
            try:
                boxes = photograph_article(page, src['url'], marks, os.path.join(rdir, name + '.png'), os.path.join(rdir, name + '.json'), log)
                log('receipt %s (%s): article, words found: %s' % (name, src.get('publisher', '?'), ', '.join('yes' if v else 'no' for v in boxes.values()) or 'none asked'))
            except Exception as e:  # noqa: BLE001
                failed.add(name); log('receipt %s: the article could not be photographed (%s)' % (name, str(e)[:90]))
            continue
        post = mat['posts'][int(name[4:])]
        if post.get('platform') == 'reddit':                   # a Reddit post: Reddit's own embed, cut under its title
            try:
                found = photograph_reddit(page, post, marks, os.path.join(rdir, name + '.png'), os.path.join(rdir, name + '.json'))
                log('receipt %s (%s): the Reddit post, words found: %s' % (name, post['handle'], ', '.join('yes' if v else 'no' for v in found.values()) or 'none asked'))
            except Exception as e:  # noqa: BLE001
                failed.add(name); log('receipt %s: the Reddit post could not be photographed (%s)' % (name, str(e)[:90]))
            continue
        try:
            page.goto('https://platform.twitter.com/embed/Tweet.html?id=%s&theme=light&lang=en&dnt=true&hideThread=true' % post['id'], wait_until='domcontentloaded', timeout=45000)
            page.wait_for_selector('article', timeout=25000)
            page.wait_for_timeout(2500)
            info = page.evaluate(JS, [m for m in marks if m])
            x, y, w, h = info['rect']
            page.screenshot(path=os.path.join(rdir, name + '.png'), clip={'x': x, 'y': y, 'width': w, 'height': h})
            json.dump({'w': w, 'h': h, 'clip': info['clip'], 'boxes': info['boxes']}, open(os.path.join(rdir, name + '.json'), 'w', encoding='utf-8'), ensure_ascii=False)
            log('receipt %s (%s): %dx%d, words found: %s' % (name, post['handle'], w, h, ', '.join('yes' if v else 'no' for v in info['boxes'].values()) or 'none asked'))
        except Exception as e:  # noqa: BLE001
            failed.add(name); log('receipt %s: could not be photographed (%s)' % (name, str(e)[:90]))
    browser.close()
    if pw:
        pw.stop()
    for name in want:                                          # every screenshot is looked at before it counts as a proof
        png, js = os.path.join(rdir, name + '.png'), os.path.join(rdir, name + '.json')
        if name not in failed and os.path.isfile(png) and os.path.isfile(js) and looks_empty(png, json.load(open(js, encoding='utf-8'))):
            failed.add(name)
            for f in (png, js):
                os.remove(f)
            log('receipt %s: the screenshot shows nothing to read (dark, empty or without the words): not shown' % name)
    if failed:
        for ln in plan['lines']:
            ln['overlays'] = [o for o in ln['overlays'] if not (o['k'] == 'receipt' and o['post'] in failed)]
        json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
