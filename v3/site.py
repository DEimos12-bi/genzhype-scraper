"""THE PAGE ON THREE SCREENS: the story's own page photographed as a PC, a tablet and a phone see it (read only), for
the closing of the video (three devices showing the real page). Writes <work>/site/desktop.jpg, tablet.jpg, phone.jpg
and site.json with their sizes. Where the page cannot be opened (GitHub's machines are refused by the host) nothing is
written and the closing falls back to its plain form; feed.py takes the pictures on the owner's PC and sends them along.
usage: site.py <work folder>"""
import json
import os
import sys

from render import open_browser

SCREENS = {'desktop': (1440, 900, 1, 2300), 'tablet': (820, 1180, 1, 2300), 'phone': (390, 844, 2, 2600)}      # width, height, pixel ratio, how far down the picture goes
HIDE = '[class*=cookie],[class*=consent],[id*=cookie],[id*=consent],[class*=popup],[class*=modal]{display:none!important} iframe{visibility:hidden!important}'


def main(work, log=lambda *a: print(*a, flush=True)):
    out = os.path.join(work, 'site')
    if os.path.isfile(os.path.join(out, 'site.json')):
        log('site: the three pictures are already there'); return
    url = json.load(open(os.path.join(work, 'material.json'), encoding='utf-8'))['url']
    os.makedirs(out, exist_ok=True)
    browser, pw = open_browser()
    sizes = {}
    try:
        for name, (w, h, ratio, far) in SCREENS.items():
            page = browser.new_context(viewport={'width': w, 'height': h}, device_scale_factor=ratio, locale='en-US').new_page()
            page.goto(url, wait_until='domcontentloaded', timeout=40000)
            page.wait_for_timeout(1800)
            page.add_style_tag(content=HIDE)
            page.evaluate('window.scrollTo(0, 1600)'); page.wait_for_timeout(500)          # lets pictures further down load
            page.evaluate('window.scrollTo(0, 0)'); page.wait_for_timeout(400)
            tall = min(far, page.evaluate('document.documentElement.scrollHeight'))
            page.screenshot(path=os.path.join(out, name + '.jpg'), type='jpeg', quality=84, full_page=True, clip={'x': 0, 'y': 0, 'width': w, 'height': tall})
            sizes[name] = {'w': w * ratio, 'h': tall * ratio}
            page.context.close()
        json.dump(sizes, open(os.path.join(out, 'site.json'), 'w', encoding='utf-8'))
        log('site: the page on a PC, a tablet and a phone (%s)' % ', '.join('%s %dx%d' % (k, v['w'], v['h']) for k, v in sizes.items()))
    except Exception as e:  # noqa: BLE001
        log('site: the page could not be photographed (%s); the closing uses its plain form' % str(e)[:100])
    finally:
        browser.close()
        if pw:
            pw.stop()


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
