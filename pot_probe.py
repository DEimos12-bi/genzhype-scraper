"""PO TOKEN PROBE (r142, 2026-09-06) — the one free YouTube door left untested.

Every other door is measured shut: 8 player clients from our server, cookies,
the tunnel, 9 public Invidious/Piped proxies, cobalt. yt-dlp's maintainers say
a Proof-of-Origin token "may help in some cases" for IP-flagged hosts. This
runs REAL story videos through yt-dlp with the official bgutil token provider
(HTTP server mode, started by the workflow) across the player clients the PO
Token Guide lists, and POSTs the verdict to our server's heartbeat log — the
GitHub Actions log is unreadable from the server (403 unauthenticated).
"""
import json
import os
import subprocess
import time

VIDEOS = [
    "jZgG4CvcZso",   # Kotaku: Overwatch x Skibidi Toilet (story p33)
    "tzD9OxAHtzU",   # story clip
    "y5ggehpY6bQ",   # story clip
]
CK = os.environ.get("CLIP_COOKIES", "")
INGEST_TOKEN = os.environ.get("INGEST_TOKEN", "").strip()
RECEIVE_URL = os.environ.get("VIDEO_RECEIVE_URL", "https://genzhype.com/api/video_receive.php")
POT = os.environ.get("POT_SERVER", "http://127.0.0.1:4416")

STRATEGIES = [
    ("plain (no token)",       ["--extractor-args", "youtubepot-bgutilhttp:base_url=http://127.0.0.1:1"]),  # provider unreachable = no token
    ("token, default clients", []),
    ("token + mweb",           ["--extractor-args", "youtube:player_client=mweb"]),
    ("token + tv",             ["--extractor-args", "youtube:player_client=tv"]),
    ("token + web_safari",     ["--extractor-args", "youtube:player_client=web_safari"]),
    ("token + web",            ["--extractor-args", "youtube:player_client=web"]),
    ("android_vr (no token)",  ["--extractor-args", "youtube:player_client=android_vr"]),
]
if CK and os.path.isfile(CK) and os.path.getsize(CK) > 100:
    STRATEGIES.append(("token + tv + cookies", ["--extractor-args", "youtube:player_client=tv", "--cookies", CK]))
    STRATEGIES.append(("token + web + cookies", ["--extractor-args", "youtube:player_client=web", "--cookies", CK]))

OUT = "probe_out"
os.makedirs(OUT, exist_ok=True)
report = {"at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "results": [], "wins": []}

ver = subprocess.run(["yt-dlp", "--version"], capture_output=True, text=True).stdout.strip()
report["yt_dlp"] = ver
print("yt-dlp:", ver, flush=True)
try:
    import urllib.request
    ping = urllib.request.urlopen(POT + "/ping", timeout=5).read().decode()[:120]
except Exception as e:  # noqa: BLE001
    ping = f"NO PROVIDER: {type(e).__name__}"
report["pot_ping"] = ping
print("token provider:", ping, flush=True)


def post(stage, note):
    body = {"token": INGEST_TOKEN, "action": "heartbeat", "page_id": 0,
            "stage": stage, "elapsed": 0, "note": note[:900]}
    if not INGEST_TOKEN:
        return
    try:
        from curl_cffi import requests as cffi
        cffi.post(RECEIVE_URL, json=body, impersonate="firefox", timeout=10,
                  headers={"User-Agent": "Mozilla/5.0"})
    except Exception as e:  # noqa: BLE001
        print("post failed:", e, flush=True)


for vid in VIDEOS:
    url = f"https://www.youtube.com/watch?v={vid}"
    print(f"=== {url}", flush=True)
    for name, flags in STRATEGIES:
        stem = os.path.join(OUT, f"{vid}-{abs(hash(name)) % 10**6}")
        cmd = ["yt-dlp", "--no-playlist", "--no-warnings", "--max-filesize", "30M",
               "-f", "b[height<=480]/b", "--download-sections", "*0-8",
               "-o", stem + ".%(ext)s"] + flags + [url]
        t0 = time.time()
        try:
            p = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
            lines = [l for l in (p.stderr or "").strip().splitlines() if l.strip()]
            err = lines[-1][:160] if lines else ""
        except subprocess.TimeoutExpired:
            err = "TIMEOUT after 120s"
        got = [f for f in os.listdir(OUT) if f.startswith(os.path.basename(stem))
               and os.path.getsize(os.path.join(OUT, f)) > 30000]
        ok = bool(got)
        ms = int((time.time() - t0) * 1000)
        kb = round(os.path.getsize(os.path.join(OUT, got[0])) / 1024) if ok else 0
        print(f"  {'OK  ' if ok else 'FAIL'} {name:<26} {ms:>6}ms  {str(kb) + 'KB' if ok else err}", flush=True)
        report["results"].append({"video": vid, "strategy": name, "ok": ok, "kb": kb, "ms": ms, "err": err})
        if ok:
            report["wins"].append(f"{vid}:{name}")
        time.sleep(2)

summary = {}
for r in report["results"]:
    summary.setdefault(r["strategy"], [0, 0])
    summary[r["strategy"]][1] += 1
    if r["ok"]:
        summary[r["strategy"]][0] += 1
line = " | ".join(f"{k}={v[0]}/{v[1]}" for k, v in summary.items())
report["summary"] = summary
with open(os.path.join(OUT, "report.json"), "w") as fh:
    json.dump(report, fh, indent=1)
print("SUMMARY:", line, flush=True)
post("POT_PROBE", f"yt-dlp {ver}; provider {ping[:40]}; {line}")
