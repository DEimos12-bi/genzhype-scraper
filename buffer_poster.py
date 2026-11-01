#!/usr/bin/env python3
"""GenZHype | Facebook Page + Instagram Reels poster VIA BUFFER.

Why Buffer instead of Meta directly: our Meta developer accounts are
restricted (automated, unappealable). Buffer's OWN approved Meta app does
the publishing, so no developer platform is involved at all — the owner
just connects the Page + IG account once with a normal user login, and
Buffer's GraphQL API (free plan, verified 2026-08-08) lets this runner
queue posts machine-to-machine.

Flow: our ready-video queue -> Buffer createPost (addToQueue) for each of
the FB + IG channels -> Buffer publishes at its scheduled slots.
Verified API facts (developers.buffer.com):
  endpoint https://api.buffer.com, header "Authorization: Bearer <key>",
  account{organizations{id}} -> channels(input:{organizationId}) ->
  createPost(input:{channelId,text,schedulingType:automatic,
  mode:addToQueue,assets:[{video:{url,metadata:{thumbnailOffset}}}]}),
  asset url must be publicly accessible (our media/video/*.mp4 is).
Free-plan ceilings: 3 channels, 10 queued posts per channel, 100 API
requests/24h — we use ~6.

Token lives in the server vault (key "buffer"); repo is public.
Env: SOCIAL_BASE, INGEST_TOKEN, BUFFER_TOKEN (fetched by the workflow).
"""
import json
import os
import shutil
import subprocess
import time

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
TOKEN = os.environ.get("BUFFER_TOKEN", "")
API = "https://api.buffer.com"
STATE = ".social"
# Buffer's service name -> our platform code + state file.
# r128/r129 — BUFFER IS THE ONLY POSTING ROUTE for all three platforms, and
# the history matters because stale comments here have already misled us once:
#   - IG/FB: the native reels/facebook posters authenticate with Meta Graph
#     tokens that answer "Application has been deleted" (verified live
#     2026-08-15; the banned Meta developer account took the app). They can
#     never succeed; their schedules are disabled.
#   - TikTok: our own app is not audit-approved, so its API only allows INBOX
#     DRAFTS the owner must tap — and untapped drafts are posts that never
#     happened. Buffer is an approved TikTok partner and publishes directly.
# One route per platform = the r122 double-posting cannot return.
# If you change this map, update THIS comment in the same commit.
WANTED = {"tiktok": "tt", "instagram": "ig", "facebook": "fb"}

# r129: ALL FIVE TikTok posts are Buffer-direct now — fully automatic, zero
# owner taps. Buffer's own measured ceiling is 25/day (dailyPostingLimits,
# 2026-08-14), so 5 is comfortable. The native tiktok-poster's inbox-draft
# lane is schedule-disabled: drafts depended on the owner tapping publish
# every night, and unpublished drafts are posts that never happened.
DAILY_CAPS = {
    "tt": int(os.environ.get("BUFFER_DAILY_CAP_TT", "5")),
    "ig": int(os.environ.get("BUFFER_DAILY_CAP_IG", "5")),
    "fb": int(os.environ.get("BUFFER_DAILY_CAP_FB", "3")),
}

# All inside the 21:00-05:00 UTC window like every poster. The workflow runs
# 8x/night; each run books the next FREE slot per channel (the booked-slots
# state makes double-booking impossible), and the caps above stop each
# channel at its agreed count.
# r136 WINNER WINDOW (2026-08-20). Our own competitor engine measured when
# rival outliers actually publish: top hours are 19:00 and 20:00 UTC (19 -> 5
# outliers, 20 -> 3), while our grid started at 21:10 and ran to 04:10 — we
# were posting AROUND the best window, never inside it. The grids now open at
# 19:xx and still hold 5 TikTok / 5 Instagram / 3 Facebook slots. Post-midnight
# slots book as "tomorrow" by design (see next_slot) — that is still the same
# US evening.
SERVICE_SLOTS = {
    "tiktok":    os.environ.get("BUFFER_SLOTS_TT", "19:10,20:20,21:40,23:10,0:40"),
    "instagram": os.environ.get("BUFFER_SLOTS_IG", "19:35,20:50,22:10,23:40,1:10"),
    "facebook":  os.environ.get("BUFFER_SLOTS_FB", "19:50,21:20,23:00"),
}


