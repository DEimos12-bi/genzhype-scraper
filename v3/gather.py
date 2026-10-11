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

import ears
import eyes
import footage
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
    if m.get('game') and not m.get('trailer_tried'):           # 2c. the game the story is about: its official trailer (its Steam page), material like a post's clip
        m['trailer_tried'] = True
        st = footage.steam_trailer(m['game'], os.path.join(adir, 'trailer.mp4'), 75, log)
        if st:
            clean = re.sub(r'[^A-Za-z0-9 :&\-\.]', '', st['name']).strip()
            A['trailer'] = {'kind': 'clip', 'file': 'trailer.mp4', 'credit': 'TRAILER · %s / STEAM' % clean.upper()[:30], 'about': '%s, the game itself: its official trailer "%s" from its Steam page' % (clean, st['trailer']),
                            'by': clean, 'page': st['page'], 'game': True}
            log('trailer: the official trailer "%s" of %s, from its Steam page' % (st['trailer'][:50], clean))
        else:
            log('trailer: no trailer of "%s" on Steam' % m['game'])
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
