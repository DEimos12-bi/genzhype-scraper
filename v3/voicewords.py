"""HOW A WORD IS SAID. The local voice (Kokoro) reads a word it does not know by guessing from its letters: "Cenat" came
out "SEN-at", "Karmine" as "KAR-mine", "Kameto" as "ka-MEE-toh". A person who voices these videos knows the names.
So, before a line is spoken:
  1. every word of the script is looked up in the voice's own dictionary (misaki's two word lists, read as plain files)
  2. a word it does not know is looked up in voice_names.json (our beat's names, written by hand, in IPA)
  3. what is still unknown is asked from a light model ONCE (IPA, checked letter by letter) and kept in
     store/voice_names.json, so a name is asked one time for all the videos
  4. the line goes to the voice with the sound written next to the word, "[Cenat](/sɪnˈæt/)": the caption still shows
     the word, the voice says the sound
Also here: "live" (a stream is live, people live somewhere) is said the way the sentence means it.
usage: voicewords.py "a sentence with Kai Cenat and Kameto in it"   (prints what the voice will be given)"""
import glob
import json
import os
import re
import sys

import ai

HERE = os.path.dirname(os.path.abspath(__file__))
KEPT = os.path.join(HERE, 'store', 'voice_names.json')
VOWELS = 'AIOWYaeiouæɑɔəɛɜɪʊʌᵊᵻ'
SOUNDS = set('AIOWYabdfhijklmnpstuvwzæðŋɑɔəɛɜɡɪɹɾʃʊʌʒʤʧθᵊᵻTˈˌ')      # the letters the voice was trained on (American English)
SWAP = [('t͡ʃ', 'ʧ'), ('d͡ʒ', 'ʤ'), ('tʃ', 'ʧ'), ('dʒ', 'ʤ'), ('eɪ', 'A'), ('aɪ', 'I'), ('oʊ', 'O'), ('əʊ', 'O'), ('aʊ', 'W'), ('ɔɪ', 'Y'), ('ɝ', 'ɜɹ'), ('ɚ', 'əɹ'), ('r', 'ɹ'), ('g', 'ɡ'),
        ('ɐ', 'ə'), ('ɒ', 'ɑ'), ('ɘ', 'ə'), ('ɨ', 'ɪ'), ('ʁ', 'ɹ'), ('x', 'k'), ('ç', 'ʃ'), ('y', 'u'), ('ø', 'ɜ'), ('œ', 'ɜ'), ('ɲ', 'nj'), ('ʎ', 'j'), ('ɥ', 'w'), ('ʔ', ''), ('c', 'k'), ('q', 'k'),
        ('e', 'ɛ'), ('a', 'ɑ'), ('o', 'O')]
VERB_BEFORE = r"(?:to|i|we|you|they|people|who|can|could|will|would|should|must|might|may|not|never|still|don't|didn't|doesn't|can't|won't|let|gonna|wanna)"
LIVE_AFTER = r"(?:went|go|goes|going|gone|is|was|are|were|be|been|it's|that's|he's|she's|they're|streamed|streaming|streams|broadcast|aired|played|happened|reacted|said|stated|did|all|it)"
_DICT = {}


def dictionary():
    """The words the voice knows how to say (its two word lists). An empty set when they cannot be found: then nothing is asked."""
    if 'w' not in _DICT:
        py = (ai.CONFIG.get('voice_fallback') or {}).get('python') or ai.CONFIG.get('voice_python', '') or os.path.join(HERE, '.venv-voice', 'Scripts', 'python.exe')
        root = os.path.dirname(os.path.dirname(py))
        words = set()
        for f in glob.glob(os.path.join(root, 'Lib', 'site-packages', 'misaki', 'data', 'us_*.json')) + glob.glob(os.path.join(root, 'lib', 'python*', 'site-packages', 'misaki', 'data', 'us_*.json')):
            try:
                words |= set(json.load(open(f, encoding='utf-8')))
            except Exception:  # noqa: BLE001
                pass
        _DICT['w'] = words
    return _DICT['w']


