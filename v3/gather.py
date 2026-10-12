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
import re
import sys
import time

import ai
import ears
import eyes
import footage
import investigate
import memes
import people
import stills as cutstills      # (a local list below is called stills)
import supply

def luma(path):
    """How light a clip is: the mean brightness of a picture every two seconds (0 black, 255 white). None when unreadable."""
    rc, out, err = footage.run(['ffmpeg', '-v', 'error', '-i', path, '-t', '40', '-vf', 'fps=1/2,scale=96:54,signalstats,metadata=print:key=lavfi.signalstats.YAVG:file=-', '-f', 'null', '-'], 60)
    vals = [float(x) for x in re.findall(r'YAVG=([\d.]+)', (out or '') + (err or ''))]
    return round(sum(vals) / len(vals), 1) if vals else None


OURS = re.compile(r'genzhype|genz hype|drama desk|the timeline|the receipts|not the gossip|live desk', re.I)
PUBLISHER = re.compile(r'news|daily|drama|alert|update|report|media|press|times|buzz|gossip|shade|tea\b|dexerto|tmz|insider|esports', re.I)      # an account that is itself a publisher: its video is not ours to show


def call_left():
    """Seconds before the caller cuts this command (make.py --limit); None when there is no limit."""
    end = os.environ.get('V3_CALL_END', '')
    return float(end) - time.time() if end else None


def subjects(m):
    """Who or what a story is ABOUT, as the names are written (about_of, or the people of a topic's brief)."""
    return [x for x in m.get('subjects') or [] if x]


def logins(m):
    """The channel names to try for a story's people, [(login, the person)]: a post author's handle when the title names
    them; a name of two words or more written as one ("Kai Cenat" -> kaicenat); a one-word name written like a handle
    (SuperBrine). A plain first name is nobody's channel for certain, and the story's game is not a person."""
    bare = lambda t: re.sub(r'[^a-z0-9_]', '', (t or '').lower())
    title, game, out = investigate.squash(m.get('title', '')), investigate.squash(m.get('game') or ''), {}
    for p in m.get('posts') or []:
        h = bare(str(p.get('handle', '')))
        if p.get('platform', 'x') in ('x', 'tiktok') and len(h) >= 5 and (h.replace('_', '')[:7] in title or any(len(w) >= 5 and w in title for w in investigate.flat(p.get('name', '')).split())):
            out.setdefault(h, re.sub(r'[^A-Za-z0-9 ._-]', '', p.get('name') or '').strip() or p['handle'])
    for nm in subjects(m):
        s = bare(nm.replace(' ', ''))
        if game and s in game:
            continue
        if 6 <= len(s) <= 25 and (' ' in nm.strip() or re.search(r'[a-z][A-Z]|\d', nm)):
            out.setdefault(s, nm)
    return list(out.items())[:3]


