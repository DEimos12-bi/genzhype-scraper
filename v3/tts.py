"""THE VOICE: one take per script line (edge-tts with word timings), trimmed and laid end to end with chosen pauses.
Writes vo.wav (48 kHz mono) and timeline.json (every line and word with its time in the final video); every cut and
every graphic is placed from those times. A script that comes out a little under the minimum length is spoken once
more, a little slower (never slower than a natural pace), instead of losing the video.  usage: tts.py <work folder>"""
import asyncio
import json
import os
import re
import subprocess
import sys
import time
import wave

import numpy as np

import ai
import voicecheck
import voicewords

SR = 48000
LEAD = 0.10          # silence before the first word
SLOWEST = -4         # per cent: the slowest the voice may go
BITE_LEAD, BITE_TAIL = 0.28, 0.22      # the silence before and after a clip that speaks for itself
# A swear word in the subject's own clip: TikTok holds back a video that says or shows it. It is bleeped in the sound
# (audio.py, on the word's own seconds) and written masked in the captions ("F******"); the clip still speaks.
SWEAR = re.compile(r"(?i)^(?:mother)?f+u+c*k+\w*$|^shit\w*$|^bullshit\w*$|^bitch\w*$|^cunts?$|^ass(?:hole)?s?$|^dick(?:head)?s?$|^puss(?:y|ies)$|^bastards?$|^nigg\w*$|^fag(?:got)?s?$|^retard\w*$|^wtf$|^stfu$")


def masked(w):
    """A swear word with its first letter kept: "fucking" -> "F******"."""
    return w[:1].upper() + '*' * max(2, len(w) - 1)


def place_bites(plan, tl):
    """A line of the plan may end with a bite: seconds of a clip of the story, with their own sound. The voice left a
    pause of that length after the line; here the bite becomes a line of the timeline (id <line>bite) with its own
    words and times, so the captions, the cut and the sound all sit on it."""
    out = []
    for l in tl:
        out.append(l)
        b = next((p.get('bite') for p in plan['lines'] if p['id'] == l['id']), None)
        if not b:
            continue
        at = round(LEAD if b.get('first') else l['e'] + BITE_LEAD, 3)      # the second of the video where the clip second b['ss'] plays (a cold open: at the very start)
        clean = lambda w: re.sub(r"^[^\w@#$%]+|[^\w%'’]+$", '', w)
        ws = [{'w': clean(w), 's': round(at + s - b['ss'], 3), 'e': round(at + e - b['ss'], 3)} for w, s, e in b['words'] if clean(w)]
        for w in ws:
            if SWEAR.match(w['w']):
                w['w'], w['bleep'] = masked(w['w']), True
        text = ' '.join(masked(x) if SWEAR.match(re.sub(r"[^\w']", '', x)) else x for x in b['text'].split())
        if any(w.get('bleep') for w in ws):
            print('voice: the clip says %d swear word(s): bleeped in the sound, masked in the captions' % sum(1 for w in ws if w.get('bleep')), flush=True)
        row = {'id': l['id'] + 'bite', 'text': text, 's': ws[0]['s'] if ws else at, 'e': round(at + b['dur'], 3), 'words': ws,
               'bite': {'at': at, 'ss': b['ss'], 'dur': b['dur'], 'asset': b['asset'], 'src': 'assets/' + plan['assets'][b['asset']]['file'], 'of': l['id'], 'who': b.get('who', ''), 'first': bool(b.get('first'))}}
        if b.get('first'):
            out.insert(len(out) - 1, row)                     # the clip comes before its line
        else:
            out.append(row)
    return out


SENT = re.compile(r'.+?(?:[.!?…]+["”’\']*(?=\s|$)|:(?=\s)|$)', re.S)
TAKES = set()                # the narrator's takes of this plan (normalised): said drier and slower


def sentences_of(text):
    return [x.strip() for x in SENT.findall(text or '') if x.strip()]


