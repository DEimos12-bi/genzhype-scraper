"""THE EARS: what is SAID in the story's own clips, word by word, with the second each word is spoken.
A person cutting by hand listens to the clip: when the subject says the claim in their own voice, that moment IS the
video ("his words:" and then him). The system could only look. Now every clip of the story's own posts is heard:
  1. a caption track that came with the clip (Reddit makes one: supply.fetch keeps its words), else
  2. a small speech model on this machine (faster-whisper in the voice's own Python; ears_run.py), first 90 seconds.
Names the ear got wrong ("kosanet" for "Kai Cenat") are set right against the story's own names by a writing model:
only the spelling of a heard word changes, never its time. What comes out is a["said"] = {"by", "lang", "words":
[[word, start, end]], "text"}; the director reads it and may let the clip speak (a "bite").
usage (alone): ears.py <work folder>"""
import json
import os
import re
import subprocess
import sys
import time

import ai

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_S = 90


def voice_python():
    fb = ai.CONFIG.get('voice_fallback') or {}
    py = fb.get('python') or ai.CONFIG.get('voice_python', '')
    return py if py and os.path.isfile(py) else ''


def heard(files, log):
    """{name: {'lang': 'en', 'words': [[w, s, e]]}} for the files the speech model could hear; {} when this machine has none."""
    py = voice_python()
    if not py or not files:
        return {}
    jobs = os.path.join(os.path.dirname(files[0]), 'ears_jobs.json')
    json.dump([{'file': f, 'max_s': MAX_S} for f in files], open(jobs, 'w', encoding='utf-8'))
    try:
        r = subprocess.run([py, os.path.join(HERE, 'ears_run.py'), jobs], capture_output=True, text=True, timeout=600)
    except subprocess.TimeoutExpired:
        log('ears: the speech model did not finish in time; the clips stay unheard')
        return {}
    if r.returncode != 0:
        log('ears: no speech model answered on this machine (%s): the clips stay unheard' % ' '.join((r.stderr or '').split())[-110:])
        return {}
    out = json.load(open(jobs, encoding='utf-8'))
    return {os.path.basename(j['file']): {'lang': j.get('lang', ''), 'words': j.get('words') or []} for j in out}


def name_fix(said, m, log, who=''):
    """The story's names, set right in what was heard. A writing model gets the heard words (numbered) and the story;
    it answers ONLY with the numbers of words that are a misheard name and their right spelling. Times never move."""
    words = said.get('words') or []
    if len(words) < 6 or said.get('named'):
        return
    said['named'] = True
    numbered = ' '.join('%d:%s' % (i, w[0]) for i, w in enumerate(words[:260]))
    names = ', '.join(dict.fromkeys([p for p in (m.get('people') or [])] + re.findall(r'\b[A-Z][A-Za-z0-9]+(?: [A-Z][A-Za-z0-9]+)*\b', m.get('title', ''))))[:400]
    try:
        j, model = ai.ask_json('You correct NAMES in a speech-to-text transcript. Strict JSON only.',
                               'THE STORY: %s\nNAMES IN THE STORY: %s\nWHAT THE PAGE SAYS: %s\n\nWords heard in a clip of this story, each with its number:\n%s\n\n'
                               'Some names may be misheard (a person, a company, a game written as a nonsense word or as two wrong words). List ONLY those: the number of the first word, how many heard words the name covers (1 or 2), '
                               'and the right spelling from the story. Do not touch any other word. Then "ends": the numbers of the words that END a complete thought (a sentence someone could quote on its own; a speaker '
                               'who never stops still has four to eight of them in thirty seconds). JSON: {"fix":[{"at":12,"covers":1,"name":"Kai Cenat"}],"ends":[15,27,42]} (an empty "fix" when nothing is misheard).'
                               % (m.get('title', ''), names, re.sub(r'\s+', ' ', m.get('page_text', ''))[:1200], numbered), kind='text', temperature=0.1, timeout=60, max_tokens=1500)
    except ai.AIError:
        return
    cor = re.sub(r'[^a-z0-9]', '', (m.get('title', '') + ' ' + m.get('page_text', '') + ' ' + ' '.join(m.get('people') or [])).lower())
    ends = sorted({int(e) for e in j.get('ends') or [] if str(e).isdigit() and 0 <= int(e) < len(words)})
    done = []
    for f in sorted([x for x in j.get('fix') or [] if isinstance(x, dict)], key=lambda x: -int(x.get('at', 0)) if str(x.get('at', '')).lstrip('-').isdigit() else 0):
        try:
            at, n, name = int(f['at']), max(1, min(2, int(f.get('covers', 1)))), re.sub(r'\s+', ' ', str(f['name'])).strip()
        except (KeyError, TypeError, ValueError):
            continue
        if not (0 <= at < len(words)) or at + n > len(words) or not name or re.sub(r'[^a-z0-9]', '', name.lower()) not in cor:
            continue                                           # only a name the story really has
        old = ' '.join(w[0] for w in words[at:at + n])
        words[at:at + n] = [[name, words[at][1], words[at + n - 1][2]]]
        ends = sorted({e if e < at else at if e < at + n else e - (n - 1) for e in ends})      # two heard words became one name: the numbers after it move up
        done.append('%s -> %s' % (old, name))
    said['ends'] = ends
    if done:
        log('  ears%s: names set right against the story (%s): %s' % (who, model, '; '.join(reversed(done))[:200]))


