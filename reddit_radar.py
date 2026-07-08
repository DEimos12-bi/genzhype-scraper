#!/usr/bin/env python3
"""GenZHype | Reddit Opportunity Radar (READ-ONLY). Finds recent threads where one
of our pages is the perfect answer and ships them to our endpoint for manual review.
It only READS. It NEVER posts -- a human posts by hand in the admin."""
import os
import sys
import re
import time
import json
import calendar
import urllib.parse
import urllib.request

GET_TIMEOUT = 20
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"
PER_SUB = 40

DEFAULT_SUBS = [
    "OutOfTheLoop", "GenZ", "teenagers", "LivestreamFail", "Fauxmoi",
    "popculturechat", "h3h3productions", "youtubedrama", "InternetDrama",
    "memes", "dankmemes",
]
SUBS = [s.strip() for s in os.environ.get("RADAR_SUBS", ",".join(DEFAULT_SUBS)).split(",") if s.strip()]
INGEST_URL = os.environ.get("INGEST_URL", "https://genzhype.com/api/reddit_ingest.php")

try:
    from curl_cffi import requests as _cffi
    def http_get(url):
        r = _cffi.get(url, impersonate="firefox", timeout=GET_TIMEOUT,
                      headers={"User-Agent": UA, "Accept": "application/atom+xml,application/xml,text/xml,*/*"},
                      allow_redirects=True)
        r.raise_for_status(); return r.content
except Exception:
    def http_get(url):
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        with urllib.request.urlopen(req, timeout=GET_TIMEOUT) as r:
            return r.read()


def _thread(pid, sub, title, permalink, selftext, author, created, ncomments=0, ups=0):
    return {"id": "t3_" + pid, "subreddit": sub, "title": (title or "")[:500],
            "permalink": permalink, "selftext": (selftext or "")[:4000],
            "author": author or "", "created_utc": int(created or 0),
            "num_comments": int(ncomments or 0), "ups": int(ups or 0)}


def collect_praw():
    import praw
    reddit = praw.Reddit(client_id=os.environ["REDDIT_CLIENT_ID"],
                         client_secret=os.environ["REDDIT_CLIENT_SECRET"],
                         user_agent="web:genzhype-radar:v1 (read-only opportunity finder)")
    reddit.read_only = True
    seen, out = set(), []
    for sub in SUBS:
        try:
            for p in reddit.subreddit(sub).new(limit=PER_SUB):
                if p.id in seen or getattr(p, "stickied", False) or getattr(p, "over_18", False):
                    continue
                seen.add(p.id)
                out.append(_thread(p.id, sub, p.title, p.permalink, getattr(p, "selftext", ""),
                                   str(p.author) if p.author else "", getattr(p, "created_utc", 0),
                                   getattr(p, "num_comments", 0), getattr(p, "score", 0)))
        except Exception as e:
            print(f"  ! praw r/{sub}: {e}", file=sys.stderr)
    return out


def _get_retry(url, tries=4):
    delay = 5
    for _ in range(tries):
        try:
            return http_get(url)
        except Exception as e:
            print(f"    retry ({e})", file=sys.stderr)
            time.sleep(delay); delay = min(delay * 2, 45)
    return None


def collect_rss():
    import feedparser
    seen, out = set(), []
    for sub in SUBS:
        raw = None
        for host in ("www.reddit.com", "old.reddit.com"):
            raw = _get_retry(f"https://{host}/r/{sub}/new/.rss?limit={PER_SUB}")
            if raw and len(raw) > 400:
                break
        if not raw:
            print(f"  ! rss r/{sub}: skipped (throttled, will retry next run)", file=sys.stderr)
            time.sleep(3); continue
        feed = feedparser.parse(raw)
        got = 0
        for e in feed.entries:
            fid = (getattr(e, "id", "") or "")
            pid = fid[3:] if fid.startswith("t3_") else None
            link = getattr(e, "link", "") or ""
            if not pid:
                m = re.search(r"/comments/([a-z0-9]+)/", link)
                pid = m.group(1) if m else None
            if not pid or pid in seen:
                continue
            seen.add(pid)
            created = calendar.timegm(e.published_parsed) if getattr(e, "published_parsed", None) else 0
            summary = re.sub(r"<[^>]+>", " ", getattr(e, "summary", "") or "")
            path = urllib.parse.urlparse(link).path or f"/r/{sub}/comments/{pid}/"
            out.append(_thread(pid, sub, getattr(e, "title", ""), path, summary,
                               getattr(e, "author", ""), created))
            got += 1
        print(f"  r/{sub}: {got}")
        time.sleep(4)
    return out


def _d_cffi(url, body):
    from curl_cffi import requests as c
    r = c.post(url, json=body, impersonate="firefox", timeout=60, headers={"User-Agent": UA})
    r.raise_for_status(); return r.json()

def _d_requests(url, body):
    import requests
    r = requests.post(url, json=body, headers={"User-Agent": UA}, timeout=60)
    r.raise_for_status(); return r.json()

def _d_urllib(url, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json", "User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())

def deliver(threads, chunk=40):
    if not threads:
        print("no threads collected"); return
    token = os.environ.get("INGEST_TOKEN", "")
    if not token:
        print("ERROR: INGEST_TOKEN not set", file=sys.stderr); sys.exit(1)
    total = {"seen": 0, "matched": 0, "duplicates": 0, "stale": 0}
    for c in range(0, len(threads), chunk):
        body = {"token": token, "threads": threads[c:c + chunk]}
        last, delay = None, 5
        for attempt in range(1, 6):
            ok = False
            for engine in (_d_cffi, _d_requests, _d_urllib):
                try:
                    res = engine(INGEST_URL, body)
                    for k in total: total[k] += int(res.get(k, 0) or 0)
                    ok = True; break
                except Exception as e:
                    last = e
            if ok: break
            print(f"  ! ingest chunk {c//chunk+1} attempt {attempt}/5: {last}", file=sys.stderr)
            time.sleep(delay); delay = min(delay * 2, 60)
    print(f"ingest done: seen={total['seen']} matched={total['matched']} "
          f"stale-skipped={total['stale']} dupes={total['duplicates']}")


if __name__ == "__main__":
    if os.environ.get("REDDIT_CLIENT_ID") and os.environ.get("REDDIT_CLIENT_SECRET"):
        print(f"Reddit Radar: source = API/PRAW (fresh), {len(SUBS)} subs...")
        threads = collect_praw()
    else:
        print(f"Reddit Radar: source = RSS (no app, best-effort), {len(SUBS)} subs...")
        threads = collect_rss()
    print(f"collected {len(threads)} threads -> {INGEST_URL}")
    deliver(threads)
