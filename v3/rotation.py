"""The videos' own AI keys: which models answer, and in which order they are asked.
    python rotation.py            asks each key for its models, sends ONE tiny request to every candidate, and writes
                                  the order of those that answer into config.local.json ("ai") on this machine
    python rotation.py --show     prints the order in use and which models are put aside right now
The order: every Gemini model first, then every NVIDIA model. Inside Gemini, the writing goes to the strongest Flash
model first and the many small calls (checks, looks at pictures) to the light ones, whose daily allowance is the
largest. During a run nothing here is needed: ai.py puts a model aside the moment it hits a limit and asks the next
one (see ai.chat). Run this again when a key changes or once in a while: models come and go.
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
LISTS = {'gemini': 'https://generativelanguage.googleapis.com/v1beta/openai/models', 'nvidia': 'https://integrate.api.nvidia.com/v1/models'}
NOT_CHAT = re.compile(r'image|tts|audio|live|embed|transcribe|translate|computer-use|robotics|customtools|omni|banana|guard|safety|reward|parse|code|coder|deplot|detector|clip|'
                      r'calibration|topic-control|chatqa|-med-|-fin-|diffusion|recurrent|zamba|sea-lion|granite|llama2|muse|laguna|retriev|kosmos|fuyu|vila|neva|cosmos|gemma-2b|research|antigravity', re.I)
# the order inside a key: the first pattern that matches a model's name gives its place
GEMINI = {'text': [r'gemini-3\.[5-9]-flash$', r'gemini-3(\.\d)?-flash', r'gemini-flash-latest', r'gemini-2\.5-flash$', r'flash-lite', r'gemma-4-31b', r'gemma', r'-pro'],
          'reader': [r'gemini-3\.[5-9]-flash-lite$', r'flash-lite', r'gemma-4-31b', r'gemma', r'gemini-3\.[5-9]-flash$', r'flash'],
          'vision': [r'gemini-3\.[5-9]-flash-lite$', r'flash-lite', r'gemini-3\.[5-9]-flash$', r'flash', r'gemma-4-31b', r'gemma-4']}
NVIDIA = {'text': [r'deepseek-v', r'kimi-k3', r'mistral-large-2', r'nemotron-4-340b-instruct', r'mistral-large', r'glm-5\.3-flash', r'kimi-k2', r'nemotron-3-super', r'glm-5', r'gemma-4-31b',
                   r'nemotron-3\.5-lightning', r'nemotron-ultra', r'nemotron-70b', r'nemotron-3-ultra', r'palmyra-creative', r'gpt-oss', r'yi-large', r'jamba', r'nemotron-nano-3', r'gemma-3-12b',
                   r'mixtral', r'nemotron-51b', r'phi-3\.5', r'dbrx', r'mistral-nemo', r'mistral-7b', r'minitron'],
          'vision': [r'llama-3\.2-90b-vision', r'gemma-4-31b', r'nano-omni', r'llama-3\.2-11b-vision', r'phi-3-vision', r'gemma-3-12b']}
NVIDIA['reader'] = NVIDIA['text']


def listed(provider):
    req = urllib.request.Request(LISTS[provider], headers={'Authorization': 'Bearer ' + ai.key(provider), 'User-Agent': 'genzhype-video/3'})
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
        text = ai._post(provider, model, [{'role': 'system', 'content': 'You answer in strict JSON only.'}, {'role': 'user', 'content': content}], 0.1, 45, 600, 'low')
        j = ai.parse_json(text)
        good = j.get('n') == 4 if not picture else 'red' in str(j.get('colour', '')).lower()
        return (round(time.time() - t, 1), '') if good else (None, 'wrong answer: ' + text[:60].replace('\n', ' '))
    except Exception as e:  # noqa: BLE001
        body = e.read().decode('utf-8', 'replace')[:160].replace('\n', ' ') if hasattr(e, 'read') else ''
        return None, ('%s %s' % (str(e)[:70], body)).strip()


def main():
    if '--show' in sys.argv:
        bad = ai.strikes()
        for kind in ('text', 'reader', 'vision'):
            print(kind + ':')
            for m in ai.models(kind):
                print('   %s%s' % (m, '   (put aside for %d more minutes)' % ((bad[m] - time.time()) / 60 + 1) if m in bad else ''))
        return 0
    providers = [p for p in ('gemini', 'nvidia') if ai.key(p)]
    if not providers:
        print('no key of its own on this machine (config.local.json: "keys_file")')
        return 2
    red = os.path.join(tempfile.gettempdir(), 'v3_rotation_red.png')
    subprocess.run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=red:s=96x96', '-frames:v', '1', red], timeout=30)
    jobs = []                                                 # (provider, model, kind of test)
    for p in providers:
        try:
            names = [n for n in listed(p) if not NOT_CHAT.search(n)]
        except Exception as e:  # noqa: BLE001
            print('%s: its list of models could not be read (%s)' % (p, str(e)[:80]))
            continue
        table = GEMINI if p == 'gemini' else NVIDIA
        for n in names:
            if place(n, table['text']) is not None:
                jobs.append((p, n, 'text'))
            if place(n, table['vision']) is not None and os.path.isfile(red):
                jobs.append((p, n, 'vision'))
    print('%d requests to send (one per model and kind) ...' % len(jobs), flush=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=10) as pool:
        results = list(pool.map(lambda j: ask(j[0], j[1], red if j[2] == 'vision' else None), jobs))
    ok = {}
    for (p, n, kind), (secs, why) in zip(jobs, results):
        print('  %-7s %-6s %-48s %s' % (p, kind, n, ('%.1f s' % secs) if secs is not None else 'NO: ' + why[:110]), flush=True)
        if secs is not None:
            ok[(p, n, kind)] = secs
    order = {}
    for kind in ('text', 'reader', 'vision'):
        order[kind] = []
        for p in providers:
            table = (GEMINI if p == 'gemini' else NVIDIA)[kind]
            mine = [n for (pp, n, k) in ok if pp == p and k == ('vision' if kind == 'vision' else 'text') and place(n, table) is not None]
            order[kind] += [p + '/' + n for n in sorted(mine, key=lambda n: (place(n, table),) + newest_first(n))]
    if not order['text'] or not order['vision']:
        print('too few models answer (text %d, pictures %d): the order is NOT changed' % (len(order['text']), len(order['vision'])))
        return 2
    c = json.load(open(LOCAL, encoding='utf-8')) if os.path.isfile(LOCAL) else {}
    c['ai'] = dict(order, gemini_effort='low', timeout=100, checked=time.strftime('%Y-%m-%d %H:%M'))
    json.dump(c, open(LOCAL, 'w', encoding='utf-8'), indent=1)
    for kind in ('text', 'reader', 'vision'):
        print('\n%s (%d): %s' % (kind, len(order[kind]), '  >  '.join(m.split('/', 1)[1] for m in order[kind])))
    print('\nwritten to config.local.json (this machine only)')
    return 0


if __name__ == '__main__':
    sys.exit(main())
