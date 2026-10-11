"""The videos' own AI keys: which models answer, and in which order they are asked.
    python rotation.py                 tests every key this machine holds, one tiny request per model, and writes the
                                       orders into config.local.json ("ai") on this machine
    python rotation.py --only gemini2  tests that one key (several: gemini2,nvidia2) and keeps what it saw, so a
                                       machine with many keys can be tested a key at a time; then
    python rotation.py --write         writes the orders from everything seen so far
    python rotation.py --show          prints the orders in use and which models are put aside right now
A machine may hold several keys of one provider (keys file: GEMINI_API_KEY, GEMINI_API_KEY_2, ... NVIDIA_API_KEY_2 ...):
each is a provider of its own here ("gemini", "gemini2", ...). THE ORDERS:
  text    the jobs that decide a video (casting, script, pictures, fact check, corrections): the strongest model first,
          on every key that has it, then the next model. The strong models' small daily allowance is spent only here.
  light   the many small jobs (the researcher's steps, names in a transcript, small readings): the light models first,
          whose daily allowance is the largest; the strong ones only as a last resort. "reader" is the same order.
  vision  looking at pictures: light models first.
A model that answers "limit reached" during the test is KEPT (it exists and is only out for now); one that is gone or
answers wrongly is left out. During a run nothing here is needed: ai.py puts a model aside the moment it hits a limit
and asks the next one. Run this again when a key changes or once in a while: models come and go.
Only for a machine that has its own keys (config.local.json: "keys_file"); the committed config.json is not touched."""
import concurrent.futures
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.request

import localenv

localenv.load()
import ai  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
LOCAL = os.path.join(HERE, 'config.local.json')
SEEN = os.path.join(HERE, 'store', 'rotation_seen.json')      # what each key answered when it was last tested
LISTS = {'gemini': 'https://generativelanguage.googleapis.com/v1beta/openai/models', 'nvidia': 'https://integrate.api.nvidia.com/v1/models'}
NOT_CHAT = re.compile(r'lyria|veo|imagen|image|tts|audio|live|embed|transcribe|translate|computer-use|robotics|customtools|omni|banana|guard|safety|reward|parse|code|coder|deplot|detector|clip|'
                      r'calibration|topic-control|chatqa|-med-|-fin-|diffusion|recurrent|zamba|sea-lion|granite|llama2|muse|laguna|retriev|kosmos|fuyu|vila|neva|cosmos|gemma-2b|research|antigravity', re.I)
# the order inside a provider: the first pattern that matches a model's name gives its place
GEMINI = {'text': [r'gemini-3\.[5-9]-flash$', r'gemini-3(\.\d)?-flash', r'gemini-flash-latest', r'gemini-2\.5-flash$', r'flash-lite', r'gemma-4-31b', r'gemma', r'-pro'],
          'light': [r'gemini-3\.[5-9]-flash-lite$', r'flash-lite', r'gemma-4-31b', r'gemma', r'gemini-2\.5-flash$', r'gemini-flash-latest', r'gemini-3(\.\d)?-flash-preview', r'flash'],
          'vision': [r'gemini-3\.[5-9]-flash-lite$', r'flash-lite', r'gemma-4-31b', r'gemma-4', r'gemini-2\.5-flash$', r'gemini-3\.[5-9]-flash$', r'flash']}
NVIDIA = {'text': [r'deepseek-v', r'kimi-k3', r'mistral-large-2', r'nemotron-4-340b-instruct', r'mistral-large', r'glm-5\.3-flash', r'kimi-k2', r'nemotron-3-super', r'glm-5', r'gemma-4-31b',
                   r'nemotron-3\.5-lightning', r'nemotron-ultra', r'nemotron-70b', r'nemotron-3-ultra', r'palmyra-creative', r'gpt-oss', r'yi-large', r'jamba', r'nemotron-nano-3', r'gemma-3-12b',
                   r'mixtral', r'nemotron-51b', r'phi-3\.5', r'dbrx', r'mistral-nemo', r'mistral-7b', r'minitron'],
          'light': [r'nemotron-3\.5-lightning', r'glm-5\.3-flash', r'gemma-4-31b', r'nemotron-nano-3', r'gpt-oss', r'gemma-3-12b', r'mistral-nemo', r'nemotron-3-super', r'glm-5', r'kimi-k2', r'kimi-k3',
                    r'deepseek-v', r'mistral-large'],
          'vision': [r'llama-3\.2-90b-vision', r'gemma-4-31b', r'nano-omni', r'llama-3\.2-11b-vision', r'phi-3-vision', r'gemma-3-12b']}
