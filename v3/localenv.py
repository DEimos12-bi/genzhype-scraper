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


def load():
    if os.environ.get('GITHUB_ACTIONS') or any(os.environ.get(n) for n in NAMES[:6]):       # GitHub's secrets, or a parent step already loaded them
        return 0
    s, out = _settings(), ''
    local = os.environ.get('GENZHYPE_LOCAL_CONFIG') or s.get('local_site_config', '')
    try:
        if local and os.path.isfile(local):
            php = shutil.which('php') or 'C:/xampp/php/php.exe'
            out = subprocess.run([php, '-r', PHP % local.replace('\\', '/')], capture_output=True, text=True, timeout=20).stdout
        elif isinstance(s.get('keys_from_ssh'), dict) and s['keys_from_ssh'].get('host'):
            ssh = s['keys_from_ssh']
            out = subprocess.run(['ssh', '-o', 'ConnectTimeout=15', '-o', 'BatchMode=yes', ssh['host'], "php -r '%s'" % (PHP % ssh['config'])], capture_output=True, text=True, timeout=40).stdout
    except Exception:  # noqa: BLE001
        return 0
    try:
        keys = json.loads(out[out.index('{'):])
    except Exception:  # noqa: BLE001
        return 0
    n = 0
    for name in NAMES:
        v = keys.get(name)
        if isinstance(v, str) and v.strip() and not os.environ.get(name):
            os.environ[name] = v.strip()
            n += 1
    return n
