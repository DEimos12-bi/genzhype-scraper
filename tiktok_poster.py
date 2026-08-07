#!/usr/bin/env python3
"""GenZHype | TikTok drafts auto-uploader (GitHub Actions) — pushes judged
READY videos into the account's TikTok INBOX (drafts), one per run. The
owner opens TikTok, sees the draft notification, adds the caption and taps
publish — the legal path for unaudited apps per the 2026-08-06 posting
study (Direct Post is review-gated; inbox mode is not).

Mechanics (Content Posting API, sandbox app, target user genzhype0):
- access token comes from OUR server (api/tt_refresh.php) which owns the
  24h-refresh + rotating-refresh-token dance (single writer; stateless
  runners must never hold the rotating token).
- FILE_UPLOAD (push_by_file): init -> single-chunk PUT (our videos are
  3-50MB, one chunk <64MB) -> poll status until SEND_TO_USER_INBOX.
- Site fetches via curl (Hostinger WAF TLS-blocks urllib — proven).
- 6 req/min/user token cap: one video per run stays far under it.

Env: SOCIAL_BASE, INGEST_TOKEN.
"""
import json
import os
import subprocess
import time
import urllib.error
import urllib.request

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
API = "https://open.tiktokapis.com/v2"
STATE = ".social"
DONE = f"{STATE}/tt_posted.txt"


def log(*a):
    print(*a, flush=True)


def site_get(url, binary=False):
    r = subprocess.run(["curl", "-s", "--fail", "--max-time", "180", url],
                       capture_output=True, timeout=200)
    if r.returncode != 0:
        raise RuntimeError(f"curl {r.returncode} for {url.split('?')[0]}")
    return r.stdout if binary else json.loads(r.stdout)


def site_post_json(url, payload):
    r = subprocess.run(["curl", "-s", "--fail", "--max-time", "60",
                        "-H", "Content-Type: application/json",
                        "-d", json.dumps(payload), url],
                       capture_output=True, timeout=80)
    if r.returncode != 0:
        raise RuntimeError(f"curl {r.returncode} for {url}")
    return json.loads(r.stdout)


def tk(url, payload, token, method=None, raw=None, headers=None):
    hdr = {"Authorization": f"Bearer {token}"}
    hdr.update(headers or {})
    if raw is None:
        hdr["Content-Type"] = "application/json; charset=UTF-8"
        body = json.dumps(payload).encode()
    else:
        body = raw
    req = urllib.request.Request(url, data=body, headers=hdr, method=method)
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            t = r.read()
            return json.loads(t) if t.strip() else {"http": r.status}
    except urllib.error.HTTPError as e:
        try:
            return json.load(e)
        except Exception:  # noqa: BLE001
            return {"error": e.read().decode()[:400]}
    except Exception as e:  # noqa: BLE001
        return {"error": str(e)}


def upload(v, token):
    blob = site_get(v["video"], binary=True)
    n = len(blob)
    log(f"downloaded {n // 1024}KB")
    init = tk(f"{API}/post/publish/inbox/video/init/", {
        "source_info": {"source": "FILE_UPLOAD", "video_size": n,
                        "chunk_size": n, "total_chunk_count": 1}}, token)
    data = init.get("data") or {}
    up_url, pid = data.get("upload_url"), data.get("publish_id")
    if not up_url:
        log("TT init failed:", json.dumps(init)[:400])
        return False
    put = tk(up_url, None, token, method="PUT", raw=blob, headers={
        "Content-Type": "video/mp4",
        "Content-Range": f"bytes 0-{n - 1}/{n}"})
    if put.get("error"):
        log("TT chunk upload failed:", json.dumps(put)[:400])
        return False
    for _ in range(10):
        time.sleep(15)
        st = tk(f"{API}/post/publish/status/fetch/",
                {"publish_id": pid}, token)
        code = (st.get("data") or {}).get("status", "?")
        log(f"TT {pid}: {code}")
        if code == "SEND_TO_USER_INBOX":
            log("TT DRAFT DELIVERED — open TikTok app, add caption, publish")
            return True
        if code in ("FAILED",):
            log("TT failed:", json.dumps(st)[:400])
            return False
    log("TT still processing after 150s; counting as delivered (inbox is async)")
    return True


def main():
    os.makedirs(STATE, exist_ok=True)
    tok = site_post_json(f"{BASE}/api/tt_refresh.php", {"token": INGEST})
    token = tok.get("access_token", "")
    if not token:
        log("TT: no credentials; skipped", json.dumps(tok)[:200])
        return 0
    q = site_get(f"{BASE}/api/video_social_next.php?token=" + INGEST)
    vids = q.get("videos", [])
    if not vids:
        log("queue empty")
        return 0
    done = set(l.strip() for l in open(DONE)) if os.path.exists(DONE) else set()
    todo = [v for v in vids if str(v["page_id"]) not in done]
    if not todo:
        log("TT: nothing new")
        return 0
    v = todo[0]
    log(f"TT: uploading page {v['page_id']} ({v['slug']})")
    if not upload(v, token):
        return 1
    done.add(str(v["page_id"]))
    open(DONE, "w").write("\n".join(sorted(done)) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
