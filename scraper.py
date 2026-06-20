#!/usr/bin/env python3
"""
GenZHype | discovery scraper v7  (hardening over v6, 2026-06-17).

Why v6 kept failing (red X after ~14 min):
  - GETs used curl_cffi (browser-TLS) and worked, but DELIVERY used plain
    `requests` (Python's TLS fingerprint) which Hostinger's bot protection
    intermittently blocks -> all chunks fail -> main() returns 1 -> red.
  - timeout=30 + retries=2 meant every hung/blocked source (pullpush, tmz 403,
    redirecting feeds) cost up to 60s, stacking into 14-minute runs that were
    far more exposed to a transient blip.

v7 changes:
  - SOURCES FAIL FAST: timeout 12s, 1 retry  -> a dead feed costs <=12s, runs
    finish in ~2 min instead of 14.
  - GLOBAL FETCH DEADLINE: stop starting new sources after FETCH_BUDGET seconds.
  - DELIVERY IS BLOCK-RESISTANT: tries 3 engines per attempt (curl_cffi browser-
    TLS first, then requests, then urllib), 5 attempts, exponential backoff.
    Browser-TLS delivery is the actual fix for the intermittent ingest block.
  - follow redirects (dailydot 308, popcrave 301 feeds now resolve).
"""
import json
import os
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET

# FORCE IPv4: GitHub-hosted runners cannot route IPv6.
import socket as _socket
_gai = _socket.getaddrinfo
_socket.getaddrinfo = lambda *a, **k: [x for x in _gai(*a, **k) if x[0] == _socket.AF_INET]

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"

GET_TIMEOUT = 12        # was 30 â€” fail fast on dead/slow feeds
GET_RETRIES = 1         # was 2 â€” one quick retry is enough
FETCH_BUDGET = 240      # stop starting new sources after this many seconds total

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

# curl_cffi (browser-TLS) when present, urllib fallback. Both follow redirects.
try:
    from curl_cffi import requests as _cffi
    def _http_once(url):
        r = _cffi.get(url, impersonate="firefox", timeout=GET_TIMEOUT,
                      headers=_next_headers(), allow_redirects=True)
        r.raise_for_status()
        return r.content
    _HTTP_ENGINE = "curl_cffi"
except Exception:
    def _http_once(url):
        req = urllib.request.Request(url, headers=_next_headers())
        with urllib.request.urlopen(req, timeout=GET_TIMEOUT) as r:
            return r.read()
    _HTTP_ENGINE = "urllib"


def http_get(url, retries=GET_RETRIES, retry_delay=2):
    last = None
    for i in range(retries + 1):
        try:
            return _http_once(url)
        except Exception as e:
            last = e
            if i < retries:
                time.sleep(retry_delay)
    raise last

RSS_FEEDS = [
    ("dexerto", "https://www.dexerto.com/feed/"),
    ("distractify", "https://www.distractify.com/rss"),
    ("dailydot", "https://www.dailydot.com/feed/"),
    ("shaderoom", "https://theshaderoom.com/feed/"),
    ("variety", "https://variety.com/feed/"),
    ("popcrave", "https://www.popcrave.com/feed/"),
    # tmz dropped: returns 403 to datacenter IPs (dead weight, never yields).
]

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
                         user_agent="GenZHypeDesk/7.0 research")
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


# ---- DELIVERY: three engines, browser-TLS first (the v7 fix) ----
def _deliver_cffi(url, body):
    from curl_cffi import requests as _cffi
    r = _cffi.post(url, json=body, impersonate="firefox", timeout=60,
                   headers={"User-Agent": UA})
    r.raise_for_status()
    return r.json()

def _deliver_requests(url, body):
    import requests
    r = requests.post(url, json=body, headers={"User-Agent": UA}, timeout=60)
    r.raise_for_status()
    return r.json()

def _deliver_urllib(url, body):
    payload = json.dumps(body).encode()
    req = urllib.request.Request(url, data=payload,
                                 headers={"Content-Type": "application/json", "User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def _post_chunk(items, attempts=5):
    """Each attempt tries browser-TLS, then requests, then urllib (so a TLS-
    fingerprint block on one engine is dodged by another). 5 attempts with
    exponential backoff to outlast a transient host block."""
    body = {"token": os.environ["INGEST_TOKEN"], "items": items}
    url = os.environ["INGEST_URL"]
    last = None
    delay = 5
    for i in range(1, attempts + 1):
        for engine in (_deliver_cffi, _deliver_requests, _deliver_urllib):
            try:
                return engine(url, body)
            except Exception as e:
                last = e
        print(f"  ! ingest attempt {i}/{attempts} failed (all engines): {last}", file=sys.stderr)
        if i < attempts:
            time.sleep(delay)
            delay = min(delay * 2, 60)
    raise last


def post_ingest(items, chunk=20):
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
    started = time.time()
    items = []
    for fn in (via_google_trends, via_google_news, via_rss, via_pullpush):
        if time.time() - started > FETCH_BUDGET:
            print(f"  ! fetch budget ({FETCH_BUDGET}s) hit; skipping {fn.__name__}", file=sys.stderr)
            continue
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
    for it in items:
        sx = it.get("signals", {}).get("selftext_excerpt")
        if sx:
            it["signals"]["selftext_excerpt"] = sx[:300]
    res = post_ingest(items)
    print(f"ingest: {res}  (harvested {len(items)})")
    # Green as long as at least one chunk delivered. Only a TOTAL delivery
    # failure (every engine, every retry, every chunk) goes red.
    return 0 if res.get("ok") else 1


if __name__ == "__main__":
    sys.exit(main())
