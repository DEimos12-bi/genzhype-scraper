"""Draws the video picture by picture in a headless browser (comp.html in the work folder).
usage: render.py <work> stills T1 T2 ...   -> stills/sNN.jpg at those seconds
       render.py <work> frames FIRST LAST  -> frames/f00000.jpg ... (LAST not included)
       render.py <work> layout             -> layout.json: where every graphic sits at each moment it is on screen
The browser is CloakBrowser where it is installed (the owner's PC), Playwright's Chromium elsewhere (GitHub)."""
import json
import os
import sys
import time

FPS = 30


def open_browser():
    try:
        from cloakbrowser import launch
        return launch(headless=True), None
    except Exception:  # noqa: BLE001
        from playwright.sync_api import sync_playwright
        pw = sync_playwright().start()
        return pw.chromium.launch(headless=True), pw


def main():
    work, mode = os.path.abspath(sys.argv[1]), sys.argv[2]
    browser, pw = open_browser()
    page = browser.new_context(viewport={'width': 1080, 'height': 1920}, device_scale_factor=1).new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)[:200]))
    page.on('console', lambda m: errors.append('console: ' + m.text[:200]) if m.type == 'error' else None)
    page.goto('file:///' + os.path.join(work, 'comp.html').replace('\\', '/'))
    page.evaluate('window.READY')
    page.wait_for_timeout(250)
    t0 = time.time()
    if mode == 'stills':
        out = os.path.join(work, 'stills'); os.makedirs(out, exist_ok=True)
        for i, t in enumerate(sys.argv[3:]):
            page.evaluate('render(%s)' % t)
            page.screenshot(path=os.path.join(out, 's%02d.jpg' % i), type='jpeg', quality=88)
    elif mode == 'layout':
        end = page.evaluate('window.COMP.end')
        times = set()
        for a, b in page.evaluate('spans()'):
            b = min(b, end)
            for t in (a + 0.5, (a + b) / 2, b - 0.07):
                if a < t < b:
                    times.add(round(t, 2))
        samples = []
        for t in sorted(times):
            page.evaluate('render(%s)' % t)
            samples.append({'t': t, 'rects': page.evaluate('rects()')})
        json.dump(samples, open(os.path.join(work, 'layout.json'), 'w', encoding='utf-8'))
        print('layout: %d moments read' % len(samples), flush=True)
    else:
        out = os.path.join(work, 'frames'); os.makedirs(out, exist_ok=True)
        first, last = int(sys.argv[3]), int(sys.argv[4])
        for f in range(first, last):
            page.evaluate('render(%.5f)' % (f / FPS))
            page.screenshot(path=os.path.join(out, 'f%05d.jpg' % f), type='jpeg', quality=93)
        print('frames %d..%d in %.0fs (%.0f ms each)' % (first, last - 1, time.time() - t0, 1000 * (time.time() - t0) / max(1, last - first)), flush=True)
    bad = page.evaluate('window.ERR || 0')
    print('picture errors: %s | page errors: %s' % (bad, errors[:4] or 'none'), flush=True)
    browser.close()
    if pw:
        pw.stop()
    if errors or bad:
        open(os.path.join(work, 'render_errors.txt'), 'a', encoding='utf-8').write('%s: decode %s, %s\n' % (mode, bad, errors[:6]))
        sys.exit(4)


if __name__ == '__main__':
    main()
