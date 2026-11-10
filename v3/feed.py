"""Hands one story to the GitHub maker from the owner's PC (GitHub's machines cannot open genzhype.com themselves).
Reads the public page, writes <id>/material.json on the `v3-feed` branch and touches .social/v3-feed/<id>.json on main,
which starts the v3-maker workflow for that story. The id is the last part of the page address.
usage: feed.py <page url>          (run inside a clone of the repository; uses the git and GitHub login already set up)"""
import json
import os
import subprocess
import sys
import tempfile
import time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import material  # noqa: E402


def git(*a, cwd=REPO, check=True):
    return subprocess.run(['git'] + list(a), cwd=cwd, check=check, capture_output=True, text=True).stdout.strip()


def main(url):
    sid = url.rstrip('/').split('/')[-1][:70]
    mat = material.from_url(url)
    origin = git('remote', 'get-url', 'origin')
    with tempfile.TemporaryDirectory() as tmp:
        have = subprocess.run(['git', 'clone', '-q', '--depth', '1', '--branch', 'v3-feed', origin, tmp], capture_output=True).returncode == 0
        if not have:                                           # first story ever: the branch starts empty
            git('init', '-q', cwd=tmp); git('checkout', '-q', '-b', 'v3-feed', cwd=tmp); git('remote', 'add', 'origin', origin, cwd=tmp)
        os.makedirs(os.path.join(tmp, sid), exist_ok=True)
        json.dump(mat, open(os.path.join(tmp, sid, 'material.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        import make                                             # the page on three screens, taken here: GitHub cannot open the site
        subprocess.run([make.browser_python(), os.path.join(HERE, 'site.py'), os.path.join(tmp, sid)], timeout=300)
        git('add', '-A', cwd=tmp)
        git('-c', 'user.name=genzhype-feed', '-c', 'user.email=feed@genzhype.local', 'commit', '-q', '-m', 'v3 feed: ' + sid, cwd=tmp, check=False)
        git('push', '-q', 'origin', 'v3-feed', cwd=tmp)
    print('feed: %s -> v3-feed/%s/material.json (%d posts, %d outlet texts)' % (mat['title'][:60], sid, len(mat['posts']), len(mat['sources'])))
    r = subprocess.run(['gh', 'workflow', 'run', 'v3-maker.yml', '-f', 'pages=' + sid], cwd=REPO, capture_output=True, text=True)
    print('workflow started for %s' % sid if r.returncode == 0 else 'the feed is pushed; start "v3-maker" by hand (gh said: %s)' % (r.stderr or r.stdout).strip()[:160])
    return sid


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
