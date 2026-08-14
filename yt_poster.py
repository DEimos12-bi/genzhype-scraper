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
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

# feedback loop: report what got posted so the metrics collector can poll it
def register_post(base, ingest, platform, page_id, video_id=None, url=None):
    payload = {"token": ingest, "action": "register", "platform": platform,
               "page_id": page_id, "video_id": video_id, "url": url}
    r = subprocess.run(["curl", "-s", "--max-time", "60",
                        "-H", "Content-Type: application/json",
                        "-d", json.dumps(payload),
                        f"{base}/api/metrics_ingest.php"],
                       capture_output=True, timeout=80)
    print("registry:", (r.stdout or b"?").decode()[:120], flush=True)

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
REFRESH = os.environ.get("YT_REFRESH", "")
CID = os.environ.get("YT_CLIENT_ID", "")
SEC = os.environ.get("YT_CLIENT_SECRET", "")
STATE = ".social"
DONE = f"{STATE}/yt_posted.txt"
POLICY = {}          # r114: filled from the queue endpoint's "policy" block


def log(*a):
    print(*a, flush=True)


def jpost(url, data, headers=None, raw=False, method=None):
    body = data if raw else urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(url, data=body, headers=headers or {},
                                 method=method)
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


def site_get(url, binary=False):
    """Fetch from OUR site via curl: Hostinger's WAF TLS-blocks Python's
    urllib (the original scraper lesson — runs died exactly here while the
    workflow's curl steps passed). Google endpoints stay on urllib."""
    r = subprocess.run(["curl", "-s", "--fail", "--max-time", "180", url],
                       capture_output=True, timeout=200)
    if r.returncode != 0:
        raise RuntimeError(f"curl {r.returncode} for {url.split('?')[0]}")
    return r.stdout if binary else json.loads(r.stdout)


def access_token():
    tok, _ = jpost("https://oauth2.googleapis.com/token", {
        "client_id": CID, "client_secret": SEC,
        "refresh_token": REFRESH, "grant_type": "refresh_token"})
    return tok.get("access_token", "")


def upload(v, token):
    """Resumable upload: init -> PUT bytes. Returns True on success."""
    blob = site_get(v["video"], binary=True)
    log(f"downloaded {len(blob) // 1024}KB")
    meta = {
        "snippet": {
            # Shorts rulebook: only ~40 chars are visible in the feed, so
            # the title leads with the subject (the site composes it) and
            # hashtags live in the description, never the title.
            "title": (v.get("yt_title") or v["title"])[:95],
            "description": v.get("yt_desc") or (v["caption"] + "\n" + v["link"]),
            "tags": v.get("yt_tags") or [],
            "categoryId": "24",  # Entertainment (25=News draws stricter review)
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
    res, _ = jpost(put_url, blob, raw=True, method="PUT", headers={
        "Authorization": f"Bearer {token}", "Content-Type": "video/mp4"})
    vid = res.get("id")
    if not vid:
        log("YT upload failed:", json.dumps(res)[:400])
        return False
    priv = (res.get("status") or {}).get("privacyStatus", "?")
    log(f"YT UPLOADED https://youtube.com/shorts/{vid} privacy={priv}"
        + ("  (audit not passed yet -> locked private)" if priv != "public" else ""))
    register_post(BASE, INGEST, "yt", v["page_id"], vid,
                  f"https://youtube.com/shorts/{vid}")
    first_comment(vid, v, token)
    return True


def first_comment(vid, v, token):
    """Post the sourced-timeline link as the first comment. On a 0-comment
    Short it sits on top anyway; pinning it is a manual Studio step (the
    Data API has no pin endpoint). Needs the youtube.force-ssl scope — if
    the grant predates it, this logs and moves on."""
    body = {"snippet": {"videoId": vid, "topLevelComment": {"snippet": {
        "textOriginal": "Every receipt in this video is sourced and dated "
                        "here: " + v["link"]}}}}
    res, _ = jpost("https://www.googleapis.com/youtube/v3/commentThreads"
                   "?part=snippet", json.dumps(body).encode(), raw=True,
                   headers={"Authorization": f"Bearer {token}",
                            "Content-Type": "application/json"})
    if res.get("id"):
        log("  first comment posted (pin it manually in Studio)")
    else:
        log("  first comment skipped:", json.dumps(res.get("error", res))[:180])


def main():
    if not (REFRESH and CID and SEC):
        log("YT: no credentials; skipped")
        return 0
    os.makedirs(STATE, exist_ok=True)
    q = site_get(f"{BASE}/api/video_social_next.php?token="
                 + urllib.parse.quote(INGEST))
    vids = q.get("videos", [])
    global POLICY
    POLICY = q.get("policy") or {}          # r114: learned posting policy
    if not vids:
        log("queue empty")
        return 0
    done = set(l.strip() for l in open(DONE)) if os.path.exists(DONE) else set()
    todo = [v for v in vids if str(v["page_id"]) not in done]

    # r120 LANE GATE. The channel averaged 5 views a Short because it mixes
    # celeb + gaming + memes and YouTube's 2026 watch-history clusters cannot
    # classify it; the one breakout (88 views) was the Asmongold/Twitch story,
    # and the celeb-gossip giants we track are literally single-digit-subs ON
    # YOUTUBE (Pop Crave 480, popbase 1) while DramaAlert/Dexerto own the
    # streamer lane. So this channel posts ONLY lane-fit stories, decided
    # server-side (yt_fit). Posting off-lane would actively un-classify the
    # channel, so an empty fit-list means we WAIT, not fall back.
    # Kill switch: YT_LANE_GATE=0. Payloads without the field pass (old feed).
    if os.environ.get("YT_LANE_GATE", "1") != "0":
        fit = [v for v in todo if v.get("yt_fit", True)]
        if len(fit) != len(todo):
            log(f"YT lane gate: {len(todo) - len(fit)} off-lane story(ies) held back,"
                f" {len(fit)} fit")
        todo = fit
    if not todo:
        log("YT: nothing new")
        return 0

    # Slot logic (Shorts rulebook): this runs hourly, but publishes only in
    # the two US-audience slots — UNLESS the story is fresh news (<12h),
    # where freshness is an official ranking factor and waiting costs reach.
    hour = time.gmtime().tm_hour
    slots = [int(h) for h in os.environ.get("YT_SLOT_HOURS", "16,23").split(",")]
    # r114: the intelligence engine measures which hours this lane actually
    # publishes in, and the site serves that as policy.slot_hours next to the
    # copy. It is ADDITIVE (the engine adds at most one well-evidenced rival
    # hour to ours) and it never shrinks our windows — so a bad or empty rule
    # can only ever leave the hardcoded slots exactly as they were.
    learned = [int(h) for h in (POLICY.get("slot_hours") or [])
               if isinstance(h, int) or str(h).isdigit()]
    if learned:
        merged = sorted(set(slots) | set(h for h in learned if 0 <= h <= 23))
        if merged != sorted(set(slots)):
            log(f"YT: slots {sorted(set(slots))} + learned {sorted(set(learned) - set(slots))}"
                f" -> {merged}")
        slots = merged
    hot = [v for v in todo if v.get("hot")]
    if hour in slots:
        todo = hot + [v for v in todo if not v.get("hot")]
    elif hot:
        log(f"YT: hot story outside slots (hour {hour}) — publishing now")
        todo = hot
    else:
        log(f"YT: hour {hour} is not a slot {slots} and nothing is hot; waiting")
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