def prosody(k, n, sentence, line_id, rate, seed):
    """How one sentence is said: a person does not read every sentence at the same pace and pitch. -> (rate %, pitch Hz,
    pause after in s). The opening is pushed, a take is dry (slower, lower), a question lifts, a fragment lands with a
    breath after it, a long fact runs a little faster; a small steady variation keeps two lines from sounding alike."""
    w = len(sentence.split())
    jit = ((seed * 7 + k * 13) % 7) - 3                   # -3..+3, the same on every run of the same words
    r, ph, pause = rate + jit, 0, .14
    flat = re.sub(r'[^a-z0-9 ]', '', sentence.lower())
    if any(re.sub(r'[^a-z0-9 ]', '', t) == flat or (len(flat) > 6 and flat in re.sub(r'[^a-z0-9 ]', '', t)) for t in TAKES):
        r, ph, pause = rate - 9, -8, .30
    elif sentence.rstrip('"”’\'').endswith('?'):
        r, ph, pause = rate - 3, 6, .26
    elif w <= 3:
        r, ph, pause = rate - 5, -3, .24
    elif w >= 13:
        r = rate + 4
    if line_id == 'hook' and k == 0:
        r, ph = max(r, rate + 3), ph + 3
    if sentence.endswith(':'):
        pause = .18
    if k == n - 1:
        pause = 0.0                                       # the line's own pause comes after it (main)
    return r, ph, pause


async def one(takes, i, text, rate, voice, script=None, line_id=''):
    """One take by the online voice, said sentence by sentence (each with its own pace, pitch and pause, see prosody),
    joined into one take. `text` is what it is given (a name may be respelled), `script` the line's own words: the word
    timings are put back on those, so the captions show the real names."""
    import edge_tts
    mp3, meta = os.path.join(takes, '%02d.mp3' % i), os.path.join(takes, '%02d.json' % i)
    base = int(str(rate).rstrip('%') or 0)
    said, mine = sentences_of(text), sentences_of(script or text)
    if len(said) != len(mine):                            # the respelled line split differently: said as written
        said, mine = [text], [script or text]
    seed = sum(ord(c) for c in (script or text))
    plan = [prosody(k, len(said), y, line_id, base, seed) for k, y in enumerate(mine)]

    async def say(k, tries=3):                            # the sentences of a line are asked for at the same time
        r, ph, _ = plan[k]
        tmp = mp3 + '.%d.mp3' % k
        for t in range(tries):
            try:
                try:
                    com = edge_tts.Communicate(said[k], voice, rate='%+d%%' % r, pitch='%+dHz' % ph, boundary='WordBoundary')
                except TypeError:
                    com = edge_tts.Communicate(said[k], voice, rate='%+d%%' % r, pitch='%+dHz' % ph)
                ws = []
                with open(tmp, 'wb') as f:
                    async for chunk in com.stream():
                        if chunk['type'] == 'audio':
                            f.write(chunk['data'])
                        elif chunk['type'] == 'WordBoundary':
                            ws.append({'w': chunk['text'], 's': chunk['offset'] / 1e7, 'd': chunk['duration'] / 1e7})
                return tmp, ws
            except Exception as e:  # noqa: BLE001
                if '403' in str(e) or t == tries - 1:
                    raise
                await asyncio.sleep(1.5 * (t + 1))
    try:
        got = await asyncio.gather(*(say(k) for k in range(len(said))))
    except Exception as e:  # noqa: BLE001
        if '403' in str(e) or len(said) == 1:
            raise
        said, mine = [text], [script or text]                 # a sentence the service would not say alone: the line is said whole
        plan = [(base, 0, 0.0)]
        got = await asyncio.gather(say(0))
    parts, words, t = [], [], 0.0
    for k, (x, y) in enumerate(zip(said, mine)):
        _, _, pause = plan[k]
        tmp, ws = got[k]
        a = load(tmp)
        os.remove(tmp)
        loud = np.where(np.abs(a) > 0.012)[0]
        if not len(loud):
            continue
        s0, s1 = max(0, loud[0] - int(0.015 * SR)), min(len(a), loud[-1] + int(0.05 * SR))
        a = a[s0:s1]
        if y != x:
            ws = voicecheck.restore(ws, y, x)
        words += [{'w': w['w'], 's': round(t + w['s'] - s0 / SR, 3), 'd': w['d']} for w in ws]
        parts += [a, np.zeros(int(pause * SR), dtype=np.float32)]
        t += len(a) / SR + pause
    if not parts:
        return 0
    wav = mp3 + '.wav'
    with wave.open(wav, 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((np.clip(np.concatenate(parts), -1, 1) * 32767).astype(np.int16).tobytes())
    subprocess.run(['ffmpeg', '-y', '-v', 'error', '-i', wav, '-c:a', 'libmp3lame', '-b:a', '192k', mp3], check=True)
    os.remove(wav)
    json.dump(words, open(meta, 'w', encoding='utf-8'), ensure_ascii=False)
    return len(words)


def load(mp3):
    if not os.path.isfile(mp3) and os.path.isfile(mp3[:-4] + '.wav'):      # a take by the fallback voice
        mp3 = mp3[:-4] + '.wav'
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', mp3, '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True).stdout
    return np.frombuffer(raw, dtype=np.float32).copy()