def text_of(words):
    return re.sub(r'\s+', ' ', ' '.join(w[0] for w in words)).strip()


JOINS = set("and but so because just while then now that's thats when if or yet still also plus".split())


def split_long(said, log, who=''):
    """Parts longer than a bite (7.5 s) are cut again by a model that sees when each word starts."""
    ws = said.get('words') or []
    long_ = [pt for pt in parts_of(said, rule=False) if pt['e'] - pt['s'] > 7.5]
    if not long_ or said.get('split'):
        return
    said['split'] = True
    rows = '\n\n'.join('STRETCH from word %d to word %d:\n' % (pt['i0'], pt['i1']) + ' '.join('%d:%s@%.1f' % (i, ws[i][0], ws[i][1]) for i in range(pt['i0'], pt['i1'] + 1)) for pt in long_)
    try:
        j, model = ai.ask_json('You cut a transcript into complete thoughts. Strict JSON only.',
                               'Each word below has its number and the second it starts (number:word@second). Cut each stretch into COMPLETE THOUGHTS of 2 to 7 seconds each: a thought is a sentence someone could quote on its own '
                               '("just like when the new phones come out the older ones get slower" is one). A thought never ends on "and", "the", "to", "because". '
                               'Answer with the numbers of the LAST word of every thought. JSON: {"ends":[27,40,52]}\n\n' + rows, kind='text', temperature=0.1, timeout=60, max_tokens=1200)
    except ai.AIError:
        return
    more = {int(e) for e in j.get('ends') or [] if str(e).isdigit() and 0 <= int(e) < len(ws)}
    bad = {e for e in more if re.sub(r'[^a-z]', '', ws[e][0].lower()) in JOINS | {'the', 'to', 'a', 'of', 'in'}}
    said['ends'] = sorted(set(said.get('ends') or []) | (more - bad))
    log('  ears%s: the long stretch was cut into thoughts by %s (%d ends)' % (who, model, len(more - bad)))


