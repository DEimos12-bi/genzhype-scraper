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
# Buffer's service name -> our platform code + state file
# r72: Buffer now owns TIKTOK ONLY.
# Why this matters more than it looks: TikTok's own API only grants us INBOX
# (draft) mode because our app is not audit-approved, so every video sat waiting
# for the owner to tap publish — at 5:15am his time for the late-US slots.
# Buffer IS an approved TikTok partner, so posts it queues publish DIRECTLY.
# The channel (genzhype0) was already connected in Buffer; this map simply never
# listed "tiktok", so the code walked straight past it.
# Facebook/Instagram are deliberately NOT here — the native reels/facebook
# posters own those, and having both queue to them is what pushed IG and FB to
# 8 posts/day earlier.
WANTED = {"tiktok": "tt", "facebook": "fb", "instagram": "ig"}
# Posts per channel per DAY. The workflow checks 5x/day so a fresh video
# goes out soon after it renders — but without this cap those 5 checks
# would drain a 10-video backlog at 5 posts/channel/day, which is what
# caused the 8-a-day flood the first time fb/ig were on Buffer.
DAILY_CAP = int(os.environ.get("BUFFER_DAILY_CAP", "2"))


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


def gql(query, variables=None):
    """Buffer GraphQL call. Returns (data, errors)."""
    res = curl_json(API, {"query": query, "variables": variables or {}},
                    [f"Authorization: Bearer {TOKEN}"])
    return res.get("data"), res.get("errors")


def next_slot():
    """Exact publish time, in UTC, decided HERE — not by Buffer's queue.
    Buffer's per-channel schedule grid is fiddly and drifts to random
    times (6am ET posts help nobody), so we pass customScheduled + dueAt
    and the grid becomes irrelevant. Slots sit ~30min after the YouTube
    ones so the same story doesn't land everywhere in the same minute."""
    slots = os.environ.get("BUFFER_SLOTS_UTC", "17:15,23:15").split(",")
    now = time.gmtime()
    mins_now = now.tm_hour * 60 + now.tm_min
    for s in slots:
        h, m = (int(x) for x in s.split(":"))
        if h * 60 + m > mins_now + 10:          # not in the past / too soon
            return time.strftime(f"%Y-%m-%dT{h:02d}:{m:02d}:00.000Z", now)
    h, m = (int(x) for x in slots[0].split(":"))   # tomorrow's first slot
    tmr = time.gmtime(time.time() + 86400)
    return time.strftime(f"%Y-%m-%dT{h:02d}:{m:02d}:00.000Z", tmr)


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


def load_done(code):
    p = done_path(code)
    return set(l.strip() for l in open(p)) if os.path.exists(p) else set()


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
    due = next_slot()
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
    if not TOKEN:
        log("Buffer: no credentials; skipped")
        return 0
    os.makedirs(STATE, exist_ok=True)
    q = curl_json(f"{BASE}/api/video_social_next.php?token={INGEST}")
    vids = q.get("videos", [])
    if not vids:
        log("queue empty")
        return 0
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
        if n >= DAILY_CAP:
            log(f"{code}: daily cap reached ({n}/{DAILY_CAP}) - skipping")
            continue
        done = load_done(code)
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