def log(*a):
    print(*a, flush=True)


def curl_json(url, payload=None, headers=None):
    cmd = ["curl", "-s", "--max-time", "120"]
    for h in (headers or []):
        cmd += ["-H", h]
    if payload is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(payload)]
    r = subprocess.run(cmd + [url], capture_output=True, timeout=140)
    if r.returncode != 0:
        raise RuntimeError(f"curl {r.returncode} for {url.split('?')[0]}")
    return json.loads(r.stdout or b"{}")


def fetch_vault_token():
    """Get the Buffer key from our server. This used to live in a bash step
    in the workflow — and when Hostinger's WAF blackholed the runner's IP
    the whole job died there with NO LOG AT ALL, which is what made two
    failures un-diagnosable. Doing it here means every run leaves a log,
    win or lose. Retrying is best-effort only: the block is per-IP, so if
    this runner is blocked all attempts fail and the next scheduled run
    (5x/day, cap 2) simply picks it up."""
    for attempt in (1, 2, 3):
        try:
            creds = curl_json(f"{BASE}/api/creds.php",
                              {"token": INGEST, "want": ["buffer"]})
            tok = (creds.get("creds") or {}).get("buffer", "")
            if tok:
                print(f"::add-mask::{tok}", flush=True)   # 2026-09-24: this repo's logs are public
                return tok
            log(f"vault returned no buffer key (attempt {attempt})")
        except Exception as e:  # noqa: BLE001
            log(f"vault unreachable from this runner (attempt {attempt}): {e}")
        if attempt < 3:
            time.sleep(10 * attempt)
    return ""


def gql(query, variables=None):
    """Buffer GraphQL call. Returns (data, errors)."""
    res = curl_json(API, {"query": query, "variables": variables or {}},
                    [f"Authorization: Bearer {TOKEN}"])
    return res.get("data"), res.get("errors")


def next_slot(service=""):
    """Exact publish time, in UTC, decided HERE — not by Buffer's queue.
    Buffer's per-channel schedule grid is fiddly and drifts to random
    times (6am ET posts help nobody), so we pass customScheduled + dueAt
    and the grid becomes irrelevant. Slots sit ~30min after the YouTube
    ones so the same story doesn't land everywhere in the same minute."""
    # Per-platform stagger: the same story landing on every network in the
    # same minute is a pattern platforms read as automation, and every
    # rulebook warns against duplicate-looking simultaneous posts.
    offset = {"tiktok": 0, "instagram": 7, "facebook": 14}.get(service, 0)
    slots = SERVICE_SLOTS.get(service,
                              os.environ.get("BUFFER_SLOTS_UTC", "17:15,23:15")).split(",")
    # r121: the workflow now runs 8x/day to feed 5-slot grids, so two runs can
    # see the same upcoming slot. A slot is used ONCE: booked ones (per UTC
    # day, per service) are skipped, otherwise back-to-back runs would both
    # schedule into e.g. 16:20 and the day would burn its cap in one hour.
    booked = _booked_slots(service)
    now = time.gmtime()
    mins_now = now.tm_hour * 60 + now.tm_min
    for s in slots:
        if s in booked:
            continue
        h, m = (int(x) for x in s.split(":"))
        t = h * 60 + m + offset
        if t > mins_now + 10:                   # not in the past / too soon
            _book_slot(service, s)
            return time.strftime(f"%Y-%m-%dT{t // 60:02d}:{t % 60:02d}:00.000Z", now)
    for s in slots:                             # tomorrow's first free slot
        if s in _booked_slots(service, tomorrow=True):
            continue
        h, m = (int(x) for x in s.split(":"))
        t = h * 60 + m + offset
        _book_slot(service, s, tomorrow=True)
        tmr = time.gmtime(time.time() + 86400)
        return time.strftime(f"%Y-%m-%dT{t // 60:02d}:{t % 60:02d}:00.000Z", tmr)
    return None                                 # every slot spoken for


