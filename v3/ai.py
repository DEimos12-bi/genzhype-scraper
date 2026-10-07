"""The director's AI access: the system's own providers (keys from the environment), for text and for pictures.
    chat(system, user, images=[paths], kind='text'|'vision') -> (reply text, 'provider/model')   or raises AIError
    ask_json(...) -> (dict, 'provider/model')
The order of providers and models is in config.json ("ai"); a model that fails or refuses is skipped for the next one.
Keys are read from the environment only and are never printed. For a run on the owner's PC, localenv.py fills the
environment from his local config (see there)."""
import base64
import json
import os
import re
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
CONFIG = json.load(open(os.path.join(HERE, 'config.json'), encoding='utf-8'))
if os.path.isfile(os.path.join(HERE, 'config.local.json')):          # this machine's own settings (never committed)
    CONFIG.update(json.load(open(os.path.join(HERE, 'config.local.json'), encoding='utf-8')))
URLS = {
    'gemini': 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
    'groq': 'https://api.groq.com/openai/v1/chat/completions',
    'nvidia': 'https://integrate.api.nvidia.com/v1/chat/completions',
    'openrouter': 'https://openrouter.ai/api/v1/chat/completions',
    'anthropic': 'https://api.anthropic.com/v1/chat/completions',
    'openai': 'https://api.openai.com/v1/chat/completions',
}
ENV = {'gemini': ['GEMINI_API_KEY', 'AI_PROBE_GEMINI'], 'groq': ['GROQ_API_KEY'], 'nvidia': ['NVIDIA_API_KEY', 'AI_PROBE_NVIDIA'],
       'openrouter': ['OPENROUTER_API_KEY', 'AI_PROBE_OPENROUTER'], 'anthropic': ['ANTHROPIC_API_KEY'], 'openai': ['OPENAI_API_KEY']}
LOG = []          # one entry per request: provider/model, seconds, ok or the reason it failed


def note(entry):
    LOG.append(entry)
    if os.environ.get('V3_AI_LOG'):                           # make.py keeps every step's requests for the report
        with open(os.environ['V3_AI_LOG'], 'a', encoding='utf-8') as f:
            f.write(json.dumps(entry, ensure_ascii=False) + '\n')


class AIError(RuntimeError):
    pass


def key(provider):
    for name in ENV.get(provider, []):
        v = os.environ.get(name, '').strip()
        if v:
            return v
    return ''


def available(kind='text'):
    return [(p, m) for p, m in (x.split('/', 1) for x in CONFIG['ai'][kind]) if key(p)]


def _image_part(path, max_side=None):
    data = open(path, 'rb').read()
    mime = 'image/png' if path.lower().endswith('.png') else 'image/jpeg'
    return {'type': 'image_url', 'image_url': {'url': 'data:%s;base64,%s' % (mime, base64.b64encode(data).decode())}}


def _post(provider, model, messages, temperature, timeout, max_tokens):
    body = {'model': model, 'messages': messages, 'temperature': temperature, 'max_tokens': max_tokens}
    req = urllib.request.Request(URLS[provider], data=json.dumps(body).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + key(provider), 'User-Agent': 'genzhype-video/3'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        j = json.loads(r.read().decode('utf-8', 'replace'))
    text = ((j.get('choices') or [{}])[0].get('message') or {}).get('content') or ''
    if isinstance(text, list):
        text = ''.join(p.get('text', '') for p in text if isinstance(p, dict))
    return text.strip()


def chat(system, user, images=(), kind=None, temperature=0.5, timeout=120, max_tokens=6000, only=None, skip=()):
    kind = kind or ('vision' if images else 'text')
    content = user if not images else [{'type': 'text', 'text': user}] + [_image_part(p) for p in images]
    messages = [{'role': 'system', 'content': system}, {'role': 'user', 'content': content}]
    last = 'no AI key in the environment for: ' + ', '.join(CONFIG['ai'][kind])
    for provider, model in available(kind):
        if (only and provider not in only) or provider + '/' + model in skip:
            continue
        t = time.time()
        try:
            text = _post(provider, model, messages, temperature, timeout, max_tokens)
            if len(text) < 2:
                raise AIError('empty reply')
            note({'model': provider + '/' + model, 'kind': kind, 's': round(time.time() - t, 1), 'ok': True})
            return text, provider + '/' + model
        except urllib.error.HTTPError as e:
            last = '%s/%s: HTTP %s %s' % (provider, model, e.code, e.read().decode('utf-8', 'replace')[:160].replace('\n', ' '))
        except Exception as e:  # noqa: BLE001  (a timeout, a broken reply: the next model answers)
            last = '%s/%s: %s' % (provider, model, str(e)[:160])
        note({'model': provider + '/' + model, 'kind': kind, 's': round(time.time() - t, 1), 'ok': False, 'why': last[-170:]})
    raise AIError(last)


def parse_json(text):
    """The first JSON object in a reply (models wrap it in ``` fences or add a sentence around it)."""
    text = re.sub(r'^```(?:json)?\s*|\s*```\s*$', '', text.strip(), flags=re.M)
    a = text.find('{')
    if a < 0:
        raise ValueError('no JSON object in the reply')
    depth, instr, esc = 0, False, False
    for i in range(a, len(text)):
        c = text[i]
        if instr:
            esc = (c == '\\') and not esc
            if c == '"' and not esc:
                instr = False
            if c != '\\':
                esc = False
            continue
        if c == '"':
            instr = True
        elif c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                return json.loads(text[a:i + 1])
    return json.loads(text[a:])


def ask_json(system, user, images=(), tries=2, **kw):
    last, bad = None, []
    for _ in range(tries):                                    # a model whose reply was not JSON is passed over on the next try
        try:
            text, model = chat(system, user, images, skip=bad, **kw)
        except AIError:
            if not bad:
                raise
            bad = []
            continue
        bad.append(model)
        try:
            return parse_json(text), model
        except Exception as e:  # noqa: BLE001
            last = str(e)[:120]
    raise AIError('no valid JSON after %d tries: %s' % (tries, last))
