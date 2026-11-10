"""Which of the brain's models answer right now, and how fast: one small request to each candidate, text and picture.
Run it when the videos' AI feels slow or after adding a key; put the fast, good ones first in config.json ("ai").
usage: probe.py [picture.jpg]      (keys come from the environment / localenv, never printed)"""
import json
import os
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

import localenv

localenv.load()
import ai  # noqa: E402

TEXT_Q = 'Write a JSON object {"lines": [...]} with 8 punchy sentences (14 words each) telling a football match as a story. JSON only.'
SKIP = ('embed', 'guard', 'safety', 'whisper', 'tts', 'rerank', 'retriev', 'nano', 'coder', 'code', 'bge', 'parakeet', 'nv-', 'audio', 'ocr', 'detect', 'segment', 'translate', 'prompt-guard', 'orpheus', 'distil')


def listed(provider):
    url = {'groq': 'https://api.groq.com/openai/v1/models', 'nvidia': 'https://integrate.api.nvidia.com/v1/models', 'nvidia_b': 'https://integrate.api.nvidia.com/v1/models',
           'nvidia_director': 'https://integrate.api.nvidia.com/v1/models', 'gemini': 'https://generativelanguage.googleapis.com/v1beta/openai/models',
           'cloudflare': 'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search?per_page=200' % os.environ.get('CF_ACCOUNT_ID', '')}.get(provider)
    if not url or not ai.key(provider):
        return []
    try:
        j = json.loads(urllib.request.urlopen(urllib.request.Request(url, headers={'Authorization': 'Bearer ' + ai.key(provider), 'User-Agent': 'genzhype-video/3'}), timeout=25).read().decode())
    except Exception as e:  # noqa: BLE001
        print('%s: model list not available (%s)' % (provider, str(e)[:60])); return []
    if provider == 'cloudflare':
        return sorted(m['name'] for m in j.get('result', []) if (m.get('task') or {}).get('name') in ('Text Generation', 'Image-to-Text'))
    return sorted(m['id'].replace('models/', '') for m in j.get('data', []))


def ask(provider, model, picture=None):
    content = TEXT_Q if not picture else [{'type': 'text', 'text': 'JSON only: {"big_text": "the largest text in the picture", "people": number of people}'}, ai._image_part(picture)]
    t = time.time()
    try:
        txt = ai._post(provider, model, [{'role': 'user', 'content': content}], 0.3, 28, 1500, 'low')
        try:
            ai.parse_json(txt); ok = 'JSON ok'
        except Exception:  # noqa: BLE001
            ok = 'not JSON' if txt else 'EMPTY'
        return (time.time() - t, '%-16s %-46s %-7s %5.1fs  %s' % (provider, model, 'picture' if picture else 'text', time.time() - t, ok))
    except urllib.error.HTTPError as e:
        return (999, '%-16s %-46s %-7s  HTTP %s %s' % (provider, model, 'picture' if picture else 'text', e.code, ' '.join(e.read().decode('utf-8', 'replace').split())[:60]))
    except Exception as e:  # noqa: BLE001
        return (999, '%-16s %-46s %-7s  FAIL %s' % (provider, model, 'picture' if picture else 'text', str(e)[:44]))


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    picture = sys.argv[1] if len(sys.argv) > 1 else None
    have = [p for p in ai.URLS if ai.key(p)]
    print('providers with a key here:', ', '.join(have))
    jobs = []
    for p in have:
        names = [m for m in listed(p) if not any(k in m.lower() for k in SKIP)]
        print('%s: %d chat models listed' % (p, len(names)))
        pic = [m for m in names if any(k in m.lower() for k in ('vision', 'llava', 'gemini', 'gemma-4', 'pixtral', '-vl', 'omni', 'scout', 'maverick'))]
        good = ('gpt-oss-120b', 'gpt-5', 'llama-4', 'llama-3.3-70b', 'qwen3', 'deepseek', 'kimi', 'glm', 'gemini', 'gemma-4', 'nemotron-3-ultra', 'nemotron-3-super', 'nemotron-3.5', 'mistral', 'gpt-oss-20b', 'maverick', 'scout')
        names = sorted([m for m in names if any(g in m.lower() for g in good)], key=lambda m: min(i for i, g in enumerate(good) if g in m.lower()))
        for m in names[:9]:
            jobs.append((p, m, None))
        if picture:
            for m in pic[:4]:
                jobs.append((p, m, picture))
    print('%d requests going out' % len(jobs), flush=True)
    with ThreadPoolExecutor(14) as ex:
        for _, line in ex.map(lambda j: ask(*j), jobs):
            print(line, flush=True)


if __name__ == '__main__':
    main()