TABLE = {'gemini': GEMINI, 'nvidia': NVIDIA}
LIMIT = re.compile(r'429|quota|resource_exhausted|rate limit|too many requests', re.I)


def providers():
    """Every key this machine holds, as providers: gemini, gemini2 ... nvidia, nvidia2 ..."""
    out = []
    for fam in ('gemini', 'nvidia'):
        out += [p for p in [fam] + ['%s%d' % (fam, n) for n in range(2, 13)] if ai.key(p)]
    return out


def listed(provider):
    req = urllib.request.Request(LISTS[ai.family(provider)], headers={'Authorization': 'Bearer ' + ai.key(provider), 'User-Agent': 'genzhype-video/3'})
    j = json.loads(urllib.request.urlopen(req, timeout=30).read().decode('utf-8', 'replace'))
    return sorted({str(m.get('id', '')).replace('models/', '') for m in j.get('data', []) if m.get('id')})


def place(name, patterns):
    return next((i for i, p in enumerate(patterns) if re.search(p, name)), None)


def newest_first(name):
    v = re.search(r'-(\d+(?:\.\d+)?)', name)
    return (-float(v.group(1)) if v else 0, 'preview' in name, name)


def ask(provider, model, picture=None):
    """One tiny request in the form every call of the system uses (a system part, strict JSON back). -> (seconds, '') or (None, why)"""
    user = 'Reply with this JSON and nothing else, with n = 2 + 2: {"ok": true, "n": 0}' if not picture else \
        'Which colour fills this picture? Reply with JSON and nothing else: {"colour": ""}'
    content = user if not picture else [{'type': 'text', 'text': user}, ai._image_part(picture)]
    t = time.time()
    try:
        text = ai._post(provider, model, [{'role': 'system', 'content': 'You answer in strict JSON only.'}, {'role': 'user', 'content': content}], 0.1, 40, 600, 'low')
        j = ai.parse_json(text)
        good = j.get('n') == 4 if not picture else 'red' in str(j.get('colour', '')).lower()
        return (round(time.time() - t, 1), '') if good else (None, 'wrong answer: ' + text[:60].replace('\n', ' '))
    except Exception as e:  # noqa: BLE001
        body = e.read().decode('utf-8', 'replace')[:900].replace('\n', ' ') if hasattr(e, 'read') else ''
        body = (re.search(r'limit:\s*\d+', body).group(0) + ' ' if re.search(r'limit:\s*\d+', body) else '') + body[:200]
        return None, ('%s %s' % (str(e)[:70], body)).strip()


