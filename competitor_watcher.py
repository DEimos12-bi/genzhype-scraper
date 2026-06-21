#!/usr/bin/env python3
"""
GenZHype | Competitor Intelligence WATCHER  (Phase 2 of the Competitor Engine).

Runs externally on GitHub Actions (like scraper.py). For each competitor it:
  1. finds sitemaps (robots.txt -> Sitemap: lines, then /sitemap.xml), parses them
     recursively -> (url, lastmod). lastmod = publishing cadence; the URL SET = which
     pages exist (the brain diffs it to find newly-published pages).
  2. takes the most RECENT articles and, per article, extracts the "why they win" signals:
       - content  via trafilatura (clean word_count, H2 outline, outbound link domains,
                   author, publish date)
       - schema   via extruct (their JSON-LD @type set, sameAs/speakable/FAQPage presence)
       - head tags via lxml (title, meta description, canonical, og:type)
  3. POSTs all signal bundles to the PHP brain (/api/comp_ingest.php), which stores +
     diffs them over time. Delivery reuses scraper.py's browser-TLS engines so Hostinger
     bot-protection can't block it.

Env:
  COMP_INGEST_URL   e.g. https://genzhype.com/api/comp_ingest.php   (required)
  INGEST_TOKEN      the site ingest token                          (required)
  COMPETITORS       comma-separated competitor domains (optional; sensible default below)
  ARTICLES_PER_COMP how many recent articles to analyze per competitor (default 8)

requirements: trafilatura>=1.8  extruct>=0.16  curl_cffi>=0.7  lxml>=5  requests>=2.31
"""
import json
import os
import re
import sys
import time
import hashlib
import urllib.request
import xml.etree.ElementTree as ET
from urllib.parse import urljoin, urlparse

# FORCE IPv4 (GitHub runners have no IPv6 route) — same as scraper.py.
import socket as _socket
_gai = _socket.getaddrinfo
_socket.getaddrinfo = lambda *a, **k: [x for x in _gai(*a, **k) if x[0] == _socket.AF_INET]

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"
GET_TIMEOUT = 15
FETCH_BUDGET = 300            # stop starting new competitors after this many seconds
ARTICLES_PER_COMP = int(os.environ.get("ARTICLES_PER_COMP", "8"))

# The competitive set (edit via the COMPETITORS env / GitHub secret). These are real
# creator-culture / drama / explainer sites that compete for the same queries.
DEFAULT_COMPETITORS = [
    "dexerto.com", "distractify.com", "knowyourmeme.com", "thethings.com",
    "popcrave.com", "dailydot.com", "thetab.com",
]
COMPETITORS = [d.strip() for d in os.environ.get("COMPETITORS", ",".join(DEFAULT_COMPETITORS)).split(",") if d.strip()]

# ---- browser-TLS HTTP (curl_cffi) with urllib fallback, like scraper.py ----
try:
    from curl_cffi import requests as _cffi
    def _http_once(url):
        r = _cffi.get(url, impersonate="firefox", timeout=GET_TIMEOUT,
                      headers={"User-Agent": UA}, allow_redirects=True)
        r.raise_for_status()
        return r.content
    _ENGINE = "curl_cffi"
except Exception:
    def _http_once(url):
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        with urllib.request.urlopen(req, timeout=GET_TIMEOUT) as r:
            return r.read()
    _ENGINE = "urllib"

def http_get(url, retries=1):
    last = None
    for i in range(retries + 1):
        try:
            return _http_once(url)
        except Exception as e:
            last = e
            if i < retries:
                time.sleep(2)
    raise last

def http_text(url):
    try:
        return http_get(url).decode("utf-8", "replace")
    except Exception:
        return ""

# ---- sitemap discovery + parsing ----
def find_sitemaps(domain):
    out = []
    robots = http_text(f"https://{domain}/robots.txt")
    for line in robots.splitlines():
        if line.lower().startswith("sitemap:"):
            out.append(line.split(":", 1)[1].strip())
    if not out:
        out = [f"https://{domain}/sitemap.xml", f"https://{domain}/sitemap_index.xml"]
    return out

def _strip_ns(tag):
    return tag.split("}", 1)[-1] if "}" in tag else tag