def sent(i, text):
    """What the online voice is given for line i: the line, or the line with a name respelled after the ear (SEND)."""
    x = SEND.get(str(i))
    return x[1] if x and x[0] == text else text


def have_take(takes, i, text, rate):
    """A take spoken before, for the same words at the same rate, is kept (a stopped run does not speak twice)."""
    tag = os.path.join(takes, '%02d.txt' % i)
    ok = os.path.isfile(os.path.join(takes, '%02d.json' % i)) and (os.path.isfile(os.path.join(takes, '%02d.mp3' % i)) or os.path.isfile(os.path.join(takes, '%02d.wav' % i)))
    return ok and os.path.isfile(tag) and open(tag, encoding='utf-8').read() == '%s|%s' % (rate, sent(i, text))


def mark_take(takes, i, text, rate):
    open(os.path.join(takes, '%02d.txt' % i), 'w', encoding='utf-8').write('%s|%s' % (rate, sent(i, text)))


def take_files(takes, i):
    return [os.path.join(takes, '%02d.%s' % (i, x)) for x in ('mp3', 'wav', 'json', 'txt')]


def drop_take(takes, i):
    for f in take_files(takes, i):
        if os.path.exists(f):
            os.remove(f)


def is_local(takes, i):
    return os.path.isfile(os.path.join(takes, '%02d.wav' % i))


def fallback_batch(takes, jobs):
    """The lines still to speak, by the local voice (kokoro_say.py in its own Python, the model loaded once): every video
    stopped on one free service's 403 otherwise. jobs: [(i, text, rate)]. -> the set of i that were spoken."""
    fb = ai.CONFIG.get('voice_fallback') or {}
    py = fb.get('python') or ai.CONFIG.get('voice_python', '')
    if not py or not os.path.isfile(py) or not jobs:
        return set()
    batch = []
    for i, text, rate in jobs:
        wav, meta = os.path.join(takes, '%02d.wav' % i), os.path.join(takes, '%02d.json' % i)
        for f in (wav, meta, os.path.join(takes, '%02d.mp3' % i)):
            if os.path.exists(f):
                os.remove(f)
        batch.append({'i': i, 'text': voicewords.mark(text, SAID or None), 'wav': wav, 'json': meta, 'voice': fb.get('voice', 'am_michael'), 'speed': '%.3f' % (1 + rate / 100.0)})      # the sound written next to a name the voice would guess
    jfile = os.path.join(takes, 'fallback_jobs.json')
    json.dump(batch, open(jfile, 'w', encoding='utf-8'), ensure_ascii=False)
    r = subprocess.run([py, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'kokoro_say.py'), jfile], capture_output=True, text=True, timeout=900)
    if r.returncode != 0:
        print('voice: the local voice failed: %s' % ' '.join(r.stderr.split())[-220:], flush=True)
        return set()
    return {j['i'] for j in json.load(open(jfile, encoding='utf-8')) if j.get('words', 0) > 0 and os.path.isfile(j['json'])}


BUDGET = {'t0': time.time(), 's': None}
SAID = {}                    # how this script's names are said (voicewords.py)
SEND = {}                    # {line index: [the line, what the online voice is given]} when the ear made a name be respelled
LOCAL = {'on': False}        # one voice per video: once the local voice speaks a line, it speaks them all
COLD = {'s': 0.0}            # the seconds of a cold open (the story's own clip before the first word)


