#!/usr/bin/env python3
"""GenZHype | performance-feedback-loop collector (GitHub Actions, daily).
Reads per-video stats for OUR posted videos and pushes snapshots to the
site (api/metrics_ingest.php), which stores them per page and marries
TikTok drafts to their published videos.

- YouTube: videos.list?part=statistics for the registered ids (needs the
  youtube.readonly scope on the refresh token — re-consent adds it; until
  then this logs the scope error and skips, harmless).
- TikTok: POST /v2/video/list/ on our own account (needs the video.list
  scope / Display API product — same graceful skip until granted).
- Site fetches via curl (Hostinger WAF TLS-blocks urllib — proven).

Env: SOCIAL_BASE, INGEST_TOKEN.
"""
import json
import os
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]


def log(*a):
    print(*a, flush=True)


def site(url, payload=None, tries=3):
    """Talk to our own site. Hostinger's WAF intermittently blackholes
    GitHub runner IPs (curl 28 timeouts) even though the endpoint answers
    in milliseconds elsewhere — so retry with backoff instead of losing a
    whole day's snapshot to one bad packet path."""
    cmd = ["curl", "-s", "--fail", "--max-time", "60"]
    if payload is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(payload)]
    last = ""
    for attempt in range(1, tries + 1):
        r = subprocess.run(cmd + [url], capture_output=True, timeout=90)
        if r.returncode == 0:
            return json.loads(r.stdout)
        last = f"curl {r.returncode}"
        log(f"  {last} on {url.split('?')[0]} (attempt {attempt}/{tries})")
        if attempt < tries:
            time.sleep(15 * attempt)
    raise RuntimeError(f"{last} for {url.split('?')[0]}")


def http_json(url, data=None, headers=None, form=False):
    body = None
    if data is not None:
        body = urllib.parse.urlencode(data).encode() if form else json.dumps(data).encode()
    req = urllib.request.Request(url, data=body, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return json.load(e)
        except Exception:  # noqa: BLE001
            return {"error": e.read().decode()[:300]}
    except Exception as e:  # noqa: BLE001
        return {"error": str(e)}


def collect_youtube(todo):
    creds = site(f"{BASE}/api/creds.php",
                 {"token": INGEST,
                  "want": ["yt_refresh", "yt_client_id", "yt_client_secret"]})["creds"]
    if not creds.get("yt_refresh"):
        log("YT: no credentials; skipped")
        return
    tok = http_json("https://oauth2.googleapis.com/token", {
        "client_id": creds["yt_client_id"], "client_secret": creds["yt_client_secret"],
        "refresh_token": creds["yt_refresh"], "grant_type": "refresh_token"}, form=True)
    at = tok.get("access_token")
    if not at:
        log("YT: token refresh failed", json.dumps(tok)[:200])
        return
    items = []
    for i in range(0, len(todo), 50):
        batch = ",".join(todo[i:i + 50])
        d = http_json("https://www.googleapis.com/youtube/v3/videos"
                      f"?part=statistics&id={batch}",
                      headers={"Authorization": f"Bearer {at}"})
        if d.get("error"):
            log("YT stats error (readonly scope granted yet?):",
                json.dumps(d["error"])[:200])
            return
        for v in d.get("items", []):
            s = v.get("statistics", {})
            items.append({"video_id": v["id"],
                          "views": int(s.get("viewCount", 0)),
                          "likes": int(s.get("likeCount", 0)),
                          "comments": int(s.get("commentCount", 0)),
                          "shares": 0})
    if items:
        r = site(f"{BASE}/api/metrics_ingest.php",
                 {"token": INGEST, "action": "stats", "platform": "yt",
                  "items": items})
        log(f"YT: {len(items)} videos ->", json.dumps(r))
        for it in items:
            log(f"  yt/{it['video_id']}: {it['views']} views, {it['likes']} likes")


def collect_tiktok():
    tok = site(f"{BASE}/api/tt_refresh.php", {"token": INGEST})
    at = tok.get("access_token")
    if not at:
        log("TT: no credentials; skipped")
        return
    d = http_json("https://open.tiktokapis.com/v2/video/list/"
                  "?fields=id,create_time,share_url,view_count,like_count,"
                  "comment_count,share_count",
                  data={"max_count": 20},
                  headers={"Authorization": f"Bearer {at}",
                           "Content-Type": "application/json"})
    err = (d.get("error") or {})
    if err.get("code") not in (None, "ok"):
        log("TT video.list error (scope granted yet?):", json.dumps(err)[:200])
        return
    vids = (d.get("data") or {}).get("videos", [])
    items = [{"video_id": str(v.get("id")),
              "created_at": int(v.get("create_time", 0)),
              "url": v.get("share_url", ""),
              "views": int(v.get("view_count", 0)),
              "likes": int(v.get("like_count", 0)),
              "comments": int(v.get("comment_count", 0)),
              "shares": int(v.get("share_count", 0))} for v in vids]
    if items:
        r = site(f"{BASE}/api/metrics_ingest.php",
                 {"token": INGEST, "action": "stats", "platform": "tt",
                  "items": items})
        log(f"TT: {len(items)} videos ->", json.dumps(r))
        for it in items:
            log(f"  tt/{it['video_id']}: {it['views']} views, {it['likes']} likes")
    else:
        log("TT: no published videos visible yet")


def main():
    plan = site(f"{BASE}/api/metrics_next.php?token=" + INGEST)
    yt_ids = plan.get("yt", [])
    if yt_ids:
        collect_youtube(yt_ids)
    else:
        log("YT: nothing registered yet")
    if (plan.get("tt") or {}).get("poll"):
        collect_tiktok()
    else:
        log("TT: nothing registered yet")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
