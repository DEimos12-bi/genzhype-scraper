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

BASE = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
TOKEN = os.environ.get("BUFFER_TOKEN", "")
API = "https://api.buffer.com"
STATE = ".social"
# Buffer's service name -> our platform code + state file
WANTED = {"facebook": "fb", "instagram": "ig"}


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
    IG: reel + shouldShareToFeed for reach; the sourced-timeline link goes
    in firstComment because IG captions can't carry clickable links.
    isAiGenerated=true: Meta REQUIRES disclosure for digitally created
    realistic audio, which our TTS narration is."""
    link = "Full sourced timeline + every receipt: " + v["link"]
    if service == "instagram":
        return {"instagram": {"type": "reel" if as_reel else "post",
                              "shouldShareToFeed": True,
                              "isAiGenerated": True,
                              "firstComment": link}}
    if service == "facebook":
        return {"facebook": {"type": "reel" if as_reel else "post",
                             "firstComment": link}}
    return {}


def post(channel, v):
    """Queue one video on one channel. Returns True on success.
    Falls back reel -> plain video post if the network refuses (FB Reels
    cap at 90s; a long timeline would otherwise be dropped entirely)."""
    service = (channel.get("service") or "").lower()
    text = v["caption"]
    for as_reel in (True, False):
        data, err = gql(MUTATION, {"i": {
            "channelId": channel["id"], "text": text,
            "schedulingType": "automatic", "mode": "addToQueue",
            "needsApproval": False,
            "metadata": _meta(service, v, as_reel),
            "assets": [{"video": {"url": v["video"],
                                  "metadata": {"thumbnailOffset": 2000}}}]}})
        if err:
            log("  createPost error:", json.dumps(err)[:300])
            return False
        res = (data or {}).get("createPost") or {}
        if res.get("message"):
            log(f"  refused as {'reel' if as_reel else 'post'}:",
                res["message"][:200])
            continue
        pid = (res.get("post") or {}).get("id")
        log(f"  QUEUED on {service} as {'reel' if as_reel else 'post'}"
            f" (buffer post {pid})")
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
            register(code, v["page_id"])
        else:
            rc = 1
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