def _booked_path(service):
    return f"{STATE}/buffer_slots_{service}.json"


def _slot_day(tomorrow=False):
    return time.strftime("%Y-%m-%d", time.gmtime(time.time() + (86400 if tomorrow else 0)))


def _booked_slots(service, tomorrow=False):
    try:
        with open(_booked_path(service)) as fh:
            d = json.load(fh)
        return set(d.get(_slot_day(tomorrow), []))
    except Exception:
        return set()


def _book_slot(service, slot, tomorrow=False):
    day = _slot_day(tomorrow)
    try:
        with open(_booked_path(service)) as fh:
            d = json.load(fh)
    except Exception:
        d = {}
    d = {k: v for k, v in d.items() if k >= _slot_day()}   # drop past days
    d.setdefault(day, [])
    if slot not in d[day]:
        d[day].append(slot)
    with open(_booked_path(service), "w") as fh:
        json.dump(d, fh)


def rate_path(code):
    return f"{STATE}/buffer_rate_{code}.json"


def sent_today(code):
    """How many we've already scheduled on this channel today (UTC)."""
    try:
        d = json.load(open(rate_path(code)))
        today = time.strftime("%Y-%m-%d", time.gmtime())
        return d["n"] if d.get("date") == today else 0
    except Exception:  # noqa: BLE001
        return 0


def bump_today(code):
    today = time.strftime("%Y-%m-%d", time.gmtime())
    json.dump({"date": today, "n": sent_today(code) + 1},
              open(rate_path(code), "w"))


def done_path(code):
    return f"{STATE}/buffer_posted_{code}.txt"


def server_posted():
    """Page ids the SITE already recorded as posted, per platform.
    The repo's .social/*.txt files can drift — a run that published but
    never committed its state leaves the file behind, and next time we
    repost the same video. That nearly put the Streamer Awards video on
    TikTok a third time. The site's registry is the source of truth."""
    try:
        return curl_json(f"{BASE}/api/metrics_next.php?token={INGEST}") \
            .get("posted") or {}
    except Exception as e:  # noqa: BLE001
        log("could not read server registry (using local state only):", e)
        return {}


def load_done(code, server=None):
    p = done_path(code)
    local = set(l.strip() for l in open(p)) if os.path.exists(p) else set()
    return local | {str(x) for x in (server or {}).get(code, [])}


def save_done(code, done):
    open(done_path(code), "w").write("\n".join(sorted(done)) + "\n")


def register(platform, page_id):
    """Feedback loop: log the post so the scoreboard tracks it."""
    try:
        curl_json(f"{BASE}/api/metrics_ingest.php",
                  {"token": INGEST, "action": "register",
                   "platform": platform, "page_id": page_id})
    except Exception as e:  # noqa: BLE001
        log("  registry failed (non-fatal):", e)


def channels():
    data, err = gql("query { account { organizations { id name } } }")
    if err or not data:
        log("Buffer: organizations query failed:", json.dumps(err)[:300])
        return []
    orgs = ((data.get("account") or {}).get("organizations") or [])
    if not orgs:
        log("Buffer: no organizations on this account")
        return []
    org = orgs[0]["id"]
    data, err = gql(
        "query($o:OrganizationId!){ channels(input:{organizationId:$o})"
        "{ id name displayName service isDisconnected isLocked isQueuePaused } }", {"o": org})
    if err or not data:
        log("Buffer: channels query failed:", json.dumps(err)[:300])
        return []
    return data.get("channels") or []


MUTATION = ("mutation($i:CreatePostInput!){ createPost(input:$i){"
            " ... on PostActionSuccess { post { id } }"
            " ... on MutationError { message } } }")


# r133 COVER FIX (owner: IG/FB grids were BLACK tiles): thumbnailOffset was 0
# and every video fades in from black, so frame zero IS black. 2200ms lands
# after the fade with the hook text + opening clip on screen — an actual
# cover. Buffer's schema confirms the unit: 'Offset ... in ms'.
COVER_OFFSET_MS = int(os.environ.get("BUFFER_COVER_OFFSET_MS", "2200"))


