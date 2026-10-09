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
    'nvidia_b': 'https://integrate.api.nvidia.com/v1/chat/completions',            # the brain's second NVIDIA account
    'nvidia_director': 'https://integrate.api.nvidia.com/v1/chat/completions',     # the brain's director key
    'cloudflare': 'https://api.cloudflare.com/client/v4/accounts/%s/ai/v1/chat/completions',
    'openrouter': 'https://openrouter.ai/api/v1/chat/completions',
    'anthropic': 'https://api.anthropic.com/v1/chat/completions',
    'openai': 'https://api.openai.com/v1/chat/completions',
}
ENV = {'gemini': ['GEMINI_API_KEY', 'AI_PROBE_GEMINI'], 'groq': ['GROQ_API_KEY'], 'nvidia': ['NVIDIA_API_KEY', 'AI_PROBE_NVIDIA'], 'nvidia_b': ['NVIDIA_B_API_KEY'],
       'nvidia_director': ['NVIDIA_DIRECTOR_API_KEY', 'AI_PROBE_NVIDIA_DIRECTOR'], 'cloudflare': ['CF_AI_TOKEN'],
       'openrouter': ['OPENROUTER_API_KEY', 'AI_PROBE_OPENROUTER'], 'anthropic': ['ANTHROPIC_API_KEY'], 'openai': ['OPENAI_API_KEY']}
LOG = []          # one entry per request: provider/model, seconds, ok or the reason it failed


def note(entry):
    LOG.append(entry)
    if os.environ.get('V3_AI_LOG'):                           # make.py keeps every step's requests for the report
        with open(os.environ['V3_AI_LOG'], 'a', encoding='utf-8') as f:
            f.write(json.dumps(entry, ensure_ascii=False) + '\n')


class AIError(RuntimeError):
    pass


class OutOfTime(Exception):
    """The caller's time limit (DEADLINE) is reached while an answer is awaited: not a failure of the model. The step
    stops and is run again; nothing is held against the model."""


DEADLINE = None    # set by a step whose caller has a time limit (director.py with a budget): no answer is awaited past it


def key(provider):
    if provider == 'cloudflare' and not os.environ.get('CF_ACCOUNT_ID', '').strip():
        return ''
    for name in ENV.get(provider, []):
        v = os.environ.get(name, '').strip()
        if v:
            return v
    return ''


def strikes(add=None, why=''):
    """Models that failed, each with the time until which it is passed over, kept in a small file so that the next step and
    the next run do not wait on them again: a daily quota that ran out = 3 hours, a model that is gone = a day, a
    too-many-requests answer = 2 minutes, a timeout or an empty answer = 15 minutes. They stay as a last resort."""
    path = os.environ.get('V3_AI_STRIKES', '')
    try:
        d = json.load(open(path, encoding='utf-8')) if path and os.path.isfile(path) else {}
    except Exception:  # noqa: BLE001
        d = {}
    d = {k: t for k, t in d.items() if t > time.time()}
    if add:
        low = why.lower()
        hold = 10800 if ('quota' in low or 'per-day' in low or 'per day' in low or 'daily' in low or 'neurons' in low or '(tpd)' in low) else 86400 if ('http 404' in low or 'http 410' in low or 'http 403' in low) else 120 if 'http 429' in low else 900
        d[add] = time.time() + hold
        if path:
            json.dump(d, open(path, 'w', encoding='utf-8'))
    return d


def models(kind):
    """The order of models for one kind of call: 'text' writes, 'vision' looks at pictures, 'reader' checks what was
    written (other models first, so that the writer's small free allowance is kept for writing)."""
    return CONFIG['ai'].get(kind) or CONFIG['ai']['text']


def available(kind='text'):
    out, bad = [(p, m) for p, m in (x.split('/', 1) for x in models(kind)) if key(p)], strikes()
    return [x for x in out if '/'.join(x) not in bad] + [x for x in out if '/'.join(x) in bad]


def _image_part(path, max_side=None):
    data = open(path, 'rb').read()
    mime = 'image/png' if path.lower().endswith('.png') else 'image/jpeg'
    return {'type': 'image_url', 'image_url': {'url': 'data:%s;base64,%s' % (mime, base64.b64encode(data).decode())}}


def _post(provider, model, messages, temperature, timeout, max_tokens, effort):
    body = {'model': model, 'messages': messages, 'temperature': temperature, 'max_tokens': min(max_tokens, {'cloudflare': 4096, 'groq': 8192}.get(provider, max_tokens))}
    if provider == 'gemini' and effort and model.startswith('gemini-'):                       # without a cap its thinking uses up the answer's room and the JSON arrives cut off
        body['reasoning_effort'] = effort
    url = URLS[provider] % os.environ['CF_ACCOUNT_ID'].strip() if provider == 'cloudflare' else URLS[provider]
    req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + key(provider), 'User-Agent': 'genzhype-video/3'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        j = json.loads(r.read().decode('utf-8', 'replace'))
    text = ((j.get('choices') or [{}])[0].get('message') or {}).get('content') or ''
    if isinstance(text, list):
        text = ''.join(p.get('text', '') for p in text if isinstance(p, dict))
    return text.strip()