def parse_sitemap(url, depth=0, seen=None):
    """Return list of (loc, lastmod). Recurses into <sitemapindex>. Bounded."""
    if seen is None:
        seen = set()
    if url in seen or depth > 2 or len(seen) > 60:
        return []
    seen.add(url)
    raw = http_get(url) if not url.endswith(".gz") else b""   # skip gzip for v1
    if not raw:
        return []
    try:
        root = ET.fromstring(raw)
    except Exception:
        return []
    rows, children = [], []
    for el in root.iter():
        tag = _strip_ns(el.tag)
        if tag == "sitemap":          # index entry
            loc = el.findtext("{*}loc") or "".join(c.text or "" for c in el if _strip_ns(c.tag) == "loc")
            if loc:
                children.append(loc.strip())
        elif tag == "url":
            loc = lastmod = None
            for c in el:
                ct = _strip_ns(c.tag)
                if ct == "loc":
                    loc = (c.text or "").strip()
                elif ct == "lastmod":
                    lastmod = (c.text or "").strip()
            if loc:
                rows.append((loc, lastmod or ""))
    for ch in children[:25]:
        rows += parse_sitemap(ch, depth + 1, seen)
    return rows

# ---- per-article signal extraction ----
def head_tags(html):
    out = {"title": "", "meta_description": "", "canonical": "", "og_type": ""}
    try:
        import lxml.html as LH
        doc = LH.fromstring(html)
        t = doc.findtext(".//title")
        out["title"] = (t or "").strip()[:300]
        for m in doc.xpath('//meta[@name="description"]/@content'):
            out["meta_description"] = m.strip()[:400]; break
        for c in doc.xpath('//link[@rel="canonical"]/@href'):
            out["canonical"] = c.strip()[:700]; break
        for o in doc.xpath('//meta[@property="og:type"]/@content'):
            out["og_type"] = o.strip()[:60]; break
        out["h2_set"] = sorted({(h.text_content() or "").strip()[:120] for h in doc.xpath("//h2") if (h.text_content() or "").strip()})
        out["h1"] = ((doc.xpath("//h1") or [None])[0].text_content().strip()[:200] if doc.xpath("//h1") else "")
    except Exception:
        out["h2_set"] = []
        out["h1"] = ""
    return out

def schema_signals(html, url):
    sig = {"schema_types": [], "sameAs": False, "speakable": False, "faqpage": False, "videoobject": False, "author_person": False}
    try:
        import extruct
        data = extruct.extract(html, base_url=url, syntaxes=["json-ld", "opengraph", "microdata"], uniform=True, errors="log")
        items = data.get("json-ld", []) or []
        types = set()
        for it in items:
            t = it.get("@type")
            for x in ([t] if isinstance(t, str) else (t or [])):
                if isinstance(x, str):
                    types.add(x)
            if "sameAs" in it: sig["sameAs"] = True
            if "speakable" in it: sig["speakable"] = True
            auth = it.get("author")
            if isinstance(auth, dict) and auth.get("@type") == "Person": sig["author_person"] = True
        sig["schema_types"] = sorted(types)
        sig["faqpage"] = "FAQPage" in types
        sig["videoobject"] = "VideoObject" in types
    except Exception as e:
        print(f"    ! schema extract failed: {e}", file=sys.stderr)
    return sig

def content_signals(html, url):
    sig = {"word_count": 0, "outbound_domains": [], "author": "", "published_date": ""}
    try:
        import trafilatura
        doc = trafilatura.bare_extraction(html, url=url, with_metadata=True,
                                          include_links=True, include_comments=False, favor_precision=True)
        if doc:
            text = (doc.text or "") if hasattr(doc, "text") else (doc.get("text") or "")
            sig["word_count"] = len(text.split())
            sig["author"] = (getattr(doc, "author", None) or (doc.get("author") if isinstance(doc, dict) else "") or "")[:120]
            sig["published_date"] = (getattr(doc, "date", None) or (doc.get("date") if isinstance(doc, dict) else "") or "")[:20]
            sig["_text"] = text
    except Exception as e:
        print(f"    ! content extract failed: {e}", file=sys.stderr)
    # outbound domains via lxml (external <a href>)
    try:
        import lxml.html as LH
        host = urlparse(url).netloc
        doms = set()
        for href in LH.fromstring(html).xpath("//a/@href"):
            h = urlparse(urljoin(url, href)).netloc
            if h and h != host and not h.endswith(host):
                doms.add(h.replace("www.", ""))
        sig["outbound_domains"] = sorted(doms)[:25]
    except Exception:
        pass
    return sig