def _meta(service, v, as_reel=True):
    """Per-network options (schema-introspected 2026-08-08).
    NOTE: firstComment is a PAID Buffer feature — the free plan rejects the
    whole post if it's set (proven in run 1), so the timeline link rides in
    the caption instead. isAiGenerated=true: Meta REQUIRES disclosure for
    digitally created realistic audio, which our TTS narration is."""
    if service == "instagram":
        return {"instagram": {"type": "reel" if as_reel else "post",
                              "shouldShareToFeed": True,
                              "isAiGenerated": True}}
    if service == "facebook":
        return {"facebook": {"type": "reel" if as_reel else "post"}}
    if service == "tiktok":
        # Buffer's TikTokPostMetadataInput has only {title, isAiGenerated} —
        # nothing is required, and the caption rides in `text`. AI label left
        # OFF to match what we've been publishing by hand (the TikTok
        # rulebook's call: generic TTS over real receipts isn't the
        # impersonation case their policy targets). Flip to True here if we
        # ever decide to align it with Instagram.
        return {"tiktok": {}}
    return {}


def post(channel, v):
    """Queue one video on one channel. Returns True on success.
    Falls back reel -> plain video post if the network refuses (FB Reels
    cap at 90s; a long timeline would otherwise be dropped entirely)."""
    service = (channel.get("service") or "").lower()
    # Each network gets its OWN caption from app/social_copy.php. None
    # carries a clickable URL: IG captions aren't clickable at all, and
    # Meta's own guidance says FB "captions without links... perform
    # better". They name genzhype.com in plain text instead.
    # BUG FIXED: the old ternary sent the FACEBOOK caption to TikTok
    # (anything not instagram fell through to fb_caption), which would have
    # posted FB hashtags and FB phrasing to TikTok — where the caption is
    # the main search surface and must lead with the person's name.
    cap_key = {"instagram": "ig_caption", "facebook": "fb_caption",
               "tiktok": "tt_caption"}.get(service)
    text = (v.get(cap_key) if cap_key else None) or v["caption"]
    due = next_slot(service)
    if due is None:
        log(f"{service}: every slot today and tomorrow is already booked; skipping")
        return False
    for as_reel in (True, False):
        data, err = gql(MUTATION, {"i": {
            "channelId": channel["id"], "text": text,
            "schedulingType": "automatic", "mode": "customScheduled",
            "dueAt": due,
            "needsApproval": False,
            "metadata": _meta(service, v, as_reel),
            # cover = frame 0 = our title card. IG's thumb_offset is in ms
            # and defaults to 0; 2000 would have grabbed a mid-hook frame.
            # Covers barely affect reach (Mosseri) but decide grid CTR.
            "assets": [{"video": {"url": v["video"],
                                  "metadata": {"thumbnailOffset": COVER_OFFSET_MS}}}]}})
        if err:
            log("  createPost error:", json.dumps(err)[:300])
            return False
        res = (data or {}).get("createPost") or {}
        if res.get("message"):
            log(f"  refused as {'reel' if as_reel else 'post'}:",
                res["message"][:200])
            continue
        pid = (res.get("post") or {}).get("id")
        log(f"  SCHEDULED on {service} as {'reel' if as_reel else 'post'}"
            f" for {due} (buffer post {pid})")
        return pid or True
    return False


# FACEBOOK FROM A COPY THAT EXISTS FOR ONE MINUTE (owner, 2026-11-01). Facebook's servers download the video from the
# link Buffer gives them, and our host's firewall blocks many of them ("Facebook was unable to process the media" on
# most days since mid-September; the same files posted fine from GitHub). The repository is public, so a video must not
# sit there before it is posted: at a due Facebook slot the video is copied to the release, Buffer posts it NOW, the
# poster waits until Facebook has it (sent or error), and the copy is deleted whatever happened.
FB_VIA_GITHUB = os.environ.get("BUFFER_FB_VIA_GITHUB", "1") == "1"
GH_REPO = os.environ.get("GITHUB_REPOSITORY", "DEimos12-bi/genzhype-scraper")
RELEASE = "social-media"