def over():
    spent = time.time() - BUDGET['t0']
    return (BUDGET['s'] is not None and spent > BUDGET['s']) or (BUDGET.get('left') is not None and BUDGET['left'] - spent < 32)      # its own budget, or too little left of the caller's call for one more batch


def chunk_size(takes=None):
    """How many lines the next local-voice batch may take. A line costs what it cost in the last batch on this machine
    (kept with the takes; 30 s before any was measured: the model's loading shared out). The step may run one minute past
    its budget at most (a call is cut at two minutes from outside). 0 = no batch can end in what is left of this call."""
    if BUDGET['s'] is None:
        return 3
    cost = 30.0
    try:
        cost = max(8.0, float(json.load(open(os.path.join(takes, 'speed.json'), encoding='utf-8'))['line']))
    except Exception:  # noqa: BLE001
        pass
    room = BUDGET['s'] + 60 - (time.time() - BUDGET['t0'])
    if BUDGET.get('left') is not None:                         # the caller cuts the whole command: the batch must end before that
        room = min(room, BUDGET['left'] - (time.time() - BUDGET['t0']) - 14)
    return max(0, min(3, int(room / cost)))


def speak(takes, lines):
    spoken = {i for i, (lid, text, rate, _) in enumerate(lines) if have_take(takes, i, text, rate)}
    LOCAL['on'] = LOCAL['on'] or any(is_local(takes, i) for i in spoken)
    refused = ['the local voice speaks this video'] if LOCAL['on'] else []

    async def synth():
        for i, (lid, text, rate, _) in enumerate(lines):
            if i in spoken:
                continue
            if refused or over():                         # the service is refusing: the rest goes to the local voice; or the time is up
                break
            for attempt in range(7):                      # the free voice service drops a request now and then ("no audio received"), the home connection too
                try:
                    if await one(takes, i, sent(i, text), '%+d%%' % rate, ai.CONFIG['voice'], text, lid) > 0:
                        mark_take(takes, i, text, rate)
                        spoken.add(i)
                        break
                except Exception as e:  # noqa: BLE001
                    blocked = '403' in str(e)                 # the service refuses this machine: the local voice takes over at once
                    if blocked or attempt == 6:
                        refused.append(str(e)[:80])
                        break
                    print('voice: line %s did not come (%s); asked again in %d s' % (lid, str(e)[:60], 3 * 2 ** min(attempt, 4)), flush=True)
                    await asyncio.sleep(3 * 2 ** min(attempt, 4))      # 3, 6, 12, 24, 48 s: a dropped connection is waited out
    asyncio.run(synth())
    online = [i for i in spoken if not is_local(takes, i)]
    if refused and online:                                # two voices in one video sound like two videos: the local voice says it all
        print('voice: one voice per video: the %d line(s) the online voice spoke are spoken again by the local voice' % len(online), flush=True)
        for i in online:
            drop_take(takes, i)
        spoken -= set(online)
    if refused:
        LOCAL['on'] = True
    left = [i for i in range(len(lines)) if i not in spoken]
    if left and refused:
        print(('voice: the local voice speaks %d line(s)' % len(left)) if LOCAL['on'] and refused[0].startswith('the local voice') else
              'voice: the online voice refuses (%s); the local voice speaks %d line(s)' % (refused[0], len(left)), flush=True)
    while left and refused:
        if over():
            break
        chunk = left[:chunk_size(takes)]                  # up to three lines at a time, fewer near the end of the budget, so a call ends in time
        if not chunk:                                     # not even one line can end before this call is cut: the same command continues
            print('voice: stopped with %d line(s) to speak (a line takes this machine too long for what is left of this call); run again' % len(left), flush=True)
            raise SystemExit(3)
        began = time.time()
        got = fallback_batch(takes, [(i, lines[i][1], lines[i][2]) for i in chunk])
        if not got:
            raise SystemExit('voice failed on line %s: %s' % (lines[chunk[0]][0], refused[0]))
        json.dump({'line': round((time.time() - began) / len(chunk), 1)}, open(os.path.join(takes, 'speed.json'), 'w', encoding='utf-8'))      # what a line cost today
        for i in got:
            mark_take(takes, i, lines[i][1], lines[i][2])
        spoken |= got
        left = [i for i in left if i not in got]
    if left:
        if over():
            print('voice: stopped at the time budget with %d line(s) to speak; run again' % len(left), flush=True)
            raise SystemExit(3)
        raise SystemExit('voice failed on line %s: no fallback voice on this machine' % lines[left[0]][0])
    out, tl, t = [np.zeros(int((LEAD + COLD['s']) * SR), dtype=np.float32)], [], LEAD + COLD['s']      # a cold open: the clip's seconds come before the first word
    for i, (lid, text, rate, pause) in enumerate(lines):
        a = load(os.path.join(takes, '%02d.mp3' % i))
        words = json.load(open(os.path.join(takes, '%02d.json' % i), encoding='utf-8'))
        loud = np.where(np.abs(a) > 0.012)[0]
        s0, s1 = max(0, loud[0] - int(0.015 * SR)), min(len(a), loud[-1] + int(0.06 * SR))
        a = a[s0:s1]
        fade = int(0.008 * SR)
        a[:fade] *= np.linspace(0, 1, fade); a[-fade:] *= np.linspace(1, 0, fade)
        off = t - s0 / SR
        ws = [{'w': w['w'], 's': round(off + w['s'], 3), 'e': round(off + w['s'] + w['d'], 3)} for w in words]
        dur = len(a) / SR
        tl.append({'id': lid, 'text': text, 's': round(t, 3), 'e': round(t + dur, 3), 'words': ws})
        out.append(a); out.append(np.zeros(int(pause * SR), dtype=np.float32))
        t += dur + pause
    return np.concatenate(out), tl, t


