#!/usr/bin/env python3
"""CREATOR COUNTS (2026-09-25) - the owner's "numbers nobody else has": a story person's
follower count before and after the drama, on every big platform we can read.

YouTube is read by the server through the YouTube API (app/creator_stats.php). TikTok and
Instagram wall off servers and plain requests (tiktok_intel.py: TikTok-Api got empty
profiles on 2026-09-25), so this runner reads each public page in a real browser:
CloakBrowser (Chromium with fingerprint patches in its C++ source, free build 146) driven
through Scrapling, as CloakBrowser documents it. Measured from this runner, logged out:
  TikTok     the profile page's data: exact counts in "statsV2" ("stats" is rounded)
  Instagram  the profile data API and the profile page ask for a login; the profile EMBED
             page (instagram.com/NAME/embed/, what sites use to show a profile) shows the
             count, rounded from 10,000 up
Facebook has no identity links yet.

LOGGED OUT, ALWAYS. No account of ours is used (the owner's accounts must never risk a ban),
no CAPTCHA is solved, nothing is posted anywhere. A wall is recorded and skipped.

Input: ACCOUNTS ("tiktok:handle instagram:handle ...", a manual test: nothing is sent back)
or the server's list (api/creator_counts.php), where the readings then go back.
Output: creator_counts.json. FAILS QUIET: exit 0 with whatever it read.
"""

import asyncio
import json
import os
import random
import re
import subprocess
import sys
import time
from html import unescape
from urllib.request import urlopen

API = "https://genzhype.com/api/creator_counts.php?token="


def site(payload=None, tries=3):
    """Talk to our site with curl and retries, as metrics_collector.py does (Hostinger's WAF
    sometimes drops runner IPs). The address holds the token, so it is never printed."""
    cmd = ["curl", "-s", "--fail", "--max-time", "300"]
    if payload is not None:
        cmd += ["-H", "Content-Type: application/json", "--data-binary", "@-"]
    body = json.dumps(payload).encode() if payload is not None else None
    last = ""
    for attempt in range(1, tries + 1):
        r = subprocess.run(cmd + [API + os.environ.get("INGEST_TOKEN", "")], input=body, capture_output=True, timeout=330)
        if r.returncode == 0:
            return json.loads(r.stdout)
        last = f"curl {r.returncode}"
        print(f"  {last} talking to the site (attempt {attempt}/{tries})")
        if attempt < tries:
            time.sleep(15 * attempt)
    raise RuntimeError(last)


def accounts() -> list:
    raw = os.environ.get("ACCOUNTS", "").strip()
    if raw:
        out = []
        for tok in raw.split():
            plat, _, handle = tok.partition(":")
            if plat in ("tiktok", "instagram") and handle:
                out.append({"platform": plat, "handle": handle.lstrip("@")})
        return out
    try:
        return site()["accounts"]
    except Exception as e:  # the server list is the only input; nothing to read without it
        print(f"accounts list unavailable: {e}")
        return []


def num(v):
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def page_html(resp) -> str:
    """The page's HTML from a Scrapling response (raw body when it has one)."""
    body = getattr(resp, "body", None)
    if isinstance(body, (bytes, bytearray)) and body:
        return body.decode("utf-8", "replace")
    if isinstance(body, str) and body:
        return body
    return str(getattr(resp, "html_content", "") or "")


def parse_tiktok(html: str) -> dict:
    m = re.search(r'<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)</script>', html, re.S)
    if not m:
        return {"error": "no profile data in the page (wall or changed page)"}
    try:
        info = json.loads(m.group(1))["__DEFAULT_SCOPE__"]["webapp.user-detail"]["userInfo"]
    except (KeyError, ValueError):
        return {"error": "profile data without user info"}
    user, stats, v2 = info.get("user") or {}, info.get("stats") or {}, info.get("statsV2") or {}
    if not user.get("uniqueId"):
        return {"error": "empty profile (TikTok answered without the user)"}
    # "stats" came back rounded on 2026-09-25 (Kai Cenat 25,400,000); "statsV2" holds the same counts as strings
    pick = lambda k: num(v2.get(k)) if num(v2.get(k)) is not None else num(stats.get(k))
    return {"followers": pick("followerCount"), "following": pick("followingCount"), "posts": pick("videoCount"),
            "likes": pick("heartCount"), "exact": num(v2.get("followerCount")) is not None,
            "name": user.get("nickname", ""), "bio": user.get("signature", ""), "verified": bool(user.get("verified"))}


def parse_instagram_embed(html: str) -> dict:
    """Instagram's profile embed page: an exact count in its data when present, else the
    header text ("1.5M followers", rounded from 10,000 up)."""
    for rx in (r'"edge_followed_by"\s*:\s*\{\s*"count"\s*:\s*(\d+)', r'"followers?_count"\s*:\s*(\d+)'):
        m = re.search(rx, html)
        if m:
            name = re.search(r'"full_name"\s*:\s*"((?:[^"\\]|\\.)*)"', html)
            return {"followers": int(m.group(1)), "exact": True, "name": json.loads('"' + name.group(1) + '"') if name else ""}
    text = re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", " ", html)))
    m = re.search(r"([\d.,]+)\s*([KMB]?)\s+followers", text, re.I)
    if m:
        n, unit = float(m.group(1).replace(",", "")), m.group(2).upper()
        return {"followers": int(round(n * {"": 1, "K": 1e3, "M": 1e6, "B": 1e9}[unit])), "exact": not unit}
    i = text.lower().find("follow")
    return {"error": "no counts in the embed page: " + (text[max(0, i - 80):i + 80] if i >= 0 else text[:160])}


async def read_all(rows: list) -> list:
    from cloakbrowser import launch_async
    from scrapling.fetchers import StealthyFetcher
    port = 9245
    browser = await launch_async(headless=False, args=[f"--remote-debugging-port={port}", "--remote-debugging-address=127.0.0.1"])
    ws = json.loads(urlopen(f"http://127.0.0.1:{port}/json/version").read())["webSocketDebuggerUrl"]
    out = []
    try:
        for i, a in enumerate(rows):
            if i:
                await asyncio.sleep(random.uniform(4, 9))
            plat, handle = a["platform"], a["handle"]
            row = {"platform": plat, "handle": handle, "read_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
            url = f"https://www.tiktok.com/@{handle}" if plat == "tiktok" else f"https://www.instagram.com/{handle}/embed/"
            try:
                html = page_html(await StealthyFetcher.async_fetch(url, cdp_url=ws, network_idle=True))
                row.update(parse_tiktok(html) if plat == "tiktok" else parse_instagram_embed(html))
            except Exception as e:
                row["error"] = f"{type(e).__name__}: {str(e)[:200]}"
            print(json.dumps(row, ensure_ascii=False))
            out.append(row)
    finally:
        await browser.close()
    return out


def main() -> int:
    manual = bool(os.environ.get("ACCOUNTS", "").strip())
    rows = accounts()
    print(f"{len(rows)} account(s) to read" + (" (manual test: nothing is sent back)" if manual else ""))
    if not rows:
        return 0
    try:
        out = asyncio.run(read_all(rows))
    except Exception as e:
        print(f"browser did not start: {type(e).__name__}: {e}")
        out = []
    json.dump({"rows": out}, open("creator_counts.json", "w"), ensure_ascii=False, indent=1)
    print(f"read {sum(1 for r in out if r.get('followers') is not None)} of {len(rows)}")
    if out and not manual:
        try:
            print("site: " + json.dumps(site({"rows": out})))
        except Exception as e:
            print(f"readings not delivered: {e}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