def analyze_article(url):
    html = http_text(url)
    if len(html) < 500:
        return None
    head = head_tags(html)
    cont = content_signals(html, url)
    sch = schema_signals(html, url)
    text = cont.pop("_text", "")
    fields = {
        "title": head["title"], "h1": head["h1"], "meta_description": head["meta_description"],
        "canonical": head["canonical"], "og_type": head["og_type"], "h2_set": head.get("h2_set", []),
        "word_count": cont["word_count"], "outbound_domains": cont["outbound_domains"],
        "author": cont["author"], "published_date": cont["published_date"],
        "schema_types": sch["schema_types"], "sameAs": sch["sameAs"], "speakable": sch["speakable"],
        "faqpage": sch["faqpage"], "videoobject": sch["videoobject"], "author_person": sch["author_person"],
    }
    raw_hash = hashlib.md5((text or json.dumps(fields)).encode("utf-8", "replace")).hexdigest()
    return {"url": url, "watch_type": "page", "raw_text_hash": raw_hash, "fields": fields}


def watch_competitor(domain):
    items = []
    entries = []
    for sm in find_sitemaps(domain):
        try:
            entries += parse_sitemap(sm)
        except Exception as e:
            print(f"  ! sitemap {sm} failed: {e}", file=sys.stderr)
        if len(entries) > 4000:
            break
    if not entries:
        print(f"  {domain}: no sitemap entries", file=sys.stderr)
        return items
    # sitemap-level signal: the URL set (brain diffs it -> newly published pages) + cadence
    locs = sorted({u for u, _ in entries})
    items.append({"url": f"https://{domain}/__sitemap__", "watch_type": "sitemap",
                  "raw_text_hash": hashlib.md5(("".join(locs)).encode()).hexdigest(),
                  "fields": {"url_count": len(locs), "url_set": locs[:1500],
                             "newest_lastmod": max((lm for _, lm in entries if lm), default="")}})
    # recent articles by lastmod
    dated = sorted([e for e in entries if e[1]], key=lambda e: e[1], reverse=True)
    pick = [u for u, _ in (dated[:ARTICLES_PER_COMP] or entries[:ARTICLES_PER_COMP])]
    for u in pick:
        try:
            a = analyze_article(u)
            if a:
                items.append(a)
            time.sleep(1)              # politeness
        except Exception as e:
            print(f"    ! analyze {u} failed: {e}", file=sys.stderr)
    print(f"  {domain}: {len(items)} signals ({len(pick)} articles)")
    return items


# ---- delivery: browser-TLS first, then requests, then urllib (like scraper.py v7) ----
def _deliver_cffi(url, body):
    from curl_cffi import requests as _cffi
    r = _cffi.post(url, json=body, impersonate="firefox", timeout=60, headers={"User-Agent": UA}); r.raise_for_status(); return r.json()
def _deliver_requests(url, body):
    import requests
    r = requests.post(url, json=body, headers={"User-Agent": UA}, timeout=60); r.raise_for_status(); return r.json()
def _deliver_urllib(url, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers={"Content-Type": "application/json", "User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())

def deliver(items, chunk=40):
    url = os.environ["COMP_INGEST_URL"]; tok = os.environ["INGEST_TOKEN"]
    sent = {"first_seen": 0, "changed": 0, "unchanged": 0, "errors": 0}
    for c in range(0, len(items), chunk):
        body = {"token": tok, "items": items[c:c + chunk]}
        last = None
        for i in range(1, 6):
            for engine in (_deliver_cffi, _deliver_requests, _deliver_urllib):
                try:
                    res = engine(url, body)
                    for k in sent:
                        sent[k] += int(res.get(k, 0))
                    last = None; break
                except Exception as e:
                    last = e
            if last is None:
                break
            print(f"  ! deliver chunk attempt {i}/5 failed: {last}", file=sys.stderr)
            time.sleep(min(5 * 2 ** (i - 1), 60))
        if last is not None:
            print(f"  ! chunk dropped after retries: {last}", file=sys.stderr)
    return sent


def main():
    if not os.environ.get("COMP_INGEST_URL") or not os.environ.get("INGEST_TOKEN"):
        print("missing COMP_INGEST_URL / INGEST_TOKEN", file=sys.stderr); return 1
    print(f"http engine: {_ENGINE} · competitors: {len(COMPETITORS)}")
    started = time.time()
    allitems = []
    for domain in COMPETITORS:
        if time.time() - started > FETCH_BUDGET:
            print(f"  ! budget hit; skipping {domain}", file=sys.stderr); continue
        try:
            allitems += watch_competitor(domain)
        except Exception as e:
            print(f"  ! {domain} crashed: {e}", file=sys.stderr)
    if not allitems:
        print("no competitor signals this run"); return 0
    res = deliver(allitems)
    print(f"delivered: {res}  (harvested {len(allitems)} signals)")
    return 0 if (res["first_seen"] + res["changed"] + res["unchanged"]) > 0 else 1


if __name__ == "__main__":
    sys.exit(main())
