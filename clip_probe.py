#!/usr/bin/env python3
"""CLIP DOWNLOAD PROBE (r95) — find out why TikTok refuses us, and what works.

Page 131's render passed every gate we built tonight and still shipped with no
footage: the clips were selected, protected from the vision veto, and then the
DOWNLOAD failed. The maker threw yt-dlp's stderr away, so "fetch failed" was
the whole story. This runs the real URLs through the strategies we could use
and prints exactly what each one says, so the fix is chosen from evidence
rather than guessed at.
"""
import json
import os
import subprocess
import sys
import time

URLS = [
    # r97: does Instagram/Facebook behave like YouTube (wants a login)
    # or like TikTok (refuses us outright)? Measured, not assumed — the
    # "instagram is ungettable" line in the picker was a guess of mine.
    "https://www.facebook.com/thriftingmomof1/videos/cake-gate-cake-by-kaity-is-calling-out-another-creator-over-an-alleged-recipe-th/1004449218855230/",
    "https://www.facebook.com/OfficialOffTopicShow/videos/black-womans-viral-tiktok-rant-pleads-to-leave-america-amidst-ice-raids-and-poli/1462050688301001/",
    "https://www.instagram.com/reel/DMYqNJ1Rvbk/",
]
CK = os.environ.get("CLIP_COOKIES", "")

# name -> extra flags. Ordered cheapest/most-likely-legal first.
STRATEGIES = [
    ("as the maker does now", ["-f", "b[height<=720]/b", "--impersonate", "chrome",
                               "--download-sections", "*0-10",
                               "--force-keyframes-at-cuts"]),
    ("no section window",     ["-f", "b[height<=720]/b", "--impersonate", "chrome"]),
    ("no format constraint",  ["--impersonate", "chrome"]),
    ("no impersonation",      []),
    ("impersonate safari",    ["--impersonate", "safari"]),
    ("mobile api extractor",  ["--impersonate", "chrome",
                               "--extractor-args", "tiktok:api_hostname=api22-normal-c-useast2a.tiktokv.com"]),
]
if CK and os.path.isfile(CK):
    STRATEGIES.append(("chrome + cookies", ["--impersonate", "chrome", "--cookies", CK]))

OUT = "probe_out"
os.makedirs(OUT, exist_ok=True)
report = []

print("yt-dlp:", subprocess.run(["yt-dlp", "--version"], capture_output=True,
                                text=True).stdout.strip(), flush=True)

# r95b: every strategy failed with the SAME parse error in under a second —
# not a block, a broken extractor. TikTok changes its page constantly and the
# fix lands in nightly long before the stable release. If nightly reads the
# page, the answer is simply which build we install.
if os.environ.get("PROBE_NIGHTLY") == "1":
    print("installing yt-dlp nightly...", flush=True)
    subprocess.run([sys.executable, "-m", "pip", "install", "-q", "-U", "--pre",
                    "yt-dlp[default,curl-cffi]"], check=False)
    print("now:", subprocess.run(["yt-dlp", "--version"], capture_output=True,
                                 text=True).stdout.strip(), flush=True)
imp = subprocess.run(["yt-dlp", "--list-impersonate-targets"],
                     capture_output=True, text=True).stdout
print("impersonation targets available:", "chrome" in imp.lower(), flush=True)
print()

for u in URLS:
    print(f"=== {u}", flush=True)
    for name, flags in STRATEGIES:
        stem = os.path.join(OUT, f"{abs(hash(name + u)) % 10**9}")
        cmd = (["yt-dlp", "--no-playlist", "--no-warnings", "--max-filesize", "45M",
                "-o", stem + ".%(ext)s"] + flags + [u])
        t0 = time.time()
        try:
            p = subprocess.run(cmd, capture_output=True, text=True, timeout=90)
            err = (p.stderr or "").strip().splitlines()
            err = err[-1][:150] if err else ""
        except subprocess.TimeoutExpired:
            err = "TIMEOUT after 90s"
        got = [f for f in os.listdir(OUT)
               if f.startswith(os.path.basename(stem))
               and os.path.getsize(os.path.join(OUT, f)) > 30000]
        ms = int((time.time() - t0) * 1000)
        ok = bool(got)
        size = round(os.path.getsize(os.path.join(OUT, got[0])) / 1024) if ok else 0
        print(f"  {'OK  ' if ok else 'FAIL'} {name:<24} {ms:>6}ms  "
              f"{str(size) + 'KB' if ok else err}", flush=True)
        report.append({"url": u, "strategy": name, "ok": ok, "kb": size, "err": err})
        if ok:
            break          # first strategy that works is the answer for this URL
    print(flush=True)

with open(f"{OUT}/report.json", "w") as fh:
    json.dump(report, fh, indent=2)

wins = {}
for r in report:
    if r["ok"]:
        wins[r["strategy"]] = wins.get(r["strategy"], 0) + 1
print("WINNERS:", wins if wins else "NOTHING WORKED — TikTok is refusing this runner outright")