def known(word):
    """Whether the voice's dictionary has the word, as written, in small letters, or as its parts (SuperBrine = Super + Brine)."""
    d = dictionary()
    w = re.sub(r"['’]s$", '', word)
    if not d or len(w) < 3 or w in d or w.lower() in d or w.capitalize() in d:
        return True
    if w.isupper() and len(w) <= 5:                            # initials: the voice spells them
        return True
    stems = lambda x: [x] + [x[:-len(e)] + a for e, a in (('s', ''), ('es', ''), ('ed', ''), ('ed', 'e'), ('d', ''), ('ing', ''), ('ing', 'e'), ('ly', ''), ('er', ''), ('er', 'e'), ('ers', ''), ('est', ''), ('ies', 'y'), ('ied', 'y'))
                             if x.lower().endswith(e) and len(x) - len(e) >= 3] + ([x[:-3 if x.lower().endswith('ed') else -4]] if re.search(r'([b-df-hj-np-tv-z])\1(?:ed|ing)$', x.lower()) else [])
    plain = lambda x: any(y in d or y.lower() in d or y.capitalize() in d for y in stems(x))      # a regular ending is not an unknown word: the voice builds "blindfolded" itself
    if plain(w):
        return True
    if w.islower() and any(w[:i] in d and w[i:] in d for i in range(3, len(w) - 2)):      # two known words written as one ("hardcore")
        return True
    parts = [p for p in re.findall(r'[A-ZÀ-Ý]?[a-zà-ÿ]+|[A-ZÀ-Ý]+(?![a-zà-ÿ])', w)]      # a name written in one word with capitals inside
    return len(parts) > 1 and all(len(p) >= 2 and plain(p) for p in parts)


def to_voice(ipa):
    """IPA as a person writes it -> the voice's own letters, the stress mark right before the vowel. '' when it holds a sound the voice does not have."""
    s = re.sub(r'[\s/\[\]().ːˑ‿͡\-]', '', str(ipa or ''))
    s = ''.join(ch for ch in s if not 0x300 <= ord(ch) <= 0x36f)      # marks above and below a letter
    for a, b in SWAP:
        s = s.replace(a, b)
    if not s or any(ch not in SOUNDS for ch in s) or not any(ch in VOWELS for ch in s):
        return ''
    out, hold = '', ''
    for ch in s:                                               # "kəˈmɛtO" -> "kəmˈɛtO"
        if ch in 'ˈˌ':
            hold = ch
        elif hold and ch in VOWELS:
            out, hold = out + hold + ch, ''
        else:
            out += ch
    return out if 'ˈ' in out else re.sub('([%s])' % VOWELS, 'ˈ\\1', out, count=1)


def names():
    """{word in small letters: IPA}: what was asked before, then the hand-written file (which wins)."""
    out = {}
    for f in (KEPT, os.path.join(HERE, 'voice_names.json')):
        try:
            out.update({k.lower(): v for k, v in json.load(open(f, encoding='utf-8')).items() if not k.startswith('_') and isinstance(v, str)})
        except Exception:  # noqa: BLE001
            pass
    return out