def about_of(m, log):
    """(the one video game a story is about, as its store page writes it, or ''; the people, teams or companies it is
    about, as the story writes them). One small question to a light model; every name must be in the story's own words."""
    text = m.get('title', '') + '. ' + re.sub(r'\s+', ' ', m.get('page_text', ''))[:1800]
    try:
        j, _ = ai.ask_json('You read a short news story and answer two questions about it. Strict JSON only.',
                           'THE STORY: %s\n\n1. "game": is ONE video game at the centre of this story (it is played, patched, released or competed in)? Its name as its store page writes it ("TEKKEN 8", "Fortnite"), '
                           'or "" when the story is about no video game, or about several.\n2. "people": the streamers, creators, players, teams or companies this story is ABOUT, at most 4, each exactly as the story writes the name. '
                           'Not journalists, not publications, not the game itself.\nJSON: {"game":"","people":[""]}' % text, kind='light', temperature=0.1, timeout=40, max_tokens=400)
    except ai.OutOfTime:
        raise
    except Exception:  # noqa: BLE001
        return '', []
    hay = ' %s ' % investigate.flat(text)
    told = lambda name: bool(investigate.flat(name)) and all(' %s ' % w in hay for w in investigate.flat(name).split() if w not in ('the', 'of', 'and', 'a'))
    game = re.sub(r'\s+', ' ', str((j or {}).get('game') or '')).strip()[:60]
    game = game if told(game) else ''
    people = [re.sub(r'\s+', ' ', str(x)).strip()[:40] for x in (j or {}).get('people') or [] if isinstance(x, str)]
    people = list(dict.fromkeys(x for x in people if told(x) and investigate.squash(x) != investigate.squash(game)))[:4]
    log('gather: the story is about %s%s' % (', '.join(people) or 'nobody the page names', (' and the game "%s": its own videos and screenshots are material' % game) if game else ''))
    return game, people


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

    def room(seconds, what):
        left = call_left()
        if left is not None and left < seconds:               # a long fetch is not started in what is left of this call
            json.dump(m, open(mfile, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
            log('gather: %d s of this call are left and %s needs about %d; run again' % (left, what, seconds))
            raise SystemExit(3)

    if '/slang/' in m.get('url', ''):                          # a word page is drawn: nothing to fetch
        m['gathered'] = True
        keep()
        log('gather: a slang page is drawn, nothing to fetch')
        return
    for i, p in enumerate(m.get('posts', [])):                 # 1. the posts' own media
        for md in p.get('media', []):
            if md['type'] == 'video' and md.get('video') and 'clip%d' % i not in A:
                where = 'VIA %s' % p['handle'].upper() if p.get('platform') == 'reddit' else '%s / %s' % (p['handle'].upper(), 'TIKTOK' if p.get('platform') == 'tiktok' else 'X')
                A['clip%d' % i] = {'kind': 'clip', 'post': i, 'url': md['video'], 'seconds': md.get('seconds'), 'credit': 'CLIP · ' + where, 'about': p['text'][:240], 'by': p['handle'], 'page': p['url'],
                                   **({'via': 'supply'} if md.get('via') == 'supply' else {})}
            elif md['type'] == 'photo' and md.get('image') and 'photo%d' % i not in A:
                A['photo%d' % i] = {'kind': 'photo', 'post': i, 'url': md['image'], 'credit': 'IMAGE · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
    for i, e in enumerate(m.get('events') or []):              # 2a. the site's own screenshots of the sources (the page's proofs)
        src = (e.get('source') or '').strip()
        if e.get('proof') and '.' in src and ' ' not in src and not re.search(r'(?:x|twitter|reddit|tiktok|youtube|twitch|kick|instagram)\.', src, re.I):
            if not m.get('outlet_shots_said'):
                log('gather: the page\'s screenshot of %s is another publisher\'s page: it is not a picture of this story and is left out (the subject\'s own posts, clips and pages are)' % src)
            m['outlet_shots_said'] = True
            continue
        if e.get('proof') and 'proof%d' % i not in A:
            A['proof%d' % i] = {'kind': 'photo', 'url': e['proof'], 'credit': ('SCREENSHOT · %s' % (e.get('source') or 'the source')).upper()[:40], 'by': e.get('source') or 'the source',
                                'about': e.get('proof_caption') or ('screenshot of %s: %s' % (e.get('source', 'the source'), e.get('title', ''))), 'page': m.get('url', ''), 'proof_of': i, 'date': e.get('date', '')}
    if not m.get('faces_done'):                                # 2b. the faces of the people (X profile picture, Wikidata, YouTube)
        people.find_faces(m, work, log)
        m['faces_done'] = True
        keep()
    proofs = {e.get('proof', '').split('?')[0] for e in m.get('events') or [] if e.get('proof')}
    for i, im in enumerate(m.get('images') or []):             # 2. the page's own pictures
        if m.get('examples') and '/covers/' not in im['url']:  # a meme page's small pictures are copies of its examples, fetched themselves below
            continue
        if im['url'].split('?')[0] in proofs:                  # a proof screenshot is fetched above, with its event
            continue
        if im.get('own_card'):                                 # the cover the site drew itself (no photo credit): our own card is never a picture of the story
            if 'page%d' % i not in A and not m.get('own_card_said'):
                log('gather: the page\'s cover is our own card (no photo credit): it is not material')
                m['own_card_said'] = True
            continue
        A.setdefault('page%d' % i, {'kind': 'photo', 'url': im['url'], 'credit': '', 'about': im.get('alt', ''), 'by': 'the page', 'page': m.get('url', '')})
        a = A['page%d' % i]
        if im.get('who') and 'face_of' not in a and not a.get('file'):      # the page's photo is a Commons picture of a person of the story: fetched whole (the page shows a crop), named, credited
            names = [w for w in re.findall(r'[a-z0-9]+', im['who'].lower()) if len(w) >= 3]
            if names and all(w in re.sub(r'[^a-z0-9 ]', ' ', (m.get('title', '') + ' ' + m.get('page_text', '')).lower()).split() for w in names):
                full = people.commons_file(im['commons'])
                a.update(face_of=im['who'], person=im['who'], by='Wikimedia Commons', about='%s, a photo by %s' % (im['who'], im.get('credit', '?')), credit=('PHOTO · ' + im.get('credit', 'WIKIMEDIA COMMONS')).upper()[:48], **({'url': full} if full else {}))
                log("  page%d: the page's photo is %s (Wikimedia Commons, %s)%s" % (i, im['who'], im.get('credit', '?'), ': the whole picture is fetched' if full else ''))
    if 'subjects' not in m and not m.get('examples'):          # 2c. who and which game the story is about (a topic's brief says it; a page is asked)
        brief = [str(x.get('name') if isinstance(x, dict) else x).strip() for x in m.get('people') or []]
        if 'game' in m and brief:
            m['subjects'] = brief
        else:
            game, m['subjects'] = about_of(m, log)
            m.setdefault('game', game)
        keep()
    if m.get('game') and not m.get('steam_done'):              # ...and its own store page: two official videos, four official screenshots
        app = m.get('steam') or footage.steam_app(m['game'], log)
        if app:
            m['steam'] = app
            clean = re.sub(r'[^A-Za-z0-9 :&\-\.]', '', app['name']).strip()
            for n, mv in enumerate(app['movies'][:2]):
                aid = 'trailer' if n == 0 else 'trailer%d' % (n + 1)
                if aid in A:
                    continue
                room(55, 'a trailer')
                got = footage.steam_passages(mv, os.path.join(adir, aid + '.mp4'), log)
                if got:
                    A[aid] = {'kind': 'clip', 'file': aid + '.mp4', 'credit': 'TRAILER · %s / STEAM' % clean.upper()[:30], 'by': clean, 'page': app['page'], 'game': True, 'group': 'game',
                              'about': '%s, the game itself: %d passages taken across its official video "%s" (%d s long) from its Steam page' % (clean, got['passages'], got['name'][:60], got['of'])}
                    log('%s: %d passages across the official video "%s" (%d s) of %s, from its Steam page' % (aid, got['passages'], got['name'][:50], got['of'], clean))
                else:
                    A[aid] = {'kind': 'clip', 'missing': 'the video did not come', 'about': mv.get('name', ''), 'game': True}
                    log('%s: the official video "%s" did not come' % (aid, mv.get('name', '')[:50]))
                keep()
            for n, url in enumerate(app['shots'][::max(1, len(app['shots']) // 4)][:4]):
                A.setdefault('shot%d' % (n + 1), {'kind': 'photo', 'url': url, 'credit': 'SCREENSHOT · %s / STEAM' % clean.upper()[:28], 'by': clean, 'page': app['page'], 'game': True,
                                                  'about': '%s, the game itself: an official screenshot from its Steam page' % clean})
            log('steam: %d official video(s) and %d official screenshot(s) of %s' % (sum(1 for k, v in A.items() if k.startswith('trailer') and v.get('file')), min(4, len(app['shots'])), clean))
        else:
            log('steam: no store page named exactly "%s"' % m['game'])
        m.pop('steam', None)
        m['steam_done'] = True
        keep()
    if not m.get('channels_done') and not m.get('examples'):   # 2d. the people's own channels: their most watched clips of the month
        n, pages = sum(1 for k in A if k.startswith('chan')), {supply.same(v.get('page', '')) for v in A.values()}
        for login, who in logins(m):
            if login in m.setdefault('channels_tried', []) or n >= 3:
                continue
            room(45, 'a channel')
            clips = supply.channel_clips(login)
            watched = len(clips) >= 2 and clips[0]['views'] >= 500      # a real channel of somebody watched, not a namesake's
            if not watched:
                log('channel: twitch.tv/%s has no watched clips this month; nothing is taken' % login)
            for c in ([x for x in clips if 6 <= x['dur'] <= 60 and supply.same(x['url']) not in pages][:2] if watched else []):
                aid = 'chan%d' % (n + 1)
                meta = supply.fetch(c['url'], os.path.join(adir, aid + '.mp4'), keep=30, log=log)
                pages.add(supply.same(c['url']))
                if meta:
                    n += 1
                    A[aid] = {'kind': 'clip', 'file': aid + '.mp4', 'credit': 'CLIP · %s / TWITCH' % login.upper()[:24], 'title': c['title'], 'by': who, 'page': c['url'], 'found': True, 'group': 'chan:' + login,
                              'about': '"%s": one of the most watched clips of %s\'s own Twitch channel this month (%d views). Taken from the channel, not cited by the page: it shows %s live, maybe at another moment than this story\'s' % (c['title'], who, c['views'], who)}
                    log('%s: "%s" (%d views), from twitch.tv/%s' % (aid, c['title'][:50], c['views'], login))
                    keep()
            m['channels_tried'].append(login)
            keep()
        m['channels_done'] = True
        keep()
    if not m.get('hunted') and not m.get('examples'):          # 2e. clips of the story itself, on the platforms this machine can fetch from
        if 'finds' not in m:
            room(50, 'the search for clips')
            m['finds'] = supply.hunt(m.get('title', ''), subjects(m)[:2], have=[v.get('page', '') for v in A.values()] + [p.get('url', '') for p in m.get('posts') or []], maximum=5, max_seconds=45, log=log)
            keep()
        outlets, n = set(investigate.outlets_of(m)), sum(1 for k in A if k.startswith('found'))
        for f in m['finds']:
            if n >= 2 or f['url'] in m.setdefault('finds_tried', []):
                continue
            room(45, 'a clip')
            m['finds_tried'].append(f['url'])
            au = investigate.squash(f.get('author', ''))
            own = [x for x in [investigate.squash(s) for s in subjects(m)] + [investigate.squash(p.get('handle', '')) for p in m.get('posts') or []] if len(x) >= 5]
            if f['platform'] == 'tiktok' and (PUBLISHER.search(f.get('author', '')) or any(o in au for o in outlets) or not any(x in au or au in x for x in own if au)):
                log('found: the TikTok of @%s is somebody else\'s video about the story (a publisher\'s or another creator\'s): not taken' % f.get('author', ''))
                continue
            aid = 'found%d' % (n + 1)
            meta = supply.fetch(f['url'], os.path.join(adir, aid + '.mp4'), keep=30, log=log)
            if meta:
                n += 1
                name = re.sub(r'[^A-Za-z0-9@._-]', '', (meta.get('author') or f.get('author') or '').lstrip('@'))
                who = (meta.get('sub') or f.get('author') or 'reddit') if f['platform'] == 'reddit' else ('@' + name) if name else 'a %s channel' % f['platform']
                title = (meta.get('title') or f['title'])[:140]
                A[aid] = {'kind': 'clip', 'file': aid + '.mp4', 'title': title, 'by': who, 'page': f['url'], 'found': True,
                          'credit': 'CLIP · VIA %s' % who.upper()[:24] if f['platform'] == 'reddit' else ('CLIP · %s / %s' % (who.upper()[:24], f['platform'].upper())) if name else 'CLIP · %s' % f['platform'].upper(),
                          'about': '"%s", posted by %s on %s. Found by searching for this story, not cited by the page' % (title, who, f['platform'])}
                log('%s: "%s" by %s, found on %s' % (aid, title[:60], who, f['platform']))
            keep()
        m['hunted'] = True
        keep()
    if not m.get('scrolled') and not m.get('examples'):        # 2f. the subject's own pages, filmed as they scroll on a phone screen (scroll.py, in the Python that opens a browser)
        import subprocess
        exe = ai.CONFIG.get('local_browser_python', '')
        exe = exe if exe and os.path.isfile(exe) and not os.environ.get('GITHUB_ACTIONS') else sys.executable
        room(62, 'filming a page')
        left = call_left()
        r = subprocess.run([exe, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'scroll.py'), work, '%d' % (min(110, left - 6) if left is not None else 110)],
                           capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=170)
        for line in (r.stdout or '').splitlines():
            if line.startswith('scroll'):
                log(line)
        sfile = os.path.join(work, 'scroll.json')
        for c in (json.load(open(sfile, encoding='utf-8'))['clips'] if os.path.isfile(sfile) else []):
            aid, pub = c['file'][:-4], c['publisher']
            if aid in A:
                continue
            A[aid] = {'kind': 'clip', 'file': c['file'], 'w': c['w'], 'h': c['h'], 'dur': c['dur'], 'luma': None, 'credit': 'PAGE · %s' % pub.upper()[:34], 'by': pub, 'page': c['url'], 'scroll': True, 'stills_cut': True,
                      'about': 'the page itself, filmed as it scrolls on a phone: "%s", on the own site of %s' % (c['title'][:120], pub), 'scenes': [[0.0, c['dur']]], 'usable': True,
                      'eyes': {'kind': 'page', 'relevant': True, 'exact': True, 'shows': 'The page "%s" on %s, scrolling down from its top' % (c['title'][:80], pub), 'model': 'none: a page is what it is'},
                      'windows': [{'t': round(0.8 + k * (c['dur'] - 4.5) / 3, 2), 'score': 3.5, 'x': 0.5, 'face': False, 'what': 'the page, %s' % ('its top' if k == 0 else 'further down')} for k in range(4)]}
            A[aid]['tiles'] = [{'t': w['t'], 'score': w['score'], 'x': 0.5} for w in A[aid]['windows']]
        if r.returncode == 3:
            json.dump(m, open(mfile, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
            log('gather: a page is still to film; run again')
            raise SystemExit(3)
        m['scrolled'] = True
        keep()
    if m.get('examples') and not m.get('examples_collected'):  # 3. a meme's examples
        A.update(memes.collect(m, work, log))
        m['examples_collected'] = True
        keep()
    for aid, a in A.items():                                   # the downloads
        if a.get('file') or a.get('missing') or a.get('whole') or a['kind'] not in ('clip', 'photo'):
            continue
        name = aid + ('.mp4' if a['kind'] == 'clip' else '.jpg')
        try:
            if a.get('via') == 'supply':                       # a post whose video has its own route (Reddit): fetched by it, with what is said in it
                meta = supply.fetch(a['url'], os.path.join(adir, name), 0, 75, log)
                if meta is None:
                    raise RuntimeError(supply.LAST['err'] or 'no route')
                if meta.get('said'):
                    a['said'] = meta['said']
            else:
                footage.save(a['url'] + ('?name=orig' if a['kind'] == 'photo' and 'twimg.com' in a['url'] and '?' not in a['url'] else ''), os.path.join(adir, name))
            a['file'] = name
            log('%s: fetched from %s' % (aid, 'its Steam page' if a.get('game') else a.get('by', '?') if a.get('by') == 'the page' else 'the post of ' + a.get('by', '?')))
        except Exception as e:  # noqa: BLE001
            a['missing'] = str(e)[:80]
            log('%s: not fetched (%s)' % (aid, a['missing']))
        keep()
    for aid, a in A.items():                                   # sizes and lengths
        if a.get('file') and 'w' not in a:
            a['w'], a['h'], a['dur'] = footage.probe(os.path.join(adir, a['file'])) if a['kind'] != 'photo' else footage.probe(os.path.join(adir, a['file']))[:2] + (0.0,)
            if not a['w']:
                a.pop('file'); a['missing'] = 'unreadable file'
            elif a['kind'] == 'clip':
                a['luma'] = luma(os.path.join(adir, a['file']))
                if a['luma'] is not None and a['luma'] < 70:
                    log('  %s: filmed in the dark (brightness %.0f of 255): it is lifted when shown' % (aid, a['luma']))
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
    # THE EARS: what is said in the story's own clips, word by word (a caption track that came with the clip, else the
    # speech model of this machine). A clip where the subject says the claim can speak in the video.
    if not m.get('heard'):
        ears.listen(A, m, work, log, keep)
        m['heard'] = True
        keep()
    # OUR OWN CARDS ARE NEVER MATERIAL: the page's branded cover (our wordmark, "the timeline", "the receipts") is a card
    # about the story, not a picture of it. A video that shows it has nothing to show.
    for k, a in A.items():
        if a.get('kind') == 'photo' and a.get('by') == 'the page' and a.get('usable', True) and OURS.search(a.get('seen', '')):
            a['usable'] = False
            log('  %s: our own cover card, not a picture of the story: not used' % k)
    # STILLS CUT OUT OF THE CLIPS: the best moments, a face, a different scene, each looked at like a picture of its own
    new = cutstills.cut_from_clips(A, work, log)
    if new:
        keep()
        memes.look({k: A[k] for k in new}, m, work, log, keep, strangers=False)
    m['gathered'] = True
    keep()
    have = [k for k, a in A.items() if a.get('file') and a.get('usable', True)]
    log('gather: %d pieces fetched and looked at, %d usable (%s)' % (sum(1 for a in A.values() if a.get('file')), len(have), ', '.join(have) or 'none'))


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    import ai
    try:
        main(sys.argv[1], float(sys.argv[2]) if len(sys.argv) > 2 else None)
    except ai.OutOfTime as e:                                  # the caller's time for this call is up: what is fetched and looked at is kept
        print('gather: %s; run again' % e, flush=True)
        sys.exit(3)