def chat(system, user, images=(), kind=None, temperature=0.5, timeout=120, max_tokens=8000, only=None, skip=(), effort=None, patient=False):
    """patient=True (the writing calls): a model that answers "too many requests, try again in N seconds" is waited for
    once, up to 50 seconds, instead of handing the writing to a weaker model at once."""
    kind = kind or ('vision' if images else 'text')
    content = user if not images else [{'type': 'text', 'text': user}] + [_image_part(p) for p in images]
    messages = [{'role': 'system', 'content': system}, {'role': 'user', 'content': content}]
    last = 'no AI key in the environment for: ' + ', '.join(models(kind))
    slow = set()
    for provider, model in available(kind):
        if (only and provider not in only) or provider + '/' + model in skip:
            continue
        if (provider[:6], model) in slow:                      # it just timed out on a sister account: it is as slow on this one
            strikes(add=provider + '/' + model, why='timed out on a sister account')
            continue
        for again in (False, True):
            t, pause = time.time(), 0
            left = None if DEADLINE is None else DEADLINE - t
            if left is not None and left < 12:
                raise OutOfTime('the time limit of this run is reached before %s/%s could be asked' % (provider, model))
            wait_for = timeout if left is None else min(timeout, left)
            try:
                text = _post(provider, model, messages, temperature, wait_for, max_tokens, effort or CONFIG['ai'].get('gemini_effort', 'low'))
                if len(text) < 2:
                    raise AIError('empty reply')
                note({'model': provider + '/' + model, 'kind': kind, 's': round(time.time() - t, 1), 'ok': True})
                return text, provider + '/' + model
            except urllib.error.HTTPError as e:
                body = e.read().decode('utf-8', 'replace')
                last = '%s/%s: HTTP %s %s' % (provider, model, e.code, body[:420].replace('\n', ' '))
                wait = re.search(r'try again in (?:(\d+)m)?([\d.]+)s', body)
                if patient and not again and e.code == 429 and wait and '(tpd)' not in body.lower() and 'per day' not in body.lower():
                    pause = int(wait.group(1) or 0) * 60 + float(wait.group(2)) + 1
            except Exception as e:  # noqa: BLE001  (a timeout, a broken reply: the next model answers)
                last = '%s/%s: %s' % (provider, model, str(e)[:160])
                if wait_for < timeout and time.time() - t >= wait_for - 1:      # cut short by the caller's limit, not by the model
                    note({'model': provider + '/' + model, 'kind': kind, 's': round(time.time() - t, 1), 'ok': False, 'why': 'stopped at the time limit of this run after %d s' % wait_for})
                    if wait_for >= 60:                         # it had a fair wait and gave nothing: the next run asks the next model, not
                        for p2, m2 in available(kind):         # this one again (the same model on a sister account is as slow)
                            if m2 == model and p2[:6] == provider[:6]:
                                strikes(add=p2 + '/' + m2, why='too slow for the time limit of the run')
                    raise OutOfTime('the time limit of this run was reached while %s/%s was answering' % (provider, model))
                if 'timed out' in str(e).lower():
                    slow.add((provider[:6], model))
            note({'model': provider + '/' + model, 'kind': kind, 's': round(time.time() - t, 1), 'ok': False, 'why': last[-330:]})
            if 0 < pause <= 50:
                time.sleep(pause)                              # the per-minute limit of the strong writer: worth one wait
                continue
            strikes(add=provider + '/' + model, why=last)
            break
    raise AIError(last)


def _loads(s):
    """A comma left before a closing bracket is the commonest slip in a model's JSON: it is dropped (never inside a
    string) and the reply is read again, instead of the whole answer being thrown away."""
    try:
        return json.loads(s)
    except ValueError:
        out, instr, esc = [], False, False
        for c in s:
            if instr:
                out.append(c)
                if esc:
                    esc = False
                elif c == '\\':
                    esc = True
                elif c == '"':
                    instr = False
                continue
            if c == '"':
                instr = True
            elif c in '}]':
                k = len(out) - 1
                while k >= 0 and out[k] in ' \t\r\n':
                    k -= 1
                if k >= 0 and out[k] == ',':
                    del out[k]
            out.append(c)
        return json.loads(''.join(out))


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
                return _loads(text[a:i + 1])
    return _loads(text[a:])


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
            note({'model': model, 'kind': kw.get('kind') or ('vision' if images else 'text'), 's': 0, 'ok': False,
                  'why': 'its reply was not valid JSON (%s); %d chars, ending: %s' % (last, len(text), text[-120:].replace('\n', ' '))})
    raise AIError('no valid JSON after %d tries: %s' % (tries, last))
