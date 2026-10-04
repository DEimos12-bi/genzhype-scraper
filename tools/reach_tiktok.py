#!/usr/bin/env python3
"""TikTok Creative Center ear — probe + harvest (2026-08-30).

TikTok's Creative Center trend pages are browsable logged-out in a real
browser; the raw API answered 40101 keyless from our server, and the known
scraper (lofe-w) uses login cookies. UNSETTLED: whether a GitHub runner with
browser-TLS impersonation (curl_cffi, the trick that fixed our video scraper
delivery) passes anonymously. This tool settles it by measurement:

  A. the creative_radar_api hashtag list, impersonated, no cookies
  B. the public trends PAGE, impersonated — hashtags mined from embedded JSON
  C. same as A but with TT_CC_COOKIES from secrets, if the owner supplied them

Output drop/tiktok-trends.json: {"at", "method", "status": {...}, "hashtags":
[{name, rank, publish_cnt?, views?}]}. Empty hashtags + status codes = the
probe's honest answer; the server ingest survives [] like every other channel.
"""
import json
import os
import pathlib
import re
import sys
import time

try:
    from curl_cffi import requests as creq
except Exception as e:
    print(f"curl_cffi missing: {e}", flush=True)
    creq = None

CC_PAGE = "https://ads.tiktok.com/business/creativecenter/inspiration/popular/hashtag/pc/en"
CC_API = ("https://ads.tiktok.com/creative_radar_api/v1/popular_trend/hashtag/list"
          "?period=7&page=1&limit=20&country_code=US&sort_by=popular")


def api_try(cookies=None):
    r = creq.get(CC_API, impersonate="chrome", timeout=25,
                 headers={"referer": CC_PAGE, "accept": "application/json"},
                 cookies=cookies or {})
    tags = []
    try:
        j = r.json()
        for i, h in enumerate((j.get("data") or {}).get("list") or []):
            tags.append({"name": h.get("hashtag_name", ""), "rank": i + 1,
                         "publish_cnt": h.get("publish_cnt"), "views": h.get("video_views")})
    except Exception:
        pass
    return r.status_code, (r.text or "")[:160], tags


def page_try():
    r = creq.get(CC_PAGE, impersonate="chrome", timeout=25)
    body = r.text or ""
    tags = []
    # hashtag names in embedded state JSON: "hashtag_name":"xyz"
    for i, name in enumerate(dict.fromkeys(re.findall(r'"hashtag_name"\s*:\s*"([^"]{2,40})"', body))):
        tags.append({"name": name, "rank": i + 1})
    return r.status_code, len(body), tags


def browser_try():
    """D. A real browser (owner 2026-10-04: "try the real-browser route"). TikTok signs the trend API inside its own
    page, so plain HTTP gets "no permission". Chromium opens the public trend page like a visitor, logged out, and we
    read the list the page itself loads. No login, no cookies; it can break when TikTok changes the page."""
    from playwright.sync_api import sync_playwright
    tags, seen, hits = [], set(), [0]
    url = CC_PAGE + "?countryCode=US&period=7"
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True, args=["--no-sandbox"])
        ctx = b.new_context(locale="en-US", viewport={"width": 1366, "height": 900},
                            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36")
        page = ctx.new_page()

        def on_resp(r):
            if "popular_trend/hashtag/list" not in r.url:
                return
            hits[0] += 1
            try:
                j = r.json()
            except Exception:
                return
            for h in ((j.get("data") or {}).get("list") or []):
                name = (h.get("hashtag_name") or "").strip()
                if name and name not in seen:
                    seen.add(name)
                    tags.append({"name": name, "rank": len(tags) + 1,
                                 "publish_cnt": h.get("publish_cnt"), "views": h.get("video_views")})

        page.on("response", on_resp)
        status = "?"
        try:
            resp = page.goto(url, wait_until="domcontentloaded", timeout=60000)
            status = str(resp.status if resp else "no response")
            page.wait_for_timeout(9000)
            for _ in range(3):                      # the list loads more as the page scrolls
                page.mouse.wheel(0, 2500)
                page.wait_for_timeout(2500)
            if not tags:                            # nothing from the page's own API call: read what it rendered
                body = page.content()
                for name in dict.fromkeys(re.findall(r'"hashtag_name"\s*:\s*"([^"]{2,40})"', body)):
                    tags.append({"name": name, "rank": len(tags) + 1})
        finally:
            b.close()
    return status, hits[0], tags

def main():
    out_path = sys.argv[1]
    out = {"at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
           "method": None, "status": {}, "hashtags": []}
    if creq is None:
        out["status"]["error"] = "curl_cffi unavailable"
    else:
        code, head, tags = api_try()
        out["status"]["api_anon"] = f"HTTP {code}: {head[:80]}"
        print(f"A api anon: {code}, tags={len(tags)}", flush=True)
        if tags:
            out["method"], out["hashtags"] = "api_anon", tags

        if not out["hashtags"]:
            code, blen, tags = page_try()
            out["status"]["page_anon"] = f"HTTP {code}, {blen} bytes"
            print(f"B page anon: {code}, {blen} bytes, tags={len(tags)}", flush=True)
            if tags:
                out["method"], out["hashtags"] = "page_anon", tags

        raw = os.environ.get("TT_CC_COOKIES", "")
        if not out["hashtags"] and raw:
            cookies = {}
            for part in raw.split(";"):
                if "=" in part:
                    k, v = part.split("=", 1)
                    cookies[k.strip()] = v.strip()
            code, head, tags = api_try(cookies)
            out["status"]["api_cookies"] = f"HTTP {code}: {head[:80]}"
            print(f"C api cookies: {code}, tags={len(tags)}", flush=True)
            if tags:
                out["method"], out["hashtags"] = "api_cookies", tags

    if not out["hashtags"]:
        try:
            status, hits, tags = browser_try()
            out["status"]["browser"] = f"page HTTP {status}, {hits} list response(s), {len(tags)} tags"
            print(f"D browser: page {status}, list responses={hits}, tags={len(tags)}", flush=True)
            if tags:
                out["method"], out["hashtags"] = "browser", tags
        except Exception as e:
            out["status"]["browser"] = f"error: {type(e).__name__}: {str(e)[:120]}"
            print(f"D browser: {out['status']['browser']}", flush=True)

    pathlib.Path(out_path).write_text(json.dumps(out, ensure_ascii=False))
    print(f"tiktok ear: method={out['method']} hashtags={len(out['hashtags'])}", flush=True)


if __name__ == "__main__":
    main()
