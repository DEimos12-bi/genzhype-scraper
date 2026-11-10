"""Which AI keys answer on this machine: one tiny request per key, with the first model of the rotation that key has.
Prints the provider name and "answers" or why not; never a key.   python keycheck.py"""
import sys

import localenv
localenv.load()
import ai  # noqa: E402

PROVIDERS = ['gemini'] + ['gemini%d' % n for n in range(2, 13)] + ['nvidia'] + ['nvidia%d' % n for n in range(2, 13)] + ['nvidia_b', 'nvidia_director', 'groq', 'cloudflare', 'openrouter']


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    order = [m.split('/', 1) for kind in ('light', 'text') for m in ai.models(kind)]
    ok = bad = 0
    for p in PROVIDERS:
        if not ai.key(p):
            continue
        tried = [mo for pr, mo in order if pr == p][:3] or ['(no model of this key in the rotation)']
        why = ''
        for mo in tried:
            if mo.startswith('('):
                why = mo
                break
            try:
                ai._post(p, mo, [{'role': 'user', 'content': 'Say OK.'}], 0, 40, 20, None)
                print('%-16s answers (%s)' % (p, mo), flush=True)
                ok += 1
                why = ''
                break
            except Exception as e:  # noqa: BLE001
                why = '%s: %s' % (mo, str(e)[:80])
        if why:
            print('%-16s REFUSED %s' % (p, why), flush=True)
            bad += 1
    print('keys that answer: %d, refused: %d' % (ok, bad))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
