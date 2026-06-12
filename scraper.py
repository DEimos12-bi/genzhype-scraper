#!/usr/bin/env python3
"""
GenZHype | discovery scraper v6.
Open, no-auth, datacenter-safe sources (widened from v5):
  - Google News RSS search  (creator-drama queries; biggest coverage source)
  - Publisher RSS feeds      (TMZ, Dexerto, Distractify, DailyDot, Shade Room,
                              Variety, Pop Crave)
  - Google Trends RSS        (US trending + traffic estimates)
  - Reddit via PullPush       (open mirror; Reddit stopped free API keys 12/2025,
                              public JSON now bot-walled, PullPush is the 2026 way)
  - Reddit via PRAW           (only if creds are provided; optional)
POSTs candidates to the site's ingest API.

v6 hardening (techniques studied from Scrapling's HTTP layer):
  - force IPv4 (GitHub runners have no IPv6 route -> errno 101)
  - curl_cffi browser-TLS impersonation when available, urllib otherwise
  - per-source try/except so one dead feed never sinks the run
  - delivery via requests, 3 retries
"""
import json
import os
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET

# FORCE IPv4: GitHub-hosted runners cannot route IPv6; some hosts publish AAAA
# records, so stdlib otherwise tries IPv6 first and dies (errno 101).
import socket as _socket
_gai = _socket.getaddrinfo
_socket.getaddrinfo = lambda *a, **k: [x for x in _gai(*a, **k) if x[0] == _socket.AF_INET]

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"

# Realistic browser header SETS (Scrapling/browserforge idea, dependency-free):
# rotate per run so the plain-urllib fallback path doesn't send one fixed
# fingerprint. Rotation is per-process-run (seeded by run minute via os time
# is unavailable deterministically; we rotate by a module counter instead).
_HEADER_SETS = [
    {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0",
     "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8", "Accept-Language": "en-US,en;q=0.9"},
    {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
     "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,*/*;q=0.8", "Accept-Language": "en-US,en;q=0.9"},
    {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15",
     "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8", "Accept-Language": "en-US,en;q=0.9"},
    {"User-Agent": "Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0",
     "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8", "Accept-Language": "en-US,en;q=0.9"},
]
_hdr_i = 0
def _next_headers():
    global _hdr_i
    h = _HEADER_SETS[_hdr_i % len(_HEADER_SETS)]
    _hdr_i += 1
    return dict(h)

# --- HTTP GET: mirrors Scrapling's "static engine" (curl_cffi browser-TLS
#     impersonation + stealthy headers + retries + timeout). curl_cffi when
#     present (TLS fingerprint dodges naive datacenter blocks), urllib fallback
#     with rotating realistic headers otherwise.
try:
    from curl_cffi import requests as _cffi
    def _http_once(url):
        r = _cffi.get(url, impersonate="firefox", timeout=30, headers=_next_headers())
        r.raise_for_status()
        return r.content
    _HTTP_ENGINE = "curl_cffi"
except Exception:
    def _http_once(url):
        req = urllib.request.Request(url, headers=_next_headers())
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.read()
    _HTTP_ENGINE = "urllib"


def http_get(url, retries=2, retry_delay=2):
    # fail FAST on a slow/dead feed (1 retry) so one hung source can't bloat the
    # whole run; a missed feed just yields fewer candidates this cycle.
    last = None
    for i in range(retries):
        try:
            return _http_once(url)
        except Exception as e:
            last = e
            if i < retries - 1:
                time.sleep(retry_delay)
    raise last

RSS_FEEDS = [
    ("tmz", "https://www.tmz.com/rss.xml"),
    ("dexerto", "https://www.dexerto.com/feed/"),
    ("distractify", "https://www.distractify.com/rss"),
    ("dailydot", "https://www.dailydot.com/feed/"),
    ("shaderoom", "https://theshaderoom.com/feed/"),
    ("variety", "https://variety.com/feed/"),
    ("popcrave", "https://www.popcrave.com/feed/"),
]

# Google News RSS search — open, ~100 items/query, no key. The widest net.
GOOGLE_NEWS_QUERIES = [
    "youtuber drama OR controversy",
    "tiktok creator feud OR beef",
    "twitch streamer drama",
    "influencer allegations OR apology",
]
def google_news_url(q):
    from urllib.parse import quote_plus
    return f"https://news.google.com/rss/search?q={quote_plus(q)}&hl=en-US&gl=US&ceid=US:en"

