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
SERVICE_SLOTS = {
    "tiktok":    os.environ.get("BUFFER_SLOTS_TT", "21:10,22:20,23:50,2:20,4:10"),
    "instagram": os.environ.get("BUFFER_SLOTS_IG", "21:55,23:25,0:55,2:25,3:55"),
    "facebook":  os.environ.get("BUFFER_SLOTS_FB", "22:40,0:40,3:40"),
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
        "{ id name displayName service } }", {"o": org})
    if err or not data:
        log("Buffer: channels query failed:", json.dumps(err)[:300])
        return []
    return data.get("channels") or []


MUTATION = ("mutation($i:CreatePostInput!){ createPost(input:$i){"
            " ... on PostActionSuccess { post { id } }"
            " ... on MutationError { message } } }")


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
                                  "metadata": {"thumbnailOffset": 0}}}]}})
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
        return True
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
        v = todo[0]
        log(f"{code}: queueing page {v['page_id']} ({v['slug']})")
        if post(c, v):
            done.add(str(v["page_id"]))
            save_done(code, done)
            bump_today(code)
            register(code, v["page_id"])
        else:
            rc = 1
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