def keep_aside(takes, i):
    for f in take_files(takes, i):
        if os.path.exists(f):
            os.replace(f, f + '.best')


def back(takes, i):
    """The take kept aside comes back (the new one was not better, or was cut off)."""
    if not any(os.path.exists(f + '.best') for f in take_files(takes, i)):
        return
    drop_take(takes, i)
    for f in take_files(takes, i):
        if os.path.exists(f + '.best'):
            os.replace(f + '.best', f)


def hear_back(takes, lines, about, log):
    """Every take with a word the voice had to guess is heard back (voicecheck.py). A name that did not come back is said
    again another way, twice at most, and the new take is kept only when the ear hears it better.
    -> True when a take changed (the voice-over is put together again)."""
    import ears
    hfile = os.path.join(takes, 'heard.json')
    try:
        book = json.load(open(hfile, encoding='utf-8'))
    except Exception:  # noqa: BLE001
        book = {}
    book.setdefault('heard', {})
    book.setdefault('tries', {})
    save = lambda: json.dump(book, open(hfile, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    for i in range(len(lines)):                           # a try cut off by the end of a call: the take before it comes back
        back(takes, i)
    tag = lambda i: open(take_files(takes, i)[3], encoding='utf-8').read()
    audio = lambda i: next(f for f in take_files(takes, i)[:2] if os.path.isfile(f))

    def hear(idx):
        need = [i for i in idx if tag(i) not in book['heard']]
        if not need:
            return True
        if over():
            log('voice: stopped before hearing the takes back; run again')
            raise SystemExit(3)
        got = ears.heard([audio(i) for i in need], log, lang='en', min_p=0)
        if not got:
            return False
        for i in need:
            h = got.get(os.path.basename(audio(i)))
            if h is not None:
                ws = [w[0] for w in h['words']]
                book['heard'][tag(i)] = {'missed': voicecheck.missed(lines[i][1], ws), 'heard': ' '.join(ws)}
        save()
        return True

    todo = [i for i, l in enumerate(lines) if voicecheck.risky(l[1])]
    if not todo:
        return False
    if not hear(todo):
        log('voice: no ear on this machine: the names are not heard back')
        return False
    bad = lambda i: (book['heard'].get(tag(i)) or {}).get('missed') or []
    flagged = [i for i in todo if bad(i)]
    log('voice: %d take(s) with names heard back; %s' % (len(todo), 'every name came back' if not flagged else 'did not come back: ' + '; '.join(
        '%s (heard "%s")' % (x['word'], x['heard'][:40]) for i in flagged for x in bad(i))))
    changed, sure = False, book.setdefault('sure', {})    # sure: {name: its IPA or respelling} once the ear heard it right in one line
    for i in todo:
        while bad(i) and book['tries'].get(str(i), 0) < 2:
            k = book['tries'].get(str(i), 0)
            if over():                                    # a try is only started when it can end in this call
                log('voice: stopped before saying a name again; run again')
                raise SystemExit(3)
            miss, text = bad(i), lines[i][1]
            words, heard = [x['word'] for x in miss], {x['word']: x['heard'] for x in miss}
            old_send, old_said = SEND.get(str(i)), dict(SAID)
            if is_local(takes, i):                        # the local voice reads sounds: the sounds are asked again
                new = {w.lower(): sure[w] for w in words if w in sure} if k == 0 and all(w in sure for w in words) else voicewords.relearn(words, about, heard, log)
                if not new:
                    break
                SAID.update(new)
                how = ', '.join('%s /%s/' % (w, new.get(w.lower(), '?')) for w in words)
            else:                                         # the online voice reads text: the name is respelled
                sp = {} if all(w in sure for w in words) and k == 0 else voicecheck.respellings(words, about, heard, log)
                spell = dict(old_send[2] if old_send and old_send[0] == text and len(old_send) > 2 else {})
                spell.update({w: sure.get(w) if k == 0 and w in sure else sp[w][min(k, len(sp[w]) - 1)] for w in words if sp.get(w) or w in sure})
                if not any(w in spell for w in words):
                    break
                SEND[str(i)] = [text, voicecheck.respelled(text, spell), spell]
                json.dump(SEND, open(os.path.join(takes, 'send.json'), 'w', encoding='utf-8'), ensure_ascii=False)
                how = ', '.join('%s as "%s"' % (w, spell[w]) for w in words if w in spell)
            n_old = sum(x['n'] for x in miss)            # how far off it was, in consonants
            old_tag = tag(i)
            old_heard = book['heard'].pop(old_tag)        # the local voice's new take carries the same tag: it must be heard anew
            keep_aside(takes, i)
            speak(takes, lines)                           # only this line is missing: only it is spoken
            hear([i])
            book['tries'][str(i)] = k + 1                 # counted once heard: a try cut off by the end of a call is tried again
            save()
            if sum(x['n'] for x in bad(i)) < n_old:
                for f in take_files(takes, i):
                    if os.path.exists(f + '.best'):
                        os.remove(f + '.best')
                changed = True
                fixed = {x['word'] for x in miss} - {x['word'] for x in bad(i)}
                sure.update({w: v for w, v in ((w, SAID.get(w.lower()) if is_local(takes, i) else (SEND.get(str(i)) or [0, 0, {}])[2].get(w)) for w in fixed) if v})
                save()
                log('voice: line %s said again (%s): %s' % (lines[i][0], how, 'the ear hears it now' if not bad(i) else 'better, still unsure of ' + ', '.join(x['word'] for x in bad(i))))
            else:
                back(takes, i)
                book['heard'][old_tag] = old_heard
                save()
                SAID.clear()
                SAID.update(old_said)
                if old_send:
                    SEND[str(i)] = old_send
                else:
                    SEND.pop(str(i), None)
                json.dump(SEND, open(os.path.join(takes, 'send.json'), 'w', encoding='utf-8'), ensure_ascii=False)
                log('voice: line %s said again (%s) was not heard better: the earlier take stays' % (lines[i][0], how))
    left = [x['word'] for i in todo for x in bad(i)]
    if left:
        log("voice: the ear still does not hear %s; the voice's own reading stays" % ', '.join(dict.fromkeys(left)))
    return changed


def main(work):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    takes = os.path.join(work, 'takes'); os.makedirs(takes, exist_ok=True)
    TAKES.update(re.sub(r'\s+', ' ', str(t)).strip().lower() for t in plan.get('takes') or [])
    try:
        SEND.update(json.load(open(os.path.join(takes, 'send.json'), encoding='utf-8')))
    except Exception:  # noqa: BLE001
        pass
    lines = [[l['id'], l['text'], int(l.get('rate') or (10 if l['id'] in ('vote', 'site', 'final') else 12)),
              float(l.get('pause') or (0.5 if l['id'] == 'site' else 0.3 if l['id'] in ('vote', 'final') else 0.22))] for l in plan['lines']]
    for l, row in zip(plan['lines'], lines):                 # a bite plays in the pause after its line (a cold open: before the first line)
        if l.get('bite') and l['bite'].get('asset') in (plan.get('assets') or {}) and l['bite'].get('first'):
            COLD['s'] = round(l['bite']['dur'] + BITE_TAIL, 3)
        elif l.get('bite') and l['bite'].get('asset') in (plan.get('assets') or {}):
            row[3] = round(BITE_LEAD + l['bite']['dur'] + BITE_TAIL, 3)
        else:
            l.pop('bite', None)
    try:                                                  # the names the local voice does not know: looked up, or asked once and kept
        SAID.update(voicewords.learn([l[1] for l in lines], plan.get('title', ''), lambda *a: print(*a, flush=True)))
    except ai.OutOfTime as e:
        print('voice: %s; run again' % e, flush=True)
        raise SystemExit(3)
    need = ai.CONFIG['length']['min_s'] + 1.0
    rfile = os.path.join(takes, 'rates.json')
    if os.path.isfile(rfile):                             # a slower pace was chosen in an earlier run of this step: spoken at it from the first line
        for l, r in zip(lines, json.load(open(rfile, encoding='utf-8'))):
            l[2] = r
    for i in range(len(lines)):                           # a name said again was cut off by the end of a call: the take before it comes back first
        back(takes, i)
    vo, tl, t = speak(takes, lines)
    passes = len(json.load(open(rfile + '.passes', encoding='utf-8'))) if os.path.isfile(rfile + '.passes') else 0
    while t + 0.45 < need and passes < 2 and any(l[2] > SLOWEST for l in lines):      # short: once or twice more, slower by what is missing (a voice does not slow in proportion)
        silent = LEAD + COLD['s'] + sum(l[3] for l in lines)          # the pauses do not stretch: only the speech is slowed, by what the speech is missing
        slower = [max(SLOWEST, int((100 + r) * (t - silent) / max(1.0, need + 0.5 - silent) - 100) - (1 if passes else 0)) for _, _, r, _ in lines]
        if slower == [l[2] for l in lines]:
            break
        print('voice: %.1f s is under the minimum; spoken again at %+d%% instead of %+d%%' % (t + 0.45, slower[0], lines[0][2]), flush=True)
        json.dump(slower, open(rfile, 'w', encoding='utf-8'))
        passes += 1
        json.dump([1] * passes, open(rfile + '.passes', 'w', encoding='utf-8'))
        for l, r in zip(lines, slower):
            l[2] = r
        vo, tl, t = speak(takes, lines)
    if hear_back(takes, lines, plan.get('title', ''), lambda *a: print(*a, flush=True)):
        vo, tl, t = speak(takes, lines)
    short = need - (t + 0.45)
    if 0 < short <= 4.0:                                  # still short at the slowest pace: a little more breath between the lines, never filler
        gaps = [k for k, l in enumerate(lines[:-1]) if l[3] < 1.0]
        if gaps:
            add = min(0.4, short / len(gaps) + 0.02)
            for k in gaps:
                lines[k][3] = round(lines[k][3] + add, 3)
            print('voice: %.1f s short at the slowest pace; %.2f s more pause after %d lines' % (short, add, len(gaps)), flush=True)
            vo, tl, t = speak(takes, lines)
    vo = vo / (np.max(np.abs(vo)) + 1e-9) * 0.89
    with wave.open(os.path.join(work, 'vo.wav'), 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((vo * 32767).astype(np.int16).tobytes())
    spoken = sum(len(l['words']) for l in tl)
    tl = place_bites(plan, tl)
    bites = [l for l in tl if l.get('bite')]
    json.dump({'total': round(t, 3), 'lines': tl}, open(os.path.join(work, 'timeline.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('voice: %.1f s, %d lines, %d words%s' % (t, len(tl) - len(bites), spoken, ' | %d bite(s) of the story\'s own clips, %.1f s: %s' % (
        len(bites), sum(l['bite']['dur'] for l in bites), ' / '.join('"%s"' % l['text'][:60] for l in bites)) if bites else ''), flush=True)
    return t


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    if len(sys.argv) > 2:
        BUDGET['s'] = float(sys.argv[2])
    if len(sys.argv) > 3:
        BUDGET['left'] = float(sys.argv[3])
    main(sys.argv[1])