REDDIT_SUBS = ["youtubedrama", "LivestreamFail", "Fauxmoi", "InternetDrama", "TikTokCringe"]

# creator-drama relevance filter for news/trends
KEYWORDS = re.compile(
    r"tiktok|youtub|stream|twitch|kick\b|influencer|creator|drama|feud|beef|"
    r"onlyfans|podcast|viral|expose|allegat|apolog|cancel|leak|deplatform|"
    r"mrbeast|ishowspeed|kai cenat|adin ross|fanum|druski|sketch\b",
    re.I,
)

TRENDS_RSS = "https://trends.google.com/trending/rss?geo=US"
MIN_SCORE = 200
MAX_PER_SOURCE = 6


def heat(upvotes, comments):
    raw = upvotes + comments * 3
    if raw >= 20000: return 95
    if raw >= 10000: return 85
    if raw >= 5000:  return 75
    if raw >= 2000:  return 60
    if raw >= 800:   return 45
    return 30


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
        if not KEYWORDS.search(title) and h < 70:
            continue
        news = item.find("ht:news_item", ns)
        news_title = (news.findtext("ht:news_item_title", default="", namespaces=ns).strip()
                      if news is not None else "")
        out.append(candidate(title, f"US Google Trends ({traffic or 'rising'})", h,
                             {"source": "google-trends", "approx_traffic": traffic,
                              "news_title": news_title[:300]}))
    print(f"google trends: {len(out)} candidates")
    return out


def via_google_news():
    out = []
    for q in GOOGLE_NEWS_QUERIES:
        try:
            root = ET.fromstring(http_get(google_news_url(q)))
        except Exception as e:
            print(f"  ! google news '{q}' failed: {e}", file=sys.stderr)
            continue
        n = 0
        for item in root.iter("item"):
            title = (item.findtext("title") or "").strip()
            link = (item.findtext("link") or "").strip()
            if not title or not KEYWORDS.search(title):
                continue
            out.append(candidate(title, f"Google News: {q}", 50,
                                {"source": "google-news", "query": q, "url": link}))
            n += 1
            if n >= MAX_PER_SOURCE:
                break
        print(f"google news '{q}': {n} candidates")
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
            out.append(candidate(title, f"via {feed_name} rss", 45,
                                {"source": f"rss:{feed_name}", "url": link,
                                 "desc_excerpt": desc.strip()}))
            n += 1
            if n >= MAX_PER_SOURCE:
                break
        print(f"{feed_name} rss: {n} candidates")
    return out


def via_pullpush():
    """Reddit via PullPush.io — open mirror, no auth. Reddit killed free API
    keys 12/2025 and bot-walled public JSON; PullPush is the 2026 free route."""
    out = []
    for sub in REDDIT_SUBS:
        url = (f"https://api.pullpush.io/reddit/search/submission/"
               f"?subreddit={sub}&sort=desc&sort_type=score&size=15")
        try:
            data = json.loads(http_get(url)).get("data", [])
        except Exception as e:
            print(f"  ! pullpush r/{sub} failed: {e}", file=sys.stderr)
            continue
        n = 0
        for p in data:
            if p.get("stickied") or p.get("over_18"):
                continue
            ups = int(p.get("score", 0) or 0)
            if ups < MIN_SCORE:
                continue
            title = (p.get("title") or "").strip()
            if not title:
                continue
            out.append(candidate(title, f"surfaced on r/{sub}",
                                heat(ups, int(p.get("num_comments", 0) or 0)),
                                {"source": f"reddit:r/{sub}", "ups": ups,
                                 "comments": int(p.get("num_comments", 0) or 0),
                                 "permalink": "https://reddit.com" + (p.get("permalink") or ""),
                                 "external_url": p.get("url") or "",
                                 "selftext_excerpt": (p.get("selftext") or "")[:800]}))
            n += 1
            if n >= MAX_PER_SOURCE:
                break
        print(f"r/{sub}: {n} candidates (pullpush)")
    return out