def _due_slot(service):
    """The latest slot of today (UTC) whose time has come (at most 3 hours ago) and that is not used yet; None if none."""
    offset = {"tiktok": 0, "instagram": 7, "facebook": 14}.get(service, 0)
    booked = _booked_slots(service)
    now = time.gmtime()
    mins = now.tm_hour * 60 + now.tm_min
    due = []
    for s in SERVICE_SLOTS.get(service, "").split(","):
        s = s.strip()
        if not s or s in booked:
            continue
        h, m = (int(x) for x in s.split(":"))
        t = h * 60 + m + offset
        if 0 <= mins - t <= 180:
            due.append((t, s))
    return max(due)[1] if due else None


def _gh(*args):
    r = subprocess.run(["gh", *args, "-R", GH_REPO], capture_output=True, text=True, timeout=600)
    return r.returncode == 0, (r.stderr or r.stdout or "").strip()[-200:]


def post_facebook_now(channel, v):
    """-> the Buffer post id when Facebook took it, None when no slot is due yet, False on a failure."""
    slot = _due_slot("facebook")
    if not slot:
        log("fb: no Facebook slot is due right now; waiting for the next one")
        return None
    pid = str(v["page_id"])
    path = f"/tmp/fb-{pid}.mp4"
    got = subprocess.run(["curl", "-s", "-f", "-L", "--max-time", "300", "-A", "Mozilla/5.0", "-o", path, v["video"]], timeout=330)
    if got.returncode != 0 or not os.path.exists(path) or os.path.getsize(path) < 200000:
        log(f"fb: page {pid}: the video could not be read from our site from this machine; the next run tries again")
        return False
    asset = f"{pid}.mp4"
    shutil.copy(path, f"/tmp/{asset}")
    ok, why = _gh("release", "upload", RELEASE, f"/tmp/{asset}", "--clobber")
    if not ok:
        log(f"fb: page {pid}: the copy could not be put on GitHub ({why})")
        return False
    url = f"https://github.com/{GH_REPO}/releases/download/{RELEASE}/{asset}"
    post_id, status, err = None, "", ""
    try:
        text = v.get("fb_caption") or v["caption"]
        for as_reel in (True, False):
            data, e = gql(MUTATION, {"i": {
                "channelId": channel["id"], "text": text, "schedulingType": "automatic", "mode": "shareNow",
                "needsApproval": False, "metadata": _meta("facebook", v, as_reel),
                "assets": [{"video": {"url": url, "metadata": {"thumbnailOffset": COVER_OFFSET_MS}}}]}})
            res = (data or {}).get("createPost") or {}
            if e or res.get("message"):
                err = json.dumps(e)[:200] if e else res["message"][:200]
                log(f"  refused as {'reel' if as_reel else 'post'}: {err}")
                continue
            post_id = (res.get("post") or {}).get("id")
            break
        for _ in range(24):                         # Facebook downloads within a minute or two; wait for the verdict
            if not post_id:
                break
            d, _e = gql("query($i:PostInput!){ post(input:$i){ status error { message } } }", {"i": {"id": post_id}})
            p = (d or {}).get("post") or {}
            status, err = p.get("status") or "", ((p.get("error") or {}).get("message") or "")
            if status in ("sent", "error"):
                break
            time.sleep(10)
    finally:
        gone, why = _gh("release", "delete-asset", RELEASE, asset, "-y")
        log(f"fb: the GitHub copy of page {pid} {'is deleted' if gone else 'could NOT be deleted: ' + why}")
    if status == "sent":
        _book_slot("facebook", slot)
        log(f"  POSTED on facebook at slot {slot} (buffer post {post_id})")
        return post_id
    log(f"fb: page {pid}: not posted ({status or 'no answer'}{': ' + err if err else ''})")
    return False


