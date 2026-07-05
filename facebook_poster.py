#!/usr/bin/env python3
"""
GenZHype | Facebook Page auto-poster (external, GitHub Actions -- never touches the website). Uses the
SAME Meta app as Instagram (Graph API). In Development mode the app admin may post to a Page they manage
with NO App Review. A Page access token derived from a long-lived user token does NOT expire -> no refresh.
Reuses the site's read-only queue (social_next.php) + the shared JPEG (ig_image.php); tracks its own state.
Env secrets: SOCIAL_BASE, INGEST_TOKEN, FB_PAGE_ID, FB_PAGE_TOKEN.
"""
import os, sys, json, urllib.request, urllib.parse, urllib.error

BASE   = os.environ.get("SOCIAL_BASE", "https://genzhype.com").rstrip("/")
INGEST = os.environ["INGEST_TOKEN"]
PAGE   = os.environ["FB_PAGE_ID"]
TOKEN  = os.environ["FB_PAGE_TOKEN"]
GRAPH  = "https://graph.facebook.com/v21.0"
STATE  = ".social"; DONEF = f"{STATE}/fb_posted.txt"

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
    posted = set(l.strip() for l in open(DONEF) if l.strip()) if os.path.exists(DONEF) else set()

    # reuse the Instagram content (both Meta, image-first) + the page link as the click-through
    q = call(f"{BASE}/api/social_next.php?token={urllib.parse.quote(INGEST)}&platform=instagram")
    todo = [p for p in q.get("posts", []) if str(p["page_id"]) not in posted]
    if not todo:
        log(f"nothing new ({len(q.get('posts', []))} in queue, all posted)"); return 0

    p = todo[0]
    caption = f"{p['caption']}\n\n{p['link']}"        # link in the caption -> clickable, drives to the site
    log(f"posting page {p['page_id']}: {p['caption'][:70]}...")
    r = call(f"{GRAPH}/{PAGE}/photos",
             {"url": p["image"], "caption": caption, "published": "true", "access_token": TOKEN})
    post_id = r.get("post_id") or r.get("id")
    if not post_id:
        log("post failed:", r); return 1

    log(f"PUBLISHED -> https://www.facebook.com/{post_id}")
    posted.add(str(p["page_id"]))
    open(DONEF, "w").write("\n".join(sorted(posted)) + "\n")
    return 0

if __name__ == "__main__":
    sys.exit(main())