def parts_of(said, rule=True):
    """What is said, as complete thoughts: [{'id': 'b1', 'i0', 'i1', 's', 'e', 'text'}]. The ends come from the model that
    read the words (name_fix), else from the full stops the speech model wrote, else from the breaths (a word that starts
    a second or more after the one before). A part under 1.2 s joins the next one."""
    ws = said.get('words') or []
    if not ws:
        return []
    ends = [e for e in said.get('ends') or [] if 0 <= e < len(ws)]
    if len(ends) < 2:
        ends = [i for i, w in enumerate(ws) if re.search(r'[.!?]["”\']?$', w[0])]
    if len(ends) < 2:
        ends = [i for i in range(len(ws) - 1) if ws[i + 1][1] - ws[i][1] >= 1.0]
    ends = sorted(set(ends) | {len(ws) - 1})
    out, i0 = [], 0
    for e in ends:
        if e < i0:
            continue
        if ws[e][2] - ws[i0][1] < 1.2 and e != len(ws) - 1:
            continue                                           # too short to stand alone: it runs on into the next thought
        out.append([i0, e])
        i0 = e + 1
    if len(out) >= 2 and ws[out[-1][1]][2] - ws[out[-1][0]][1] < 1.2:
        last = out.pop()
        out[-1][1] = last[1]
    bare = lambda i: re.sub(r"[^a-z']", '', ws[i][0].lower())
    k = 0
    while rule and k < len(out):                               # still longer than a bite: cut by rule, nearest the middle
        a, b = out[k]
        if ws[b][2] - ws[a][1] <= 7.5:
            k += 1
            continue
        mid, best, bs = (ws[a][1] + ws[b][2]) / 2, None, 1e9
        for i in range(a + 1, b + 1):                          # a cut BEFORE word i
            if ws[i - 1][2] - ws[a][1] < 1.5 or ws[b][2] - ws[i][1] < 1.5 or bare(i - 1) in JOINS | {'the', 'to', 'a', 'of', 'in'}:
                continue
            breath = ws[i][1] - ws[i - 1][1]
            if not (bare(i) in JOINS or (ws[i][0][:1].isupper() and not ws[i - 1][0][:1].isupper()) or breath >= 0.7):
                continue
            score = abs(ws[i][1] - mid) - (0.8 if breath >= 0.7 else 0)
            if score < bs:
                best, bs = i, score
        if best is None:
            k += 1
            continue
        out[k:k + 1] = [[a, best - 1], [best, b]]
    return [{'id': 'b%d' % (k + 1), 'i0': a, 'i1': b, 's': ws[a][1], 'e': ws[b][2], 'text': text_of(ws[a:b + 1])} for k, (a, b) in enumerate(out)]


def listen(A, m, work, log, save=lambda: None):
    """Every clip of the story's own posts gets its "said". A clip already heard is not heard twice."""
    adir = os.path.join(work, 'assets')
    own = {k: a for k, a in A.items() if a.get('file') and a.get('kind') == 'clip' and not a.get('whole') and 'post' in a}
    need = [k for k, a in own.items() if 'said' not in a]
    if need:
        got = heard([os.path.join(adir, own[k]['file']) for k in need], log)
        for k in need:
            h = got.get(own[k]['file'])
            own[k]['said'] = {'by': 'the speech model of this machine', 'lang': h['lang'], 'words': h['words']} if h and h.get('words') else {'by': '', 'lang': '', 'words': []}
        save()
    for k, a in own.items():
        s = a.get('said') or {}
        if s.get('words'):
            name_fix(s, m, log, ' ' + k)
            split_long(s, log, ' ' + k)
            s['text'] = text_of(s['words'])
            s['parts'] = parts_of(s)
            log('  %s: heard (%s, %s), %d thought(s): %s' % (k, s.get('by') or '?', s.get('lang') or '?', len(s['parts']), ' | '.join('%s %.1fs "%s"' % (p['id'], p['e'] - p['s'], p['text'][:46]) for p in s['parts'][:8])))
    save()
    return sum(1 for a in own.values() if (a.get('said') or {}).get('words'))


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    wk = sys.argv[1]
    mf = os.path.join(wk, 'material.json')
    mat = json.load(open(mf, encoding='utf-8'))
    t = time.time()
    n = listen(mat.get('assets') or {}, mat, wk, lambda *a: print(*a, flush=True), lambda: json.dump(mat, open(mf, 'w', encoding='utf-8'), ensure_ascii=False, indent=1))
    print('ears: %d clip(s) heard in %.0f s' % (n, time.time() - t))
