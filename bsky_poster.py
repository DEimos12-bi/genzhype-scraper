#!/usr/bin/env python3
"""GenZHype | Bluesky auto-poster (GitHub Actions). AT Protocol: session -> uploadBlob -> createRecord."""
import os, sys, json, datetime, urllib.request, urllib.parse, urllib.error

BASE   = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
HANDLE = os.environ["BSKY_HANDLE"]
APPPW  = os.environ["BSKY_APP_PASSWORD"]
PDS    = "https://bsky.social/xrpc"
STATE  = ".social"; DONEF = f"{STATE}/bsky_posted.txt"

def log(*a): print(*a, flush=True)

def req(url, data=None, headers=None, raw=False):
    body = data if raw else (json.dumps(data).encode() if data is not None else None)
    try:
        with urllib.request.urlopen(urllib.request.Request(url, data=body, headers=headers or {}), timeout=90) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try: return json.load(e)
        except Exception: return {"error": e.read().decode()[:300]}
    except Exception as e:
        return {"error": str(e)}

def main():
    os.makedirs(STATE, exist_ok=True)
    posted = set(l.strip() for l in open(DONEF) if l.strip()) if os.path.exists(DONEF) else set()

    s = req(f"{PDS}/com.atproto.server.createSession",
            {"identifier": HANDLE, "password": APPPW}, {"Content-Type": "application/json"})
    jwt, did = s.get("accessJwt"), s.get("did")
    if not jwt:
        log("session failed:", s); return 1
    auth = {"Authorization": f"Bearer {jwt}"}

    q = req(f"{BASE}/api/social_next.php?token={urllib.parse.quote(INGEST)}&platform=x")
    todo = [p for p in q.get("posts", []) if str(p["page_id"]) not in posted]
    if not todo:
        log(f"nothing new ({len(q.get('posts', []))} in queue, all posted)"); return 0

    p = todo[0]; text = p["caption"][:300]
    log(f"posting page {p['page_id']}: {text[:70]}...")

    try:
        img = urllib.request.urlopen(p["image"], timeout=60).read()
    except Exception as e:
        log("image fetch failed:", e); return 1
    br = req(f"{PDS}/com.atproto.repo.uploadBlob", img, {**auth, "Content-Type": "image/jpeg"}, raw=True)
    blob = br.get("blob")
    if not blob:
        log("blob upload failed:", br); return 1

    rec = {"repo": did, "collection": "app.bsky.feed.post", "record": {
        "$type": "app.bsky.feed.post", "text": text,
        "createdAt": datetime.datetime.now(datetime.timezone.utc).isoformat().replace("+00:00", "Z"),
        "embed": {"$type": "app.bsky.embed.images", "images": [{"alt": "GenZHype", "image": blob}]}}}
    r = req(f"{PDS}/com.atproto.repo.createRecord", rec, {**auth, "Content-Type": "application/json"})
    if not r.get("uri"):
        log("post failed:", r); return 1

    log(f"PUBLISHED -> https://bsky.app/profile/{HANDLE}/post/{r['uri'].split('/')[-1]}")
    posted.add(str(p["page_id"]))
    open(DONEF, "w").write("\n".join(sorted(posted)) + "\n")
    return 0

if __name__ == "__main__":
    sys.exit(main())
