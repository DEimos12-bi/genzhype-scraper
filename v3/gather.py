"""GATHER: everything the page already gives is fetched AND looked at before a word of the script is written. That is
the order a person cuts these videos in: read, then gather and look at all the material, then write knowing what can
be shown. Before this step the system wrote first and fetched the posts' clips after, so the writer and the checks
were blind to them.
  1. the videos and pictures of the story's own posts (X's media servers)
  2. the page's own pictures
  3. a meme's examples (GIFs, TikTok videos by the site's route, covers), see memes.py
  4. a look at every piece: stills and examples one by one (memes.look), the posts' clips on a contact sheet (eyes.py)
Everything lands in material.json["assets"] (the same ids the director uses) and in <work>/assets. The step can be
stopped at a time budget and run again: what is fetched and looked at is kept.  usage: gather.py <work> [seconds]"""
import json
import os
import sys
import time

import eyes
import footage
import memes


def main(work, budget=None, log=lambda *a: print(*a, flush=True)):
    t0 = time.time()
    mfile = os.path.join(work, 'material.json')
    m = json.load(open(mfile, encoding='utf-8'))
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    A = m.setdefault('assets', {})

    def keep():
        json.dump(m, open(mfile, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        if budget and time.time() - t0 > budget:
            log('gather: stopped at the time budget; run again')
            raise SystemExit(3)

    if '/slang/' in m.get('url', ''):                          # a word page is drawn: nothing to fetch
        m['gathered'] = True
        keep()
        log('gather: a slang page is drawn, nothing to fetch')
        return
    for i, p in enumerate(m.get('posts', [])):                 # 1. the posts' own media
        for md in p.get('media', []):
            if md['type'] == 'video' and md.get('video') and 'clip%d' % i not in A:
                A['clip%d' % i] = {'kind': 'clip', 'post': i, 'url': md['video'], 'seconds': md.get('seconds'), 'credit': 'CLIP · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
            elif md['type'] == 'photo' and md.get('image') and 'photo%d' % i not in A:
                A['photo%d' % i] = {'kind': 'photo', 'post': i, 'url': md['image'], 'credit': 'IMAGE · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
    for i, im in enumerate(m.get('images') or []):             # 2. the page's own pictures
        if m.get('examples') and '/covers/' not in im['url']:  # a meme page's small pictures are copies of its examples, fetched themselves below
            continue
        A.setdefault('page%d' % i, {'kind': 'photo', 'url': im['url'], 'credit': '', 'about': im.get('alt', ''), 'by': 'the page', 'page': m.get('url', '')})
    if m.get('examples') and not m.get('examples_collected'):  # 3. a meme's examples
        A.update(memes.collect(m, work, log))
        m['examples_collected'] = True
        keep()
    for aid, a in A.items():                                   # the downloads
        if a.get('file') or a.get('missing') or a.get('whole') or a['kind'] not in ('clip', 'photo'):
            continue
        name = aid + ('.mp4' if a['kind'] == 'clip' else '.jpg')
        try:
            footage.save(a['url'] + ('?name=orig' if a['kind'] == 'photo' and 'twimg.com' in a['url'] and '?' not in a['url'] else ''), os.path.join(adir, name))
            a['file'] = name
            log('%s: fetched from %s' % (aid, a.get('by', '?') if a.get('by') == 'the page' else 'the post of ' + a.get('by', '?')))
        except Exception as e:  # noqa: BLE001
            a['missing'] = str(e)[:80]
            log('%s: not fetched (%s)' % (aid, a['missing']))
        keep()
    for aid, a in A.items():                                   # sizes and lengths
        if a.get('file') and 'w' not in a:
            a['w'], a['h'], a['dur'] = footage.probe(os.path.join(adir, a['file'])) if a['kind'] != 'photo' else footage.probe(os.path.join(adir, a['file']))[:2] + (0.0,)
            if not a['w']:
                a.pop('file'); a['missing'] = 'unreadable file'
    # 4. the look: every still and every example one by one; the posts' clips on a contact sheet. A real person in a
    # meme's example is a stranger made into a joke and is left out; in a story's own post it is the story's subject.
    stills = {k: a for k, a in A.items() if a.get('file') and (a.get('whole') or a['kind'] == 'photo')}
    if any('looked' not in a for a in stills.values()):
        memes.look(stills, m, work, log, keep, strangers=lambda a: bool(a.get('whole')))
    if m.get('examples') and not m.get('sorted'):
        memes.sort_topic({k: a for k, a in A.items() if a.get('whole')}, m, log)
        m['sorted'] = True
        keep()
    clips = {k for k, a in A.items() if a.get('file') and a['kind'] == 'clip' and not a.get('whole') and not a.get('eyes')}
    if clips:
        eyes.look_assets(A, m.get('title', ''), 'meme' if m.get('examples') else 'story', work, log, save=keep, only=clips)
    m['gathered'] = True
    keep()
    have = [k for k, a in A.items() if a.get('file') and a.get('usable', True)]
    log('gather: %d pieces fetched and looked at, %d usable (%s)' % (sum(1 for a in A.values() if a.get('file')), len(have), ', '.join(have) or 'none'))


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    main(sys.argv[1], float(sys.argv[2]) if len(sys.argv) > 2 else None)
