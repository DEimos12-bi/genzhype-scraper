#!/usr/bin/env python3
"""
GenZHype | discovery scraper v5.
Sources that work from datacenter IPs, no auth:
  - Google Trends RSS (US trending searches + traffic estimates)
  - Pop-culture / creator-news RSS feeds (TMZ, Dexerto, etc.)
Optional when creds exist: Reddit via PRAW. Reddit public JSON kept for local runs.
POSTs candidates to the site's ingest API.
v5 fixes: force IPv4 (GitHub runners have no IPv6 route -> errno 101 on hosts
with AAAA records), deliver via requests when available (proven path), and
retry delivery 3x before failing the run.
"""
import json
import os
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET

# FORCE IPv4: GitHub-hosted runners cannot route IPv6; genzhype.com publishes
# an AAAA record, so stdlib connects tried IPv6 first and died (errno 101).
import socket as _socket
_gai = _socket.getaddrinfo
_socket.getaddrinfo = lambda *a, **k: [x for x in _gai(*a, **k) if x[0] == _socket.AF_INET]

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"

RSS_FEEDS = [
    ("tmz", "https://www.tmz.com/rss.xml"),
    ("dexerto", "https://www.dexerto.com/feed/"),
    ("distractify", "https://www.distractify.com/rss"),
    ("dailydot", "https://www.dailydot.com/feed/"),
]

# creator-drama relevance filter for news/trends
KEYWORDS = re.compile(
    r"tiktok|youtub|stream|twitch|kick\b|influencer|creator|drama|feud|beef|"
    r"onlyfans|podcast|viral|expose|allegat|apolog|cancel|leak|deplatform|"
    r"mrbeast|ishowspeed|kai cenat|adin ross|fanum|druski|sketch\b",
    re.I,
)

TRENDS_RSS = "https://trends.google.com/trending/rss?geo=US"
MIN_SCORE = 200
MAX_PER_SUB = 8


def heat(upvotes, comments):
    raw = upvotes + comments * 3
    if raw >= 20000: return 95
    if raw >= 10000: return 85
    if raw >= 5000:  return 75
    if raw >= 2000:  return 60
    if raw >= 800:   return 45
    return 30


def http_get(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def candidate(name, angle, score, signals):
    return {"type": "drama", "name": name[:240], "angle": angle[:240],
            "heat_score": score, "era": "present", "signals": signals}


def traffic_to_heat(t):
    n = re.sub(r"[^0-9]", "", t or "")
    n = int(n) if n else 0
    if n >= 2000000: return 95
    if n >= 1000000: return 85
    if n >= 500000:  return 70
    if n >= 200000:  return 55
    return 40


def via_google_trends():
    out = []
    try:
        root = ET.fromstring(http_get(TRENDS_RSS))
    except Exception as e:
        print(f"  ! google trends failed: {e}", file=sys.stderr)
        return out
    ns = {"ht": "https://trends.google.com/trending/rss"}
    for item in root.iter("item"):
        title = (item.findtext("title") or "").strip()
        traffic = item.findtext("ht:approx_traffic", default="", namespaces=ns)
        if not title:
            continue
        h = traffic_to_heat(traffic)
        # keep if drama-relevant, or if it is a huge cultural moment
        if not KEYWORDS.search(title) and h < 70:
            continue
        news_title = ""
        news = item.find("ht:news_item", ns)
        if news is not None:
            news_title = (news.findtext("ht:news_item_title", default="", namespaces=ns) or "").strip()
        out.append(candidate(
            title, f"US Google Trends ({traffic or 'rising'})", h,
            {"source": "google-trends", "approx_traffic": traffic, "news_title": news_title[:300]}))
    print(f"google trends: {len(out)} candidates")
    return out


def via_rss():
    out = []
    for feed_name, url in RSS_FEEDS:
        try:
            root = ET.fromstring(http_get(url))
        except Exception as e:
            print(f"  ! {feed_name} rss failed: {e}", file=sys.stderr)
            continue
        n = 0
        for item in root.iter("item"):
            title = (item.findtext("title") or "").strip()
            link = (item.findtext("link") or "").strip()
            desc = re.sub(r"<[^>]+>", " ", item.findtext("description") or "")[:600]
            if not title or not KEYWORDS.search(title + " " + desc):
                continue
            out.append(candidate(
                title, f"via {feed_name} rss", 45,
                {"source": f"rss:{feed_name}", "url": link, "desc_excerpt": desc.strip()}))
            n += 1
            if n >= 6:
                break
        print(f"{feed_name} rss: {n} candidates")
    return out


def via_praw():
    import praw
    reddit = praw.Reddit(client_id=os.environ["REDDIT_CLIENT_ID"],
                         client_secret=os.environ["REDDIT_CLIENT_SECRET"],
                         user_agent="GenZHypeDesk/4.0 research")
    out = []
    for sub in ["youtubedrama", "LivestreamFail", "Fauxmoi", "InternetDrama", "TikTokCringe"]:
        n = 0
        for p in reddit.subreddit(sub).hot(limit=25):
            if p.stickied or p.over_18 or p.score < MIN_SCORE:
                continue
            out.append(candidate(
                p.title, f"surfaced on r/{sub}", heat(int(p.score), int(p.num_comments)),
                {"source": f"reddit:r/{sub}", "ups": int(p.score), "comments": int(p.num_comments),
                 "permalink": "https://reddit.com" + p.permalink,
                 "selftext_excerpt": (getattr(p, "selftext", "") or "")[:800]}))
            n += 1
            if n >= MAX_PER_SUB:
                break
        print(f"r/{sub}: {n} candidates (praw)")
    return out


def post_ingest_once(items):
    """One delivery attempt. Prefers requests (the proven path from the
    receipts worker); falls back to stdlib urllib if requests is absent."""
    body = {"token": os.environ["INGEST_TOKEN"], "items": items}
    url = os.environ["INGEST_URL"]
    try:
        import requests
        r = requests.post(url, json=body, headers={"User-Agent": UA}, timeout=30)
        r.raise_for_status()
        return r.json()
    except ImportError:
        payload = json.dumps(body).encode()
        req = urllib.request.Request(url, data=payload,
                                     headers={"Content-Type": "application/json", "User-Agent": UA})
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode())


def post_ingest(items, attempts=3):
    last = None
    for i in range(1, attempts + 1):
        try:
            return post_ingest_once(items)
        except Exception as e:
            last = e
            print(f"  ! ingest attempt {i}/{attempts} failed: {e}", file=sys.stderr)
            if i < attempts:
                time.sleep(20)
    raise last


def main():
    if not os.environ.get("INGEST_URL") or not os.environ.get("INGEST_TOKEN"):
        print("missing INGEST_URL / INGEST_TOKEN", file=sys.stderr)
        return 1
    items = []
    items += via_google_trends()
    items += via_rss()
    if os.environ.get("REDDIT_CLIENT_ID") and os.environ.get("REDDIT_CLIENT_SECRET"):
        try:
            items += via_praw()
        except Exception as e:
            print(f"  ! praw failed: {e}", file=sys.stderr)
    if not items:
        print("no candidates this run")
        return 0
    res = post_ingest(items)
    print(f"ingest: {res}")
    return 0 if res.get("ok") else 1


if __name__ == "__main__":
    sys.exit(main())
