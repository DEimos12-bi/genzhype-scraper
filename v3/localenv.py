"""For a run on the owner's PC only: fills the environment with the keys of his local copy of the site's config
(its path is "local_site_config" in config.local.json; neither file is ever committed), so the same code runs here and on GitHub (where the keys are
repository secrets). Nothing is printed and nothing is written to disk. On GitHub this file does nothing."""
import json
import os
import shutil
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
_local = os.path.join(HERE, 'config.local.json')
LOCAL_CONFIG = os.environ.get('GENZHYPE_LOCAL_CONFIG') or (json.load(open(_local, encoding='utf-8')).get('local_site_config', '') if os.path.isfile(_local) else '')
MAP = {'GEMINI_API_KEY': ('ai', 'gemini', 'key'), 'NVIDIA_API_KEY': ('ai', 'nvidia', 'key'), 'OPENROUTER_API_KEY': ('ai', 'openrouter', 'key'),
       'PEXELS_API_KEY': ('pexels_key',), 'PIXABAY_API_KEY': ('pixabay_key',), 'YOUTUBE_KEY': ('youtube_key',)}


def load():
    if os.environ.get('GITHUB_ACTIONS') or not os.path.isfile(LOCAL_CONFIG):
        return 0
    php = shutil.which('php') or 'C:/xampp/php/php.exe'
    if not os.path.isfile(php) and not shutil.which('php'):
        return 0
    code = '$c = include %s; if (!is_array($c)) $c = $GLOBALS["CONFIG"] ?? []; echo json_encode($c);' % json.dumps(LOCAL_CONFIG)
    try:
        cfg = json.loads(subprocess.run([php, '-r', code], capture_output=True, text=True, timeout=20).stdout or '{}')
    except Exception:  # noqa: BLE001
        return 0
    n = 0
    for env, path in MAP.items():
        v = cfg
        for k in path:
            v = v.get(k) if isinstance(v, dict) else None
        if isinstance(v, str) and v.strip() and not os.environ.get(env):
            os.environ[env] = v.strip()
            n += 1
    return n
