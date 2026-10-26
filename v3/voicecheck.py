"""THE VOICE IS HEARD BACK. A person who voices a video listens to the take and says a name again when it came out wrong.
The system could not hear: a name the voice guessed from its letters ("Galatasaray" said as "Galatasai", "Joao" said as
"Joe Al") went into the video. Now every take that holds a word the voice's dictionary does not know is heard back by
the speech model on this machine (ears_run.py, no hint of what was meant: a hint makes it write the script back), and
each such word is compared with what was heard by its CONSONANTS (vowels, doubled letters and voiced/voiceless pairs
are what a speech model spells loosely; a lost or a new consonant is a lost or a wrong syllable).
A word that did not come back is said again, another way:
  - the online voice reads text only: the word is given RESPELLED in plain English syllables ("Joe-ow Kan-sell-oh"),
    asked once from a writing model and kept in store/voice_respell.json; the captions keep the real word
  - the local voice reads sounds: the word's IPA is asked again, told what the ear heard
The new take is kept only when the ear hears it better than the old one. Nothing is said again more than twice.
usage (alone): voicecheck.py "a sentence" "what the ear heard"   (prints the words that did not come back)"""
import difflib
import json
import os
import re
import sys
import unicodedata

import ai
import voicewords

HERE = os.path.dirname(os.path.abspath(__file__))
RESPELL = os.path.join(HERE, 'store', 'voice_respell.json')
PAIRS = str.maketrans({'b': 'p', 'd': 't', 'g': 'k', 'z': 's', 'v': 'f', 'q': 'k', 'c': 'k', 'x': 'k'})


def plain(w):
    """A word in small plain letters: no accents, no marks."""
    w = unicodedata.normalize('NFKD', str(w)).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z]', '', w)


def skeleton(w):
    """The consonants of a word as they sound: "Cancelo" and "Cancello" -> "knsl", "Galatasaray" -> "kltsr"."""
    w = plain(w)
    w = re.sub(r'c(?=[eiy])', 's', w)
    for a, b in (('ph', 'f'), ('ck', 'k'), ('x', 'ks'), ('sch', 'sh'), ('th', 't'), ('gh', 'k'), ('qu', 'k'), ('dg', 'j'), ('tch', 'ch')):
        w = w.replace(a, b)
    w = w.translate(PAIRS)
    w = re.sub(r'[aeiouyhw]', '', w)
    return re.sub(r'(.)\1+', r'\1', w)


def far(a, b):
    """How many consonants differ (edit distance of the two skeletons)."""
    a, b = skeleton(a), skeleton(b)
    d = list(range(len(b) + 1))
    for i, x in enumerate(a, 1):
        prev, d[0] = d[0], i
        for j, y in enumerate(b, 1):
            prev, d[j] = d[j], min(d[j] + 1, d[j - 1] + 1, prev + (x != y))
    return d[len(b)], max(len(a), len(b))


def inside(word, heard):
    """How many consonants of a word are missing or wrong in the best matching stretch of what was heard (words the
    ear joined or split do not matter: "Lamine Yamal" heard as "Lomanyamall" is whole). -> (n, size)"""
    a, b = skeleton(word), skeleton(heard)
    d = [0] * (len(b) + 1)                                     # a stretch may start anywhere in what was heard
    for i, x in enumerate(a, 1):
        prev, d[0] = d[0], i
        for j, y in enumerate(b, 1):
            prev, d[j] = d[j], min(d[j] + 1, d[j - 1] + 1, prev + (x != y))
    return min(d), len(a)


def risky(text):
    """The words of a line the voice has to guess: not in its dictionary (names, mostly)."""
    return [w for w in dict.fromkeys(re.sub(r"['’]s$", '', w) for w in re.findall(r"[A-Za-zÀ-ÿĀ-ſ][A-Za-zÀ-ÿĀ-ſ'’]*", text)) if not voicewords.known(w)]


def missed(text, heard_words):
    """The risky words of a line that did not come back from the ear, each with what was heard in its place.
    The line and what was heard are lined up word by word; a risky word inside a stretch that matches is fine."""
    spans = list(re.finditer(r"[A-Za-zÀ-ÿĀ-ſ0-9][A-Za-zÀ-ÿĀ-ſ0-9'’-]*", text))
    words = [x.group(0) for x in spans]
    said = [plain(w) for w in words]
    got = [plain(w) for w in re.findall(r"[A-Za-zÀ-ÿĀ-ſ0-9][A-Za-zÀ-ÿĀ-ſ0-9'’-]*", ' '.join(heard_words))]
    want = {plain(w) for w in risky(text)}
    is_risky = lambda k: (said[k] in want or re.sub(r's$', '', said[k]) in want) and bool(skeleton(said[k]))
    joined = lambda k: k and not re.search(r'[.!?,;:]', text[spans[k - 1].end():spans[k].start()])      # two words said in one breath
    out = []
    for op, i1, i2, j1, j2 in difflib.SequenceMatcher(None, said, got, autojunk=False).get_opcodes():
        if op == 'equal':
            continue
        heard = got[j1:j2]
        bad = {}
        for k in range(i1, i2):                                # each name alone, in the best matching stretch of what was heard
            if is_risky(k):
                n, size = inside(said[k], ''.join(heard))
                if n > size // 6:                              # a name of up to five consonants must come back whole; a longer one may lose one
                    bad[k] = n
        runs, k = [], i1                                       # names said together ("Joao Cancelo"): the whole run against whole heard words,
        while k < i2:                                          # so a syllable said between them ("Joe Al-cona") is not hidden by the match
            if is_risky(k):
                r = [k]
                while r[-1] + 1 < i2 and is_risky(r[-1] + 1) and joined(r[-1] + 1):
                    r.append(r[-1] + 1)
                runs.append(r)
                k = r[-1] + 1
            else:
                k += 1
        for r in runs:
            if len(r) < 2 or any(x in bad for x in r):
                continue
            name = ''.join(said[x] for x in r)
            n, size = min((far(name, ''.join(heard[a:b])) for a in range(len(heard)) for b in range(a + 1, len(heard) + 1)), default=(len(skeleton(name)),) * 2)
            if n > len(skeleton(name)) // 6:
                bad[r[-1]] = n
        out += [{'word': words[k], 'heard': ' '.join(heard) or '(nothing)', 'n': n} for k, n in sorted(bad.items())]
    return out


