#!/usr/bin/env python3
"""
GenZHype | discovery scraper v2.
Primary: PRAW (official Reddit API | immune to IP blocks, complete data).
Fallback: public .json endpoints via Scrapling if no API creds present.
POSTs candidates to the site's ingest API. Runs on GitHub Actions cron.
"""
import json
import os
import sys
import urllib.request

SUBREDDITS = [
    "youtubedrama",
    "LivestreamFail",
    "Fauxmoi",
    "InternetDrama",
    "TikTokCringe",
]

UA = "GenZHypeDesk/2.0 research scraper"
MIN_SCORE = 200
MAX_PER_SUB = 8


def heat(upvotes: int, comments: int) -> int:
    raw = upvotes + comments * 3
    if raw >= 20000: return 95
    if raw >= 10000: return 85
    if raw >= 5000:  return 75
    if raw >= 2000:  return 60
    if raw >= 800:   return 45
    return 30


def to_candidate(sub, title, ups, ncm, permalink, ext_url, created, selftext):
    return {
        "type": "drama",
        "name": title[:240],
        "angle": f"surfaced on r/{sub}",
        "heat_score": heat(ups, ncm),
        "era": "present",
        "signals": {
            "source": f"reddit:r/{sub}",
            "ups": ups,
            "comments": ncm,
            "permalink": permalink,
            "external_url": ext_url or "",
            "created_utc": created,
            "selftext_excerpt": (selftext or "")[:800],
        },
    }


def via_praw():
    import praw
    reddit = praw.Reddit(
        client_id=os.environ["REDDIT_CLIENT_ID"],
        client_secret=os.environ["REDDIT_CLIENT_SECRET"],
        user_agent=UA,
    )
    out = []
    for sub in SUBREDDITS:
        n = 0
        for p in reddit.subreddit(sub).hot(limit=25):
            if p.stickied or p.over_18 or p.score < MIN_SCORE:
                continue
            out.append(to_candidate(
                sub, p.title, int(p.score), int(p.num_comments),
                "https://reddit.com" + p.permalink,
                p.url if not p.is_self else "",
                p.created_utc, getattr(p, "selftext", "")))
            n += 1
            if n >= MAX_PER_SUB:
                break
        print(f"r/{sub}: {n} candidates (praw)")
    return out


def via_public_json():
    from scrapling.fetchers import Fetcher
    out = []
    for sub in SUBREDDITS:
        url = f"https://www.reddit.com/r/{sub}/hot.json?limit=25"
        page = Fetcher.get(url, headers={"User-Agent": UA}, timeout=30)
        if page.status != 200:
            print(f"  ! r/{sub} HTTP {page.status}", file=sys.stderr)
            continue
        n = 0
        for child in json.loads(page.body).get("data", {}).get("children", []):
            p = child.get("data", {})
            if p.get("stickied") or p.get("over_18"):
                continue
            ups = int(p.get("ups", 0))
            if ups < MIN_SCORE:
                continue
            title = (p.get("title") or "").strip()
            if not title:
                continue
            out.append(to_candidate(
                sub, title, ups, int(p.get("num_comments", 0)),
                "https://reddit.com" + (p.get("permalink") or ""),
                p.get("url_overridden_by_dest") or p.get("url"),
                p.get("created_utc"), p.get("selftext")))
            n += 1
            if n >= MAX_PER_SUB:
                break
        print(f"r/{sub}: {n} candidates (public json)")
    return out


def post_ingest(items):
    payload = json.dumps({"token": os.environ["INGEST_TOKEN"], "items": items}).encode()
    req = urllib.request.Request(os.environ["INGEST_URL"], data=payload,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def main():
    if not os.environ.get("INGEST_URL") or not os.environ.get("INGEST_TOKEN"):
        print("missing INGEST_URL / INGEST_TOKEN", file=sys.stderr)
        return 1
    if os.environ.get("REDDIT_CLIENT_ID") and os.environ.get("REDDIT_CLIENT_SECRET"):
        items = via_praw()
    else:
        print("no Reddit API creds | using public json fallback")
        items = via_public_json()
    if not items:
        print("no candidates this run")
        return 0
    res = post_ingest(items)
    print(f"ingest: {res}")
    return 0 if res.get("ok") else 1


if __name__ == "__main__":
    sys.exit(main())