def learn(texts, about, log=print):
    """The words of these lines the voice does not know and nobody wrote down yet: asked from a light model, once, and kept."""
    have = names()
    words = list(dict.fromkeys(re.sub(r"['’]s$", '', w) for t in texts for w in re.findall(r"[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'’]*", re.sub(r'\[[^\]]*\]\([^)]*\)', ' ', t))))
    new = [w for w in words if w.lower() not in have and not known(w)][:24]
    if not new:
        return have
    try:
        j, by = ai.ask_json('You know how names and words from gaming, streaming and internet culture are said out loud. Strict JSON only.',
                            'THE STORY: %s\n\nThese words are in a voice-over script and a text-to-speech voice does not know them. For EACH, give how it is SAID in American English, in IPA, with the stress mark ˈ before the stressed '
                            'syllable. A streamer, a player, a team, a company, a game: the way that person or brand says its own name. A word you do not know: its most natural reading.\nWORDS: %s\n'
                            'JSON: {"said":[{"w":"Kameto","ipa":"kɑˈmɛtoʊ"}]}' % (about[:300], json.dumps(new, ensure_ascii=False)), kind='light', temperature=0.1, timeout=45, max_tokens=1500)
    except ai.OutOfTime:
        raise
    except Exception as e:  # noqa: BLE001
        log('voice: %d word(s) the voice does not know were not asked (%s): it guesses them from their letters (%s)' % (len(new), str(e)[-60:], ', '.join(new)[:120]))
        return have
    got, kept = {}, {}
    for x in (j or {}).get('said') or []:
        w, v = str((x or {}).get('w', '')), to_voice((x or {}).get('ipa', ''))
        if w in new and v and 0.4 <= len(v.replace('ˈ', '').replace('ˌ', '')) / max(1, len(w)) <= 1.6:
            got[w.lower()] = str(x['ipa']).strip('/[] ')
    try:
        kept = json.load(open(KEPT, encoding='utf-8'))
    except Exception:  # noqa: BLE001
        kept = {}
    kept.update(got)
    os.makedirs(os.path.dirname(KEPT), exist_ok=True)
    json.dump(kept, open(KEPT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1, sort_keys=True)
    log('voice: %d word(s) the voice did not know, asked once (%s): %s%s' % (len(new), by, ', '.join('%s /%s/' % (w, got[w.lower()]) for w in new if w.lower() in got)[:400],
                                                                         ' | left to its guess: ' + ', '.join(w for w in new if w.lower() not in got) if len(got) < len(new) else ''))
    have.update(got)
    return have


def relearn(words, about, heard, log=print):
    """The ear did not hear these names back from the local voice: their sounds are asked again from a writing model,
    told what was heard instead, and kept. -> {word in small letters: IPA}"""
    try:
        j, by = ai.ask_json('You know how names from sport, gaming, streaming and internet culture are said out loud by the people themselves. Strict JSON only.',
                            'THE STORY: %s\n\nA text-to-speech voice said these names WRONG (what a listener heard instead is given). For EACH, give how the person or brand says it, in IPA '
                            'for an American English voice, with the stress mark ˈ before the stressed syllable. Count the syllables first: the IPA has one vowel per syllable.\nNAMES: %s\n'
                            'JSON: {"said":[{"w":"Kameto","syllables":3,"ipa":"kɑˈmɛtoʊ"}]}' % (about[:300], json.dumps([{'name': w, 'heard': heard.get(w, '')} for w in words], ensure_ascii=False)),
                            kind='text', temperature=0.2, timeout=60, max_tokens=2000)
    except ai.OutOfTime:
        raise
    except Exception as e:  # noqa: BLE001
        log('voice: the names were not asked again (%s)' % str(e)[-60:])
        return {}
    got = {}
    for x in (j or {}).get('said') or []:
        w, v = str((x or {}).get('w', '')), to_voice((x or {}).get('ipa', ''))
        if w in words and v:
            got[w.lower()] = str(x['ipa']).strip('/[] ')
    try:
        kept = json.load(open(KEPT, encoding='utf-8'))
    except Exception:  # noqa: BLE001
        kept = {}
    kept.update(got)
    os.makedirs(os.path.dirname(KEPT), exist_ok=True)
    json.dump(kept, open(KEPT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1, sort_keys=True)
    return got


def mark(text, said=None):
    """The line as the voice gets it: the sound written next to every word it would guess, and "live" said as the sentence means it."""
    said = names() if said is None else said

    def one(mo):
        word = mo.group(0)
        base = re.sub(r"['’]s$", '', word)
        if base.lower() == 'live' and base == word:
            left, right = text[:mo.start()].rstrip(), text[mo.end():].lstrip()
            before = left.split(' ')[-1].lower().strip(',;:"“”') if left else ''
            edge = not left or left[-1] in '.!?,:' or not right or right[0] in '.!?,'      # the first or the last word of a sentence: where a tagger goes wrong ("blindfolded, live.")
            on_air = (edge or re.fullmatch(LIVE_AFTER, before)) and not re.fullmatch(VERB_BEFORE, before)
            return '[%s](/lˈIv/)' % word if on_air else word   # elsewhere the voice reads the sentence itself ("they live in Paris", "seven live streams")
        ipa = said.get(base.lower())
        v = to_voice(ipa) if ipa and (not known(base) or base.lower() in said) else ''
        if not v:
            return word
        if base != word:                                       # "Cenat's"
            v += 'ᵻz' if v[-1] in 'szʃʒʧʤ' else 's' if v[-1] in 'ptkfθ' else 'z'
        return '[%s](/%s/)' % (word, v)
    return re.sub(r"[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'’]*", one, text)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    line = ' '.join(sys.argv[1:]) or 'Kai Cenat went live. Karmine Corp, Kameto and Vatira live in Paris.'
    print(mark(line, learn([line], line)))
