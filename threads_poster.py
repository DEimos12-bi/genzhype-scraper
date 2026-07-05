#!/usr/bin/env python3
"""GenZHype | Threads auto-poster (GitHub Actions). Same proven flow as IG, on graph.threads.net."""
import os, sys, json, time, urllib.request, urllib.parse, urllib.error

BASE    = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST  = os.environ["INGEST_TOKEN"]
TH_ID   = os.environ["THREADS_USER_ID"]
SEED    = os.environ["THREADS_ACCESS_TOKEN"]
GRAPH   = "https://graph.threads.net/v1.0"
REFRESH = "https://graph.threads.net/refresh_access_token"
STATE   = ".social"; TOKF = f"{STATE}/th_token.txt"; DONEF = f"{STATE}/th_posted.txt"

def log(*a): print(*a, flush=True)

def call(url, data=None):
    try:
        req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode()) if data else url
        with urllib.request.urlopen(req, timeout=90) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try: return json.load(e)
        except Exception: return {"error": e.read().decode()[:300]}
    except Exception as e:
        return {"error": str(e)}

def main():
    os.makedirs(STATE, exist_ok=True)
    token  = open(TOKF).read().strip() if os.path.exists(TOKF) else SEED
    posted = set(l.strip() for l in open(DONEF) if l.strip()) if os.path.exists(DONEF) else set()

    ref = call(f"{REFRESH}?grant_type=th_refresh_token&access_token={token}")
    if ref.get("access_token"):
        token = ref["access_token"]; open(TOKF, "w").write(token)
        log(f"token refreshed (~{int(ref.get('expires_in', 0)) // 86400}d left)")
    else:
        log("token refresh skipped:", ref.get("error", ref))

    q = call(f"{BASE}/api/social_next.php?token={urllib.parse.quote(INGEST)}&platform=threads")
    posts = q.get("posts", [])
    todo = [p for p in posts if str(p["page_id"]) not in posted]
    if not todo:
        log(f"nothing new ({len(posts)} in queue, all posted)"); return 0

    p = todo[0]; text = p["caption"][:490]
    log(f"posting page {p['page_id']}: {text[:70]}...")
    cont = call(f"{GRAPH}/{TH_ID}/threads",
                {"media_type": "IMAGE", "image_url": p["image"], "text": text, "access_token": token})
    cid = cont.get("id")
    if not cid:
        log("container failed:", cont); return 1
    time.sleep(5)
    pub = call(f"{GRAPH}/{TH_ID}/threads_publish", {"creation_id": cid, "access_token": token})
    mid = pub.get("id")
    if not mid:
        log("publish failed:", pub); return 1

    perma = call(f"{GRAPH}/{mid}?fields=permalink&access_token={token}").get("permalink", "")
    log(f"PUBLISHED -> {perma or mid}")
    posted.add(str(p["page_id"]))
    open(DONEF, "w").write("\n".join(sorted(posted)) + "\n")
    return 0

if __name__ == "__main__":
    sys.exit(main())
