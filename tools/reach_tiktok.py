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

    pathlib.Path(out_path).write_text(json.dumps(out, ensure_ascii=False))
    print(f"tiktok ear: method={out['method']} hashtags={len(out['hashtags'])}", flush=True)


if __name__ == "__main__":
    main()