def kept():
    try:
        return json.load(open(RESPELL, encoding='utf-8'))
    except Exception:  # noqa: BLE001
        return {}


def respellings(names, about, heard, log=print):
    """{name: [respelling, another]} for names the online voice said wrong: plain English syllables a US voice reads
    right ("Joao" -> "Joe-ow"). Asked once and kept; a name already asked comes from the file."""
    have = kept()
    new = [n for n in dict.fromkeys(names) if n.lower() not in have]
    if new:
        try:
            j, by = ai.ask_json('You know how names from sport, gaming, streaming and internet culture are said out loud by the people themselves. Strict JSON only.',
                                'THE STORY: %s\n\nAn American English text-to-speech voice read these names WRONG (what a listener heard instead is given). For each, write the name RESPELLED '
                                'so that same voice says it right: plain English syllables joined by hyphens, small letters, every syllable a common English spelling ("Joao" -> "zhoo-ow", '
                                '"Cenat" -> "sih-nat", "Kameto" -> "kah-meh-toh"). Keep the number of syllables the person says. Give two different respellings for each.\nNAMES: %s\n'
                                'JSON: {"said":[{"w":"Cenat","respell":["sih-nat","suh-nat"]}]}'
                                % (about[:300], json.dumps([{'name': n, 'heard': heard.get(n, '')} for n in new], ensure_ascii=False)), kind='text', temperature=0.2, timeout=60, max_tokens=2000)
            got = {}
            for x in [x for x in ((j or {}).get('said') if isinstance(j, dict) else None) or [] if isinstance(x, dict)]:
                w = str((x or {}).get('w', ''))
                rs = [r for r in ((x or {}).get('respell') or []) if isinstance(r, str) and re.fullmatch(r"[a-z]+(?:-[a-z]+)*", r.strip().lower())]
                if w in new and rs:
                    got[w.lower()] = [r.strip().lower() for r in rs[:2]]
            have.update(got)
            os.makedirs(os.path.dirname(RESPELL), exist_ok=True)
            json.dump(have, open(RESPELL, 'w', encoding='utf-8'), ensure_ascii=False, indent=1, sort_keys=True)
            log('voice: %d name(s) respelled for the online voice (%s): %s' % (len(got), by, ', '.join('%s "%s"' % (k, v[0]) for k, v in got.items())[:300]))
        except ai.OutOfTime:
            raise
        except Exception as e:  # noqa: BLE001
            log('voice: no respelling came back (%s)' % str(e)[-80:])
    return {n: have.get(n.lower()) or [] for n in names}


def respelled(text, spell):
    """The line as the online voice gets it: each name replaced by its respelling. {name: 'joe-ow'}."""
    def one(mo):
        w = mo.group(0)
        base = re.sub(r"['’]s$", '', w)
        r = spell.get(base)
        return (r + w[len(base):]) if r else w
    return re.sub(r"[A-Za-zÀ-ÿĀ-ſ][A-Za-zÀ-ÿĀ-ſ'’]*", one, text)


def restore(words, text, sent):
    """The word timings of a respelled take, put back on the script's own words (the captions show "Joao", not "joe-ow").
    words: [{'w','s','d'}] as the voice gave them for `sent`."""
    if sent == text:
        return words
    mine = re.findall(r"\S+", text)
    theirs = re.findall(r"\S+", sent)
    if len(mine) != len(theirs):                               # every word is replaced by one respelled word: the counts match
        return words
    key = lambda w: plain(w)
    owner = {}
    for k, (a, b) in enumerate(zip(mine, theirs)):
        owner.setdefault(key(b), []).append(k)
    out, used = {}, set()
    seq = [key(b) for b in theirs]
    got = [key(w['w']) for w in words]
    for op, i1, i2, j1, j2 in difflib.SequenceMatcher(None, seq, got, autojunk=False).get_opcodes():
        for jj in range(j1, j2):
            k = i1 + min(jj - j1, max(0, i2 - i1 - 1)) if i2 > i1 else max(0, i1 - 1)
            w = words[jj]
            if k in out:
                e = max(out[k]['s'] + out[k]['d'], w['s'] + w['d'])
                out[k]['d'] = round(e - out[k]['s'], 3)
            else:
                out[k] = {'w': re.sub(r"^[^\w]+|[^\w'’]+$", '', mine[k]) or w['w'], 's': w['s'], 'd': w['d']}
            used.add(jj)
    return [out[k] for k in sorted(out)]


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    print(json.dumps(missed(sys.argv[1], sys.argv[2].split()), ensure_ascii=False))
