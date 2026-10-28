"""For a run on the owner's PC only: gives this run the same AI keys the site's brain uses, so the same code runs here
and on GitHub (where the keys are repository secrets). Two sources, set in config.local.json (never committed):
  "keys_from_ssh": {"host": "vsfssh", "config": "domains/genzhype.com/app/config.php"}
        the keys are read from the live site config over SSH when the run starts: always the current ones, and nothing
        is stored on this PC (they live in this run's memory only);
  "local_site_config": "C:/.../config.php"      a local copy of that config, if one is kept.
Nothing is printed. On GitHub this file does nothing."""
import json
import os
import re
import shutil
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
NAMES = ('GEMINI_API_KEY', 'OPENROUTER_API_KEY', 'NVIDIA_API_KEY', 'NVIDIA_B_API_KEY', 'NVIDIA_DIRECTOR_API_KEY', 'GROQ_API_KEY', 'CF_ACCOUNT_ID', 'CF_AI_TOKEN',
         'PEXELS_API_KEY', 'PIXABAY_API_KEY', 'YOUTUBE_KEY')
# runs on the machine that holds the config; prints one JSON object with exactly the values this system uses
PHP = ('$c = include "%s"; if (!is_array($c)) $c = $GLOBALS["CONFIG"] ?? []; $a = $c["ai"] ?? []; $r = $c["ai_rotation"] ?? []; '
       'echo json_encode(["GEMINI_API_KEY" => $a["gemini"]["key"] ?? "", "OPENROUTER_API_KEY" => $a["openrouter"]["key"] ?? "", "NVIDIA_API_KEY" => $a["nvidia"]["key"] ?? "", '
       '"NVIDIA_B_API_KEY" => $a["nvidia_b"]["key"] ?? "", "NVIDIA_DIRECTOR_API_KEY" => $a["nvidia_director"]["key"] ?? "", "GROQ_API_KEY" => $c["groq"]["key"] ?? ($r["groq_key"] ?? ""), '
       '"CF_ACCOUNT_ID" => $r["cf_account"] ?? "", "CF_AI_TOKEN" => $r["cf_token"] ?? "", "PEXELS_API_KEY" => $c["pexels_key"] ?? "", "PIXABAY_API_KEY" => $c["pixabay_key"] ?? "", '
       '"YOUTUBE_KEY" => $c["youtube_key"] ?? ""]);')


def _settings():
    p = os.path.join(HERE, 'config.local.json')
    return json.load(open(p, encoding='utf-8')) if os.path.isfile(p) else {}


def own_keys(s=None):
    """Keys kept for the videos ALONE (config.local.json: "keys_file", a JSON file OUTSIDE the repository, which is public).
    With them the videos have their own allowance and the site's AI keys are left alone."""
    p = (s if s is not None else _settings()).get('keys_file', '')
    try:
        d = json.load(open(p, encoding='utf-8')) if p and os.path.isfile(p) else {}
    except Exception:  # noqa: BLE001
        d = {}
    more = re.compile(r'(GEMINI|NVIDIA)_API_KEY_\d+$')           # further keys of one provider: each takes its turn (ai.key)
    return {k: v.strip() for k, v in d.items() if (k in NAMES or more.match(k)) and isinstance(v, str) and v.strip()}


def load():
    if os.environ.get('GITHUB_ACTIONS') or any(os.environ.get(n) for n in NAMES[:6]):       # GitHub's secrets, or a parent step already loaded them
        return 0
    s = _settings()
    own, site = own_keys(s), site_keys(s)
    keys = combined(own, site)
    n = 0
    for name in list(NAMES) + sorted(k for k in keys if k not in NAMES):
        v = keys.get(name)
        if isinstance(v, str) and v.strip() and not os.environ.get(name):
            os.environ[name] = v.strip()
            n += 1
    return n


def combined(own, site):
    """ALL the keys at once (owner, 2026-10-28: "combine the keys I gave you with the ones the system already has, use every
    API and every model"): the videos' own keys keep their names and come first; each AI key of the site that is not
    already one of them is added after them. A Gemini or NVIDIA key of the site takes the next free number of its
    provider (GEMINI_API_KEY_5, NVIDIA_API_KEY_4 ...): every numbered key is one more provider in the rotation (ai.key),
    so the site's NVIDIA_B and NVIDIA_DIRECTOR keys become numbered NVIDIA keys too. Groq, Cloudflare and OpenRouter
    keep their own names. The search and stock keys come from the site when the videos have none."""
    out = {k: v.strip() for k, v in own.items() if isinstance(v, str) and v.strip()}
    held = set(out.values())
    slots = {'GEMINI_API_KEY': 'GEMINI', 'NVIDIA_API_KEY': 'NVIDIA', 'NVIDIA_B_API_KEY': 'NVIDIA', 'NVIDIA_DIRECTOR_API_KEY': 'NVIDIA'}
    for name in NAMES:
        v = site.get(name, '')
        v = v.strip() if isinstance(v, str) else ''
        if not v or v in held:
            continue
        if name in slots:
            fam = slots[name]
            if fam + '_API_KEY' not in out:
                out[fam + '_API_KEY'] = v
            else:
                k = 2
                while '%s_API_KEY_%d' % (fam, k) in out:
                    k += 1
                out['%s_API_KEY_%d' % (fam, k)] = v
        elif name not in out:
            out[name] = v
        held.add(v)
    return out


def site_keys(s=None):
    """The site brain's keys, read when the run starts (see the top of this file). {} when they cannot be read."""
    s, out = s if s is not None else _settings(), ''
    local = os.environ.get('GENZHYPE_LOCAL_CONFIG') or s.get('local_site_config', '')
    try:
        if local and os.path.isfile(local):
            php = shutil.which('php') or 'C:/xampp/php/php.exe'
            out = subprocess.run([php, '-r', PHP % local.replace('\\', '/')], capture_output=True, text=True, timeout=20).stdout
        elif isinstance(s.get('keys_from_ssh'), dict) and s['keys_from_ssh'].get('host'):
            ssh = s['keys_from_ssh']
            out = subprocess.run(['ssh', '-o', 'ConnectTimeout=15', '-o', 'BatchMode=yes', ssh['host'], "php -r '%s'" % (PHP % ssh['config'])], capture_output=True, text=True, timeout=40).stdout
    except Exception:  # noqa: BLE001
        return {}
    try:
        keys = json.loads(out[out.index('{'):])
    except Exception:  # noqa: BLE001
        return {}
    return {k: v for k, v in keys.items() if isinstance(v, str)}