def test(which):
    """One tiny request per model and kind for these providers; what each answered is kept in store/rotation_seen.json."""
    seen = json.load(open(SEEN, encoding='utf-8')) if os.path.isfile(SEEN) else {}
    red = os.path.join(tempfile.gettempdir(), 'v3_rotation_red.png')
    subprocess.run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=red:s=96x96', '-frames:v', '1', red], timeout=30)
    jobs = []
    for p in which:
        try:
            names = [n for n in listed(p) if not NOT_CHAT.search(n)]
        except Exception as e:  # noqa: BLE001
            print('%s: its list of models could not be read (%s)' % (p, str(e)[:80]))
            continue
        table = TABLE[ai.family(p)]
        for n in names:
            if place(n, table['text']) is not None:
                jobs.append((p, n, 'text'))
            if place(n, table['vision']) is not None and os.path.isfile(red):
                jobs.append((p, n, 'vision'))
    print('%d requests to send (one per model and kind) for %s ...' % (len(jobs), ', '.join(which)), flush=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
        results = list(pool.map(lambda j: ask(j[0], j[1], red if j[2] == 'vision' else None), jobs))
    for p in which:
        seen[p] = {}
    for (p, n, kind), (secs, why) in zip(jobs, results):
        state = 'ok' if secs is not None else 'no' if re.search(r'limit:\s*0\b', why) else 'limit' if LIMIT.search(why) else 'no'      # "limit: 0" = no free allowance at all for this model
        seen[p]['%s|%s' % (n, kind)] = {'state': state, 's': secs, 'why': why[:120]}
        print('  %-8s %-6s %-46s %s' % (p, kind, n, ('%.1f s' % secs) if secs is not None else ('AT ITS LIMIT NOW (kept)' if state == 'limit' else 'NO: ' + why[:80])), flush=True)
    os.makedirs(os.path.dirname(SEEN), exist_ok=True)
    json.dump(dict(seen, _at=time.strftime('%Y-%m-%d %H:%M')), open(SEEN, 'w', encoding='utf-8'), indent=1)


def write():
    """The orders, from everything the keys answered: for each family, model by model (the order of its table), every key that has that model."""
    seen = json.load(open(SEEN, encoding='utf-8')) if os.path.isfile(SEEN) else {}
    have = [p for p in providers() if isinstance(seen.get(p), dict)]
    order = {}
    for kind in ('text', 'light', 'vision'):
        order[kind] = []
        for fam in ('gemini', 'nvidia'):
            table, keys = TABLE[fam][kind], [p for p in have if ai.family(p) == fam]
            t = 'vision' if kind == 'vision' else 'text'
            answered = {k.split('|')[0] for p in keys for k, v in seen[p].items() if k.endswith('|' + t) and v['state'] == 'ok'}
            real = lambda n, v: v['state'] == 'ok' or (v['state'] == 'limit' and (n in answered or re.search(r'limit:\s*[1-9]', v.get('why', ''))))      # out for today, not a model without any free allowance
            names = sorted({k.split('|')[0] for p in keys for k, v in seen[p].items() if k.endswith('|' + t) and real(k.split('|')[0], v) and place(k.split('|')[0], table) is not None},
                           key=lambda n: (place(n, table),) + newest_first(n))
            for n in names:
                order[kind] += [p + '/' + n for p in keys if '%s|%s' % (n, t) in seen[p] and real(n, seen[p]['%s|%s' % (n, t)])]
    order['reader'] = order['light']
    if not order['text'] or not order['vision']:
        print('too few models answer (text %d, pictures %d): the order is NOT changed' % (len(order['text']), len(order['vision'])))
        return 2
    c = json.load(open(LOCAL, encoding='utf-8')) if os.path.isfile(LOCAL) else {}
    c['ai'] = dict(order, gemini_effort='low', timeout=100, checked=time.strftime('%Y-%m-%d %H:%M'))
    json.dump(c, open(LOCAL, 'w', encoding='utf-8'), indent=1)
    print('keys in the rotation: %s' % ', '.join(have))
    for kind in ('text', 'light', 'vision'):
        print('\n%s (%d): %s' % (kind, len(order[kind]), '  >  '.join(order[kind][:14]) + ('  > ...' if len(order[kind]) > 14 else '')))
    # do two keys of one provider share one allowance? Seen when one is at its limit on a model and another answers it.
    for fam in ('gemini', 'nvidia'):
        keys = [p for p in have if ai.family(p) == fam]
        if len(keys) > 1:
            rows = {}
            for p in keys:
                for k, v in seen[p].items():
                    rows.setdefault(k, {})[p] = v['state']
            mixed = [k for k, r in rows.items() if 'limit' in r.values() and 'ok' in r.values()]
            alike = [k for k, r in rows.items() if len(r) == len(keys) and set(r.values()) == {'limit'}]
            print('\n%s keys: %s' % (fam, ('on %d model(s) one key is at its limit while another answers: those keys have separate allowances (%s)' % (len(mixed), mixed[0]) if mixed else
                                          'every key is at its limit on the same %d model(s): they may share one allowance' % len(alike) if alike else 'no key was at a limit during the test: nothing can be said yet about shared allowances')))
    print('\nwritten to config.local.json (this machine only)')
    return 0


def main():
    if '--show' in sys.argv:
        bad = ai.strikes()
        for kind in ('text', 'light', 'vision'):
            print(kind + ':')
            for m in ai.models(kind):
                print('   %s%s' % (m, '   (put aside for %d more minutes)' % ((bad[m] - time.time()) / 60 + 1) if m in bad else ''))
        return 0
    have = providers()
    if not have:
        print('no key of its own on this machine (config.local.json: "keys_file")')
        return 2
    if '--write' in sys.argv:
        return write()
    which = have
    if '--only' in sys.argv:
        which = [p for p in sys.argv[sys.argv.index('--only') + 1].split(',') if p in have]
        if not which:
            print('none of those keys is on this machine; the keys here: %s' % ', '.join(have))
            return 2
    test(which)
    return write() if '--only' not in sys.argv else 0


if __name__ == '__main__':
    sys.exit(main())
