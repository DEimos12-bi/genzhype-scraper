#!/usr/bin/env python3
"""GenZHype | Reels/video auto-poster (GitHub Actions) — posts judged READY
videos to Instagram Reels + the Facebook Page, one per platform per run.

Built from the 2026-08-06 posting study (all rules verified on Meta docs):
- IG (Instagram-Login path, graph.instagram.com): POST /{IG_ID}/media with
  media_type=REELS + a PUBLIC video_url -> poll the container status_code
  (once/minute, <=5 minutes, per Meta guidance) -> media_publish.
  is_ai_generated=true is set: Meta REQUIRES disclosure for realistic
  digitally-created audio (our TTS narration), penalty language attached.
- FB (Page): POST /{PAGE_ID}/videos with file_url (remote pull — no chunk
  upload needed) + description. (FB *Reels* proper has a 90s cap + 3-phase
  upload; the plain video post has neither. Upgrade later if wanted.)
- Runtime quota check: reads content_publishing_limit before posting (the
  study found Meta's own docs disagree 50 vs 100/day — never hardcode).
- Tokens come from the server vault (api/creds.php) at runtime; the repo is
  public, so no token ever lives here. State in .social/ like every poster.

Env: SOCIAL_BASE, INGEST_TOKEN, IG_USER_ID, FB_PAGE_ID,
     IG_ACCESS_TOKEN + FB_PAGE_TOKEN (fetched by the workflow from the vault).
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
IG_ID = os.environ.get("IG_USER_ID", "")
IG_TOKEN = os.environ.get("IG_ACCESS_TOKEN", "")
FB_PAGE = os.environ.get("FB_PAGE_ID", "")
FB_TOKEN = os.environ.get("FB_PAGE_TOKEN", "")
IG_GRAPH = "https://graph.instagram.com/v21.0"
FB_GRAPH = "https://graph.facebook.com/v21.0"
STATE = ".social"
IG_DONE = f"{STATE}/reels_posted_ig.txt"
FB_DONE = f"{STATE}/reels_posted_fb.txt"
IG_TOKF = f"{STATE}/ig_token.txt"


def log(*a):
    print(*a, flush=True)


def call(url, data=None):
    try:
        req = urllib.request.Request(
            url, data=urllib.parse.urlencode(data).encode()) if data else url
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return json.load(e)
        except Exception:  # noqa: BLE001
            return {"error": e.read().decode()[:300]}
    except Exception as e:  # noqa: BLE001
        return {"error": str(e)}


def site_get(url):
    """Fetch from OUR site via curl: Hostinger's WAF TLS-blocks Python's
    urllib (proven in the yt-poster's first run). Graph APIs stay on urllib."""
    r = subprocess.run(["curl", "-s", "--fail", "--max-time", "120", url],
                       capture_output=True, timeout=140)
    if r.returncode != 0:
        return {"error": f"curl {r.returncode}"}
    try:
        return json.loads(r.stdout)
    except Exception as e:  # noqa: BLE001
        return {"error": str(e)}


def load_done(path):
    return set(l.strip() for l in open(path)) if os.path.exists(path) else set()


def save_done(path, done):
    open(path, "w").write("\n".join(sorted(done)) + "\n")


def post_ig(v, token):
    """IG Reels: container -> poll -> publish. Returns True on success."""
    quota = call(f"{IG_GRAPH}/{IG_ID}/content_publishing_limit"
                 f"?fields=quota_usage&access_token={token}")
    usage = ((quota.get("data") or [{}])[0]).get("quota_usage")
    log(f"IG quota usage: {usage}")
    cont = call(f"{IG_GRAPH}/{IG_ID}/media", {
        "media_type": "REELS",
        "video_url": v["video"],
        "caption": v["caption"],
        "is_ai_generated": "true",     # AI-voice disclosure (Meta-required)
        "access_token": token})
    cid = cont.get("id")
    if not cid:
        log("IG container failed:", cont)
        return False
    for _ in range(5):                 # poll <=5 times, 60s apart (Meta rule)
        time.sleep(60)
        st = call(f"{IG_GRAPH}/{cid}?fields=status_code&access_token={token}")
        code = st.get("status_code")
        log(f"IG container {cid}: {code}")
        if code == "FINISHED":
            break
        if code in ("ERROR", "EXPIRED"):
            log("IG container died:", st)
            return False
    else:
        log("IG container not ready after 5 minutes; leaving for next run")
        return False
    pub = call(f"{IG_GRAPH}/{IG_ID}/media_publish",
               {"creation_id": cid, "access_token": token})
    if not pub.get("id"):
        log("IG publish failed:", pub)
        return False
    log(f"IG PUBLISHED media {pub['id']}")
    return True


def post_fb(v):
    """FB Page video via remote file_url pull. Returns True on success."""
    r = call(f"{FB_GRAPH}/{FB_PAGE}/videos", {
        "file_url": v["video"],
        "description": v["caption"] + "\n" + v["link"],
        "access_token": FB_TOKEN})
    if not r.get("id"):
        log("FB video failed:", r)
        return False
    log(f"FB PUBLISHED video {r['id']}")
    return True


def main():
    os.makedirs(STATE, exist_ok=True)
    q = site_get(f"{BASE}/api/video_social_next.php"
                 f"?token={urllib.parse.quote(INGEST)}")
    vids = q.get("videos", [])
    if not vids:
        log("queue empty")
        return 0
    rc = 0

    if IG_ID and IG_TOKEN:
        token = (open(IG_TOKF).read().strip()
                 if os.path.exists(IG_TOKF) else IG_TOKEN)
        ref = call(f"{IG_GRAPH}/refresh_access_token"
                   f"?grant_type=ig_refresh_token&access_token={token}")
        if ref.get("access_token"):
            token = ref["access_token"]
            open(IG_TOKF, "w").write(token)
            log(f"IG token refreshed (~{int(ref.get('expires_in', 0)) // 86400}d)")
        done = load_done(IG_DONE)
        todo = [v for v in vids if str(v["page_id"]) not in done]
        if todo:
            v = todo[0]
            log(f"IG: posting page {v['page_id']} ({v['slug']})")
            if post_ig(v, token):
                done.add(str(v["page_id"]))
                save_done(IG_DONE, done)
            else:
                rc = 1
        else:
            log("IG: nothing new")
    else:
        log("IG: no credentials; skipped")

    # r64: owner set different per-platform volumes (IG Reels 5/day, FB 3/day)
    # but this one poster serves BOTH. REELS_SKIP_FB=1 lets the extra IG-only
    # runs skip Facebook so each platform hits its own number.
    if FB_PAGE and FB_TOKEN and os.environ.get("REELS_SKIP_FB", "0") != "1":
        done = load_done(FB_DONE)
        todo = [v for v in vids if str(v["page_id"]) not in done]
        if todo:
            v = todo[0]
            log(f"FB: posting page {v['page_id']} ({v['slug']})")
            if post_fb(v):
                done.add(str(v["page_id"]))
                save_done(FB_DONE, done)
            else:
                rc = 1
        else:
            log("FB: nothing new")
    else:
        log("FB: no credentials; skipped")
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
