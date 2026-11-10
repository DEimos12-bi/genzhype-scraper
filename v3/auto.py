"""THE DAILY RUN: picks the newest stories of the site by itself and makes their videos, one after the other.
Reads the site's news sitemap, skips every story already tried (made or refused: work/auto_state.json), makes up to
--max videos, and puts each finished one in its own folder under --out (mp4, cover.jpg, post.txt, report.json).
A story the gate refuses (too little to show, sensitive, script too short) is noted with its reason and not tried again.
    python auto.py                    2 newest untried stories -> work/_videos/
    python auto.py --max 3 --out D:/videos
    python auto.py --list             what it would pick, nothing made
Put this one command in the PC's scheduler to have videos made every day with nobody starting it. Posts nothing."""
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import ai  # noqa: E402

SITEMAP = 'https://%s/news-sitemap.xml' % ai.CONFIG['brand']['site']
LANES = ai.CONFIG.get('auto', {}).get('lanes', ['gaming', 'drama'])
STATE = os.path.join(HERE, 'work', 'auto_state.json')
HEAVY = ('base', 'segments', 'frames', 'takes', 'eyes', 'assets', 'fonts', 'stills')


def newest():
    req = urllib.request.Request(SITEMAP, headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0 Safari/537.36'})
    xml = urllib.request.urlopen(req, timeout=30).read().decode('utf-8', 'replace')
    out = []
    for blk in re.findall(r'(?s)<url>(.*?)</url>', xml):
        loc = re.search(r'<loc>([^<]+)</loc>', blk)
        when = re.search(r'<news:publication_date>([^<]+)<', blk)
        if loc and loc.group(1).rstrip('/').count('/') == 4 and loc.group(1).split('/')[3] in LANES:
            out.append((when.group(1) if when else '', loc.group(1)))
    return [u for _, u in sorted(out, reverse=True)]


def main():
    args = sys.argv[1:]
    opt = lambda name, d=None: args[args.index(name) + 1] if name in args else d
    limit, outdir = int(opt('--max', 2)), os.path.abspath(opt('--out', os.path.join(HERE, 'work', '_videos')))
    os.makedirs(os.path.dirname(STATE), exist_ok=True)
    state = json.load(open(STATE, encoding='utf-8')) if os.path.isfile(STATE) else {}
    todo = [u for u in newest() if u.rstrip('/').split('/')[-1] not in state]
    print('%d stories on the sitemap not tried yet; making up to %d' % (len(todo), limit), flush=True)
    if '--list' in args:
        print('\n'.join(todo[:12])); return
    made = 0
    for url in todo:
        if made >= limit:
            break
        slug = url.rstrip('/').split('/')[-1]
        work = os.path.join(HERE, 'work', slug[:60])
        print('=== %s' % url, flush=True)
        rc = subprocess.run([sys.executable, os.path.join(HERE, 'make.py'), work, '--url', url]).returncode
        rep = os.path.join(work, 'out', 'report.json')
        report = json.load(open(rep, encoding='utf-8')) if os.path.isfile(rep) else {'made': False, 'why': 'stopped with exit code %d' % rc}
        state[slug] = {'when': time.strftime('%Y-%m-%d %H:%M'), 'made': bool(report.get('made')), 'why': report.get('why', ''), 'url': url}
        if report.get('made'):
            dest = os.path.join(outdir, '%s-%s' % (time.strftime('%Y%m%d'), slug[:60]))
            shutil.copytree(os.path.join(work, 'out'), dest, dirs_exist_ok=True)
            made += 1
            print('made -> %s' % dest, flush=True)
        else:
            print('no video: %s' % report.get('why'), flush=True)
        for d in HEAVY:                                         # the working files are large; the result is kept
            shutil.rmtree(os.path.join(work, d), ignore_errors=True)
        json.dump(state, open(STATE, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('done: %d made this run' % made, flush=True)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main()
