"""A PAGE THAT MOVES. A story about something on the web (an announcement on a company's own site, a status page, a
product page) had only stills to show: a logo, a screenshot. The page itself is footage. Opened on a phone-sized
screen and scrolled slowly, picture by picture, it gives a real moving clip of the subject's OWN page, upright, that
fills the screen. Only the pages marked as the subject's own (material "sources" with primary, and the researcher's
"official" pages): another publisher's page is never a picture of the story.
Writes <work>/assets/scroll<N>.mp4 and <work>/scroll.json (what was made); gather.py adds them to the material.
Runs in the Python that can open a browser.   usage: scroll.py <work folder> [seconds it may take]"""
import json
import os
import subprocess
import sys
import time

from render import open_browser

W, H, RATIO, FPS, SECONDS, FAR = 540, 960, 2, 30, 12, 4600      # a phone screen, drawn at 1080 x 1920; how far down the page the clip goes at most
HIDE = '[class*=cookie],[class*=consent],[id*=cookie],[id*=consent],[class*=popup],[class*=modal],[class*=banner][class*=cookie]{display:none!important} html{scroll-behavior:auto!important}'


def pages(m):
    """The subject's own pages of a story, the ones that state the claim first: [(url, publisher, title)], two at most."""
    out = []
    for e in m.get('evidence') or []:
        if e.get('kind') == 'official' and e.get('url'):
            out.append((e['url'], e.get('publisher', ''), e.get('title', '')))
    for s in m.get('sources') or []:
        if s.get('primary') and s.get('url'):
            out.append((s['url'], s.get('publisher', ''), s.get('title', '')))
    seen, keep = set(), []
    for url, pub, title in out:
        key = url.split('#')[0].rstrip('/')
        if key not in seen and not key.lower().endswith('.pdf'):
            seen.add(key)
            keep.append((url, pub, title))
    return keep[:2]


def main(work, budget=75.0, log=lambda *a: print(*a, flush=True)):
    t0 = time.time()
    m = json.load(open(os.path.join(work, 'material.json'), encoding='utf-8'))
    state_file = os.path.join(work, 'scroll.json')
    made = json.load(open(state_file, encoding='utf-8')) if os.path.isfile(state_file) else {'clips': [], 'tried': []}
    todo = [p for p in pages(m) if p[0] not in made['tried']]
    if not todo:
        return 0
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    browser, pw = open_browser()
    try:
        for url, pub, title in todo:
            if time.time() - t0 > budget - 45:                # a page takes most of a minute: not started in what is left
                log('scroll: no time left in this call for %s; run again' % pub)
                return 3
            n = len(made['clips']) + 1
            frames = os.path.join(work, 'scroll%d' % n); os.makedirs(frames, exist_ok=True)
            made['tried'].append(url)
            try:
                page = browser.new_context(viewport={'width': W, 'height': H}, device_scale_factor=RATIO, locale='en-US').new_page()
                page.goto(url, wait_until='domcontentloaded', timeout=40000)
                page.wait_for_timeout(2200)
                page.add_style_tag(content=HIDE)
                tall = page.evaluate("""(() => { const all = document.documentElement.scrollHeight, m = document.querySelector('article, main, [role=main]');
                    const end = m ? m.getBoundingClientRect().bottom + window.scrollY + 60 : 0;      // the clip stops where the page's own text ends, not in its footer
                    return end > 900 ? Math.min(all, end) : all; })()""")
                page.evaluate('window.scrollTo(0, %d)' % min(tall, 1800)); page.wait_for_timeout(500)      # lets pictures further down load
                page.evaluate('window.scrollTo(0, 0)'); page.wait_for_timeout(400)
                far = min(FAR, max(0, tall - H))
                if far < 500:                                  # a page with nothing under its first screen is a still, not a clip
                    log('scroll: %s is one screen long: no clip (%d px to scroll)' % (pub, far))
                    page.context.close()
                    continue
                count = SECONDS * FPS
                for i in range(count):
                    p = i / (count - 1)
                    y = far * (p * p * (3 - 2 * p))            # starts and ends gently
                    page.evaluate('window.scrollTo(0, %.1f)' % y)
                    page.screenshot(path=os.path.join(frames, '%04d.jpg' % (i + 1)), type='jpeg', quality=82)
                page.context.close()
            except Exception as e:  # noqa: BLE001
                log('scroll: %s could not be filmed (%s)' % (pub, str(e)[:90]))
                json.dump(made, open(state_file, 'w', encoding='utf-8'), ensure_ascii=False)
                continue
            name = 'scroll%d.mp4' % n
            r = subprocess.run(['ffmpeg', '-y', '-v', 'error', '-framerate', str(FPS), '-i', os.path.join(frames, '%04d.jpg'), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '20', '-preset', 'veryfast',
                                os.path.join(adir, name)], capture_output=True, timeout=120)
            for f in os.listdir(frames):
                os.remove(os.path.join(frames, f))
            os.rmdir(frames)
            if r.returncode == 0 and os.path.isfile(os.path.join(adir, name)):
                made['clips'].append({'file': name, 'url': url, 'publisher': pub, 'title': title, 'w': W * RATIO, 'h': H * RATIO, 'dur': float(SECONDS), 'far': far})
                log('scroll: the page of %s filmed on a phone screen, %d s down %d px ("%s")' % (pub, SECONDS, far, title[:60]))
            json.dump(made, open(state_file, 'w', encoding='utf-8'), ensure_ascii=False)
    finally:
        json.dump(made, open(state_file, 'w', encoding='utf-8'), ensure_ascii=False)
        browser.close()
        if pw:
            pw.stop()
    return 0


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main(sys.argv[1], float(sys.argv[2]) if len(sys.argv) > 2 else 75.0))
