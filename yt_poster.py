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
LAST = f"{STATE}/yt_last_post.txt"   # 2026-10-15: unix time of the last upload (the catch-up rule below)
CATCHUP_GAP_H = 2                      # the slots are 2 hours apart; a missed slot is made up no sooner than that
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


def upload(v, token, publish_at=None):
    """Resumable upload: init -> PUT bytes. Returns True on success.
    publish_at (ISO, UTC): uploaded PRIVATE with that publish time, and YouTube itself makes it public then
    (2026-11-01, owner: every platform on time; GitHub starts this hourly job only some of the hours)."""
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
    if publish_at:
        meta["status"]["privacyStatus"] = "private"          # the API publishes a scheduled video from private only
        meta["status"]["publishAt"] = publish_at
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
    if publish_at:
        got = (res.get("status") or {}).get("publishAt", "")
        log(f"YT SCHEDULED https://youtube.com/shorts/{vid} goes public at {got or publish_at}"
            + ("" if got else "  (YouTube did not confirm the publish time)"))
    else:
        log(f"YT UPLOADED https://youtube.com/shorts/{vid} privacy={priv}"
            + ("  (audit not passed yet -> locked private)" if priv != "public" else ""))
    register_post(BASE, INGEST, "yt", v["page_id"], vid,
                  f"https://youtube.com/shorts/{vid}")
    if publish_at:
        log("  first comment skipped: a scheduled video is private until its time")
    else:
        first_comment(vid, v, token)
    return True


SLOTS_F = f"{STATE}/yt_slots.json"


def next_yt_slot(hours):
    """The next free slot hour (UTC) at least 30 minutes ahead, today or the next two days; each slot used once.
    -> ISO publish time, or None when every slot of three days is taken."""
    try:
        booked = json.load(open(SLOTS_F))
    except Exception:  # noqa: BLE001
        booked = {}
    now = time.time()
    today0 = now - now % 86400
    for off in (0, 1, 2):
        day0 = today0 + off * 86400
        day = time.strftime("%Y-%m-%d", time.gmtime(day0))
        for h in sorted(set(hours)):
            t = day0 + h * 3600
            if t < now + 1800 or h in booked.get(day, []):
                continue
            booked.setdefault(day, []).append(h)
            booked = {k: v for k, v in booked.items() if k >= time.strftime("%Y-%m-%d", time.gmtime(today0))}
            json.dump(booked, open(SLOTS_F, "w"))
            return time.strftime("%Y-%m-%dT%H:00:00Z", time.gmtime(t))
    return None


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

    # 2026-11-01 (owner): EVERY PLATFORM ON TIME. Waiting for an hourly run to land inside a slot hour posted late or
    # not at all (GitHub starts this job only some of the hours: 2 uploads instead of 5 on 2026-10-29). Now every ready
    # video is uploaded at once, PRIVATE, with the next free slot as its publish time: YouTube publishes it itself, on
    # time. A hot story (fresh news) still goes public at once.
    slots = [int(h) for h in os.environ.get("YT_SLOT_HOURS", "16,23").split(",")]
    token = access_token()
    if not token:
        log("YT: token refresh failed")
        return 1
    hot = [v for v in todo if v.get("hot")]
    rest = [v for v in todo if not v.get("hot")]
    rc = 0
    for v in hot[:1] + rest:
        when = None if v.get("hot") else next_yt_slot(slots)
        if not v.get("hot") and not when:
            log("YT: every slot of the next three days is taken; the rest waits")
            break
        log(f"YT: page {v['page_id']} ({v['slug']}) " + (f"scheduled for {when}" if when else "is hot news: public now"))
        if not upload(v, token, publish_at=when):
            rc = 1
            break                                   # the slot stays booked: an empty slot is better than a double post
        done.add(str(v["page_id"]))
        open(DONE, "w").write("\n".join(sorted(done)) + "\n")
        open(LAST, "w").write("%d\n" % int(time.time()))
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