def main():
    global TOKEN
    os.makedirs(STATE, exist_ok=True)
    if not TOKEN:
        TOKEN = fetch_vault_token()
    if not TOKEN:
        log("Buffer: no token this run; the next scheduled run will retry")
        return 0

    q = curl_json(f"{BASE}/api/video_social_next.php?token={INGEST}")
    vids = q.get("videos", [])
    if not vids:
        log("queue empty")
        return 0
    posted = server_posted()
    chans = channels()
    if not chans:
        return 1
    log("channels:", ", ".join(f"{c['service']}:{c.get('displayName') or c['name']}"
                               for c in chans))
    rc = 0
    for c in chans:
        code = WANTED.get((c.get("service") or "").lower())
        if not code:
            continue
        n = sent_today(code)
        cap = DAILY_CAPS.get(code, 2)
        if n >= cap:
            log(f"{code}: daily cap reached ({n}/{cap}) - skipping")
            continue
        done = load_done(code, posted)
        todo = [v for v in vids if str(v["page_id"]) not in done]
        if not todo:
            log(f"{code}: nothing new")
            continue
        ok, why = channel_healthy(c, code)
        if not ok:
            log(f"{code}: CHANNEL NOT HEALTHY - {why}")
            if "paused" in why or "disconnected" in why or "locked" in why:
                continue
            todo = todo[:1]          # its last posts failed: ONE post this run as a probe, never a whole day into a broken channel
        if code == "fb" and FB_VIA_GITHUB:           # Facebook cannot fetch from our host: posted at its slot from a GitHub copy, deleted at once
            if sent_today(code) >= cap:
                log(f"{code}: daily cap reached ({sent_today(code)}/{cap})")
                continue
            v = todo[0]
            pid = post_facebook_now(c, v)
            if pid:
                done.add(str(v["page_id"]))
                save_done(code, done)
                bump_today(code)
                register(code, v["page_id"])
                remember(code, pid)
            elif pid is False:
                rc = 1
            continue
        # 2026-11-01 (owner): the day's videos are booked at once, every free slot up to the cap, instead of one per run:
        # GitHub skips most scheduled runs, and one booking per run left most slots empty.
        for v in todo:
            if sent_today(code) >= cap:
                log(f"{code}: daily cap reached ({sent_today(code)}/{cap})")
                break
            log(f"{code}: queueing page {v['page_id']} ({v['slug']})")
            pid = post(c, v)
            if pid:
                done.add(str(v["page_id"]))
                save_done(code, done)
                bump_today(code)
                register(code, v["page_id"])
                remember(code, pid)
            else:
                rc = 1
                break                # no free slot or a refusal: the next run tries again
    return rc


def _recent_path(code):
    return f"{STATE}/buffer_recent_{code}.json"


def remember(code, post_id):
    """The posts this poster booked on a channel, newest last: channel_healthy() reads how they ended."""
    try:
        ids = json.load(open(_recent_path(code)))
    except Exception:  # noqa: BLE001
        ids = []
    ids = (ids + [post_id])[-12:]
    json.dump(ids, open(_recent_path(code), "w"))


def channel_healthy(channel, code):
    """BEFORE BOOKING, CHECK THE CHANNEL (owner, 2026-11-01: "after checking all Buffer endpoints work, no problems").
    Buffer's own flags (disconnected, locked, queue paused), then how this channel's last three DUE posts ended:
    TikTok's ban and Facebook's "unable to process the media" only show up there, after the fact.
    -> (True, '') or (False, why)."""
    if channel.get("isDisconnected"):
        return False, "Buffer says the channel is disconnected (reconnect it in Buffer)"
    if channel.get("isLocked"):
        return False, "Buffer says the channel is locked"
    if channel.get("isQueuePaused"):
        return False, "the channel's queue is paused in Buffer"
    try:
        ids = json.load(open(_recent_path(code)))
    except Exception:  # noqa: BLE001
        ids = []
    ended = []
    for pid in reversed(ids):
        data, err = gql("query($i:PostInput!){ post(input:$i){ status error { message } } }", {"i": {"id": pid}})
        p = (data or {}).get("post") or {}
        if p.get("status") in ("sent", "error"):
            ended.append(p)
        if len(ended) >= 3:
            break
    bad = [p for p in ended if p.get("status") == "error"]
    if len(ended) >= 2 and len(bad) == len(ended):
        msg = ((bad[0].get("error") or {}).get("message") or "")[:160]
        return False, f"its last {len(ended)} posts all failed: {msg}"
    return True, ""


if __name__ == "__main__":
    raise SystemExit(main())