def via_praw():
    import praw
    reddit = praw.Reddit(client_id=os.environ["REDDIT_CLIENT_ID"],
                         client_secret=os.environ["REDDIT_CLIENT_SECRET"],
                         user_agent="GenZHypeDesk/6.0 research")
    out = []
    for sub in REDDIT_SUBS:
        n = 0
        for p in reddit.subreddit(sub).hot(limit=25):
            if p.stickied or p.over_18 or p.score < MIN_SCORE:
                continue
            out.append(candidate(p.title, f"surfaced on r/{sub}",
                                heat(int(p.score), int(p.num_comments)),
                                {"source": f"reddit:r/{sub}", "ups": int(p.score),
                                 "comments": int(p.num_comments),
                                 "permalink": "https://reddit.com" + p.permalink,
                                 "selftext_excerpt": (getattr(p, "selftext", "") or "")[:800]}))
            n += 1
            if n >= MAX_PER_SOURCE:
                break
        print(f"r/{sub}: {n} candidates (praw)")
    return out


def _post_chunk(items, attempts=3):
    """Deliver one chunk. requests when present (robust), urllib otherwise.
    60s timeout; 3 attempts 15s apart. Returns the parsed JSON or raises."""
    body = {"token": os.environ["INGEST_TOKEN"], "items": items}
    url = os.environ["INGEST_URL"]
    last = None
    for i in range(1, attempts + 1):
        try:
            try:
                import requests
                r = requests.post(url, json=body, headers={"User-Agent": UA}, timeout=60)
                r.raise_for_status()
                return r.json()
            except ImportError:
                payload = json.dumps(body).encode()
                req = urllib.request.Request(url, data=payload,
                                             headers={"Content-Type": "application/json", "User-Agent": UA})
                with urllib.request.urlopen(req, timeout=60) as r:
                    return json.loads(r.read().decode())
        except Exception as e:
            last = e
            print(f"  ! ingest attempt {i}/{attempts} failed: {e}", file=sys.stderr)
            if i < attempts:
                time.sleep(15)
    raise last


def post_ingest(items, chunk=20):
    """Deliver in small chunks: each POST is fast (dodges payload/connect
    timeouts) and partial success is kept even if a later chunk fails."""
    total_ins, total_skip, sent_ok = 0, 0, 0
    for c in range(0, len(items), chunk):
        part = items[c:c + chunk]
        try:
            res = _post_chunk(part)
            total_ins += int(res.get("inserted", 0))
            total_skip += int(res.get("skipped_dupes_or_invalid", 0))
            sent_ok += len(part)
        except Exception as e:
            print(f"  ! chunk {c // chunk + 1} dropped after retries: {e}", file=sys.stderr)
    return {"ok": sent_ok > 0, "inserted": total_ins,
            "skipped_dupes_or_invalid": total_skip, "delivered": sent_ok}


def dedupe(items):
    seen, out = set(), []
    for it in items:
        key = re.sub(r"\s+", " ", (it["name"] or "").lower()).strip()[:120]
        if key and key not in seen:
            seen.add(key)
            out.append(it)
    return out


def main():
    if not os.environ.get("INGEST_URL") or not os.environ.get("INGEST_TOKEN"):
        print("missing INGEST_URL / INGEST_TOKEN", file=sys.stderr)
        return 1
    print(f"http engine: {_HTTP_ENGINE}")
    items = []
    for fn in (via_google_trends, via_google_news, via_rss, via_pullpush):
        try:
            items += fn()
        except Exception as e:
            print(f"  ! {fn.__name__} crashed: {e}", file=sys.stderr)
    if os.environ.get("REDDIT_CLIENT_ID") and os.environ.get("REDDIT_CLIENT_SECRET"):
        try:
            items += via_praw()
        except Exception as e:
            print(f"  ! praw failed: {e}", file=sys.stderr)
    items = dedupe(items)
    if not items:
        print("no candidates this run")
        return 0
    # trim bulky Reddit text so POST bodies stay small and fast
    for it in items:
        sx = it.get("signals", {}).get("selftext_excerpt")
        if sx:
            it["signals"]["selftext_excerpt"] = sx[:300]
    res = post_ingest(items)
    print(f"ingest: {res}  (harvested {len(items)})")
    return 0 if res.get("ok") else 1


if __name__ == "__main__":
    sys.exit(main())
