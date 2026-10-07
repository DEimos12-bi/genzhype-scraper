"""v2 frames: comp.html rendered image by image in headless Chromium (Playwright), the owner's render.py generalised.
usage: render.py <workdir> frames | render.py <workdir> stills T1 T2 ..."""
import os
import sys
import time

from playwright.sync_api import sync_playwright

FPS = 30


def main():
    work = os.path.abspath(sys.argv[1]); mode = sys.argv[2]
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, args=['--no-sandbox', '--disable-gpu'])
        ctx = browser.new_context(viewport={'width': 1080, 'height': 1920}, device_scale_factor=1)
        page = ctx.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)[:200]))
        page.on('console', lambda m: errors.append('console: ' + m.text[:200]) if m.type == 'error' else None)
        page.goto('file://' + os.path.join(work, 'comp.html'))
        page.evaluate('window.READY')
        page.wait_for_timeout(300)
        t0 = time.time()
        if mode == 'stills':
            out = os.path.join(work, 'stills'); os.makedirs(out, exist_ok=True)
            for i, t in enumerate(sys.argv[3:]):
                sid = page.evaluate('render(%s)' % t)
                page.screenshot(path=os.path.join(out, 's%02d.jpg' % i), type='jpeg', quality=90)
                print(i, t, sid, flush=True)
        else:
            out = os.path.join(work, 'frames'); os.makedirs(out, exist_ok=True)
            n = int(page.evaluate('window.COMP.frames'))
            for f in range(n):
                page.evaluate('render(%.5f)' % (f / FPS))
                page.screenshot(path=os.path.join(out, 'f%05d.jpg' % f), type='jpeg', quality=94)
                if f % 300 == 0:
                    print('frame %d/%d %.1fs' % (f, n, time.time() - t0), flush=True)
            print('frames %d in %.1fs (%.0f ms each)' % (n, time.time() - t0, 1000 * (time.time() - t0) / max(1, n)), flush=True)
        print('decode errors:', page.evaluate('window.ERR || 0'), '| page errors:', errors[:5] or 'none', flush=True)
        browser.close()
        if errors:
            open(os.path.join(work, 'render_errors.txt'), 'w').write('\n'.join(errors))


if __name__ == '__main__':
    main()
