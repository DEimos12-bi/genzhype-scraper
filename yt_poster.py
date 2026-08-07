#!/usr/bin/env python3
"""GenZHype | YouTube Shorts auto-uploader (GitHub Actions) — uploads judged
READY videos to the channel, one per run.

Built from the 2026-08-06 posting study (verified on Google docs):
- Quota model (Dec 2025): videos.insert = dedicated 100 uploads/day bucket.
  We post ~2-4/day — never near it.
- THE GATE: until the project passes the YouTube API compliance audit
  (yt_api_form), every API upload is FORCE-PRIVATE even though the API
  returns success. We upload anyway (queue drains, audit unlock is
  retroactive for future posts) and LOG the returned privacyStatus so the
  day it flips to "public" is visible in the run log.
- Shorts need no flag: vertical + <=3min = auto-Short.
- containsSyntheticMedia=true set as disclosure insurance (TTS narration).
- Auth: refresh token minted once via site api/yt_oauth.php ("In
  production" consent screen -> token never expires in practice). Creds
  fetched at runtime from the server vault (public repo, zero secrets here).

Env: SOCIAL_BASE, INGEST_TOKEN,
     YT_REFRESH + YT_CLIENT_ID + YT_CLIENT_SECRET (from vault via workflow).
"""
import json
import os
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
REFRESH = os.environ.get("YT_REFRESH", "")
CID = os.environ.get("YT_CLIENT_ID", "")
SEC = os.environ.get("YT_CLIENT_SECRET", "")
STATE = ".social"
DONE = f"{STATE}/yt_posted.txt"


def log(*a):
    print(*a, flush=True)


def jpost(url, data, headers=None, raw=False):
    body = data if raw else urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(url, data=body, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            return json.load(r), dict(r.headers)
    except urllib.error.HTTPError as e:
        try:
            return json.load(e), {}
        except Exception:  # noqa: BLE001
            return {"error": e.read().decode()[:400]}, {}
    except Exception as e:  # noqa: BLE001
        return {"error": str(e)}, {}


def access_token():
    tok, _ = jpost("https://oauth2.googleapis.com/token", {
        "client_id": CID, "client_secret": SEC,
        "refresh_token": REFRESH, "grant_type": "refresh_token"})
    return tok.get("access_token", "")


def upload(v, token):
    """Resumable upload: init -> PUT bytes. Returns True on success."""
    with urllib.request.urlopen(v["video"], timeout=300) as r:
        blob = r.read()
    log(f"downloaded {len(blob) // 1024}KB")
    meta = {
        "snippet": {
            "title": v["caption"].split("\n")[0][:95],
            "description": v["caption"] + "\n" + v["link"],
            "categoryId": "24",  # Entertainment
        },
        "status": {
            "privacyStatus": "public",
            "selfDeclaredMadeForKids": False,
            "containsSyntheticMedia": True,
        },
    }
    init_url = ("https://www.googleapis.com/upload/youtube/v3/videos"
                "?uploadType=resumable&part=snippet,status")
    req = urllib.request.Request(init_url, data=json.dumps(meta).encode(),
                                 headers={
                                     "Authorization": f"Bearer {token}",
                                     "Content-Type": "application/json",
                                     "X-Upload-Content-Type": "video/mp4",
                                     "X-Upload-Content-Length": str(len(blob)),
                                 })
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            put_url = r.headers.get("Location", "")
    except urllib.error.HTTPError as e:
        log("YT init failed:", e.read().decode()[:400])
        return False
    if not put_url:
        log("YT init gave no upload URL")
        return False
    res, _ = jpost(put_url, blob, raw=True, headers={
        "Authorization": f"Bearer {token}", "Content-Type": "video/mp4"})
    vid = res.get("id")
    if not vid:
        log("YT upload failed:", json.dumps(res)[:400])
        return False
    priv = (res.get("status") or {}).get("privacyStatus", "?")
    log(f"YT UPLOADED https://youtube.com/shorts/{vid} privacy={priv}"
        + ("  (audit not passed yet -> locked private)" if priv != "public" else ""))
    return True


def main():
    if not (REFRESH and CID and SEC):
        log("YT: no credentials; skipped")
        return 0
    os.makedirs(STATE, exist_ok=True)
    with urllib.request.urlopen(
            f"{BASE}/api/video_social_next.php?token="
            + urllib.parse.quote(INGEST), timeout=60) as r:
        q = json.load(r)
    vids = q.get("videos", [])
    if not vids:
        log("queue empty")
        return 0
    done = set(l.strip() for l in open(DONE)) if os.path.exists(DONE) else set()
    todo = [v for v in vids if str(v["page_id"]) not in done]
    if not todo:
        log("YT: nothing new")
        return 0
    token = access_token()
    if not token:
        log("YT: token refresh failed")
        return 1
    v = todo[0]
    log(f"YT: posting page {v['page_id']} ({v['slug']})")
    if not upload(v, token):
        return 1
    done.add(str(v["page_id"]))
    open(DONE, "w").write("\n".join(sorted(done)) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
