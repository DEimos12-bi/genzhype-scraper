#!/usr/bin/env python3
"""GenZHype | forum intelligence collector (r79).

Reads the CRAFT subreddits — where working creators trade what's actually
working this week — and ships the top posts to the site's intel store
(api/intel_ingest.php). The daily brief AI then reads them alongside press
and trend finds.

Fetch pattern copied from the proven reddit_radar.py fallback: subreddit RSS
via curl_cffi browser impersonation, zero credentials needed. Read-only, no
posting, no login — the domain-safety rule (a flagged domain is dead forever)
never comes into play because we never touch a write endpoint.

Env: INGEST_TOKEN. Optional: INTEL_SUBS (comma list), SOCIAL_BASE.
"""
import json
import os
import re
import sys
import urllib.request

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0 Safari/537.36")
DEFAULT_SUBS = ["NewTubers", "PartneredYoutube", "youtubers", "Twitch",
                "TikTokCreators", "InstagramMarketing"]
SUBS = [s.strip() for s in os.environ.get("INTEL_SUBS", ",".join(DEFAULT_SUBS)).split(",") if s.strip()]

try:
    from curl_cffi import requests as cffi

    def http_get(url):
        r = cffi.get(url, impersonate="firefox", timeout=25,
                     headers={"User-Agent": UA}, allow_redirects=True)
        r.raise_for_status()
        return r.content
except Exception:  # noqa: BLE001
    def http_get(url):
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        with urllib.request.urlopen(req, timeout=25) as r:
            return r.read()


def entries(xml):
    """Minimal atom parse: (title, link, updated) per entry."""
    for m in re.finditer(rb"<entry>(.*?)</entry>", xml, re.S):
        e = m.group(1)
        t = re.search(rb"<title>(.*?)</title>", e, re.S)
        l = re.search(rb'<link href="(.*?)"', e)
        u = re.search(rb"<updated>(.*?)</updated>", e)
        if t and l:
            title = re.sub(rb"<.*?>", b"", t.group(1)).decode("utf-8", "ignore")
            title = title.replace("&amp;", "&").replace("&#39;", "'").replace("&quot;", '"')
            yield (title.strip(),
                   l.group(1).decode("utf-8", "ignore"),
                   (u.group(1).decode("ascii", "ignore")[:10] if u else ""))


def main():
    finds = []
    for sub in SUBS:
        try:
            xml = http_get(f"https://www.reddit.com/r/{sub}/top/.rss?t=day&limit=10")
        except Exception as e:  # noqa: BLE001
            print(f"  ! r/{sub}: {e}", file=sys.stderr)
            continue
        n = 0
        for title, link, updated in entries(xml):
            finds.append({"kind": "reddit", "url": link, "title": title,
                          "source": f"r/{sub}", "published": updated,
                          "score": 0, "note": ""})
            n += 1
        print(f"r/{sub}: {n} post(s)")
    if not finds:
        print("no finds collected (all subs unreachable?)")
        return 1
    body = json.dumps({"token": INGEST, "finds": finds}).encode()
    req = urllib.request.Request(
        f"{BASE}/api/intel_ingest.php", data=body,
        headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            print("ingest:", r.read().decode()[:200])
    except Exception as e:  # noqa: BLE001
        # WAF may drop this runner; try curl as the proven fallback
        import subprocess
        r = subprocess.run(["curl", "-s", "--max-time", "60",
                            "-H", "Content-Type: application/json",
                            "-d", body.decode(),
                            f"{BASE}/api/intel_ingest.php"],
                           capture_output=True, timeout=80)
        print("ingest(curl):", (r.stdout or b"?").decode()[:200], str(e)[:80])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
