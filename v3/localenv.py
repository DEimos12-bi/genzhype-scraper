"""For a run on the owner's PC only: gives this run the same AI keys the site's brain uses, so the same code runs here
and on GitHub (where the keys are repository secrets). Two sources, set in config.local.json (never committed):
  "keys_from_ssh": {"host": "vsfssh", "config": "domains/genzhype.com/app/config.php"}
        the keys are read from the live site config over SSH when the run starts: always the current ones, and nothing
        is stored on this PC (they live in this run's memory only);
  "local_site_config": "C:/.../config.php"      a local copy of that config, if one is kept.
Nothing is printed. On GitHub this file does nothing."""
import json
import os
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
    return {k: v.strip() for k, v in d.items() if k in NAMES and isinstance(v, str) and v.strip()}


def load():
    if os.environ.get('GITHUB_ACTIONS') or any(os.environ.get(n) for n in NAMES[:6]):       # GitHub's secrets, or a parent step already loaded them
        return 0
    s = _settings()
    own, site = own_keys(s), site_keys(s)
    if any(k in own for k in NAMES[:8]):                       # the videos' own AI keys: of the site's, only the search and stock keys are borrowed
        site = {k: v for k, v in site.items() if k not in NAMES[:8]}
    n = 0
    for name in NAMES:
        v = own.get(name) or site.get(name)
        if isinstance(v, str) and v.strip() and not os.environ.get(name):
            os.environ[name] = v.strip()
            n += 1
    return n


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
