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
        post = mat['posts'][int(name[4:])]
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
    if failed:
        for ln in plan['lines']:
            ln['overlays'] = [o for o in ln['overlays'] if not (o['k'] == 'receipt' and o['post'] in failed)]
        json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
