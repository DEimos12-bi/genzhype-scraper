#!/usr/bin/env python3
"""CREATOR COUNTS (2026-09-25) - the owner's "numbers nobody else has": a story person's
follower count before and after the drama, on every big platform we can read.

YouTube is read by the server through the YouTube API (app/creator_stats.php). TikTok and
Instagram answer the server and plain requests with walls (tiktok_intel.py: TikTok-Api got
empty profiles on 2026-09-25), so this runner reads each public profile page in a real
browser: CloakBrowser (Chromium with fingerprint patches in its C++ source, free build 146)
driven through Scrapling, as CloakBrowser documents it. Facebook has no identity links yet.

LOGGED OUT, ALWAYS. No account of ours is used (the owner's accounts must never risk a ban),
no CAPTCHA is solved, nothing is posted. A login wall or an empty profile is recorded as
such and skipped. A few seconds between profiles.

Input: ACCOUNTS ("tiktok:handle instagram:handle ...", a manual test) or, when unset, the
server's list at ACCOUNTS_URL. Output: creator_counts.json, one row per account.
FAILS QUIET: exit 0 with whatever it read.
"""

import asyncio
import json
import os
import random
import re
import sys
import time
from urllib.request import Request, urlopen

IG_APP_ID = "936619743392459"   # the public web app id instagram.com sends with its own profile requests


def accounts() -> list:
    raw = os.environ.get("ACCOUNTS", "").strip()
    if raw:
        out = []
        for tok in raw.split():
            plat, _, handle = tok.partition(":")
            if plat in ("tiktok", "instagram") and handle:
                out.append({"platform": plat, "handle": handle.lstrip("@")})
        return out
    url = os.environ.get("ACCOUNTS_URL", "")
    if not url:
        return []
    try:
        return json.loads(urlopen(Request(url, headers={"User-Agent": "genzhype-runner"}), timeout=30).read())["accounts"]
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
    user, stats = info.get("user") or {}, info.get("stats") or {}
    if not user.get("uniqueId"):
        return {"error": "empty profile (TikTok answered without the user)"}
    return {"followers": num(stats.get("followerCount")), "following": num(stats.get("followingCount")),
            "posts": num(stats.get("videoCount")), "likes": num(stats.get("heartCount")),
            "name": user.get("nickname", ""), "bio": user.get("signature", ""), "verified": bool(user.get("verified"))}


def parse_instagram_api(body: str) -> dict:
    try:
        user = json.loads(body)["data"]["user"]
    except (KeyError, ValueError, TypeError):
        return {"error": "profile API answered without the user: " + body[:120].replace("\n", " ")}
    if not user:
        return {"error": "no such user"}
    return {"followers": num((user.get("edge_followed_by") or {}).get("count")),
            "following": num((user.get("edge_follow") or {}).get("count")),
            "posts": num((user.get("edge_owner_to_timeline_media") or {}).get("count")),
            "name": user.get("full_name", ""), "bio": user.get("biography", ""), "verified": bool(user.get("is_verified"))}


async def read_all(rows: list) -> list:
    from cloakbrowser import launch_async
    port = 9245
    browser = await launch_async(headless=False, args=[f"--remote-debugging-port={port}", "--remote-debugging-address=127.0.0.1"])
    ws = json.loads(urlopen(f"http://127.0.0.1:{port}/json/version").read())["webSocketDebuggerUrl"]
    from scrapling.fetchers import StealthyFetcher
    out = []
    try:
        for i, a in enumerate(rows):
            if i:
                await asyncio.sleep(random.uniform(4, 9))
            plat, handle = a["platform"], a["handle"]
            row = {"platform": plat, "handle": handle, "read_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
            try:
                if plat == "tiktok":
                    url = f"https://www.tiktok.com/@{handle}"
                    try:
                        row.update(parse_tiktok(page_html(await StealthyFetcher.async_fetch(url, cdp_url=ws, network_idle=True))), how="Scrapling over CloakBrowser")
                    except Exception as e:   # the same browser without Scrapling, so a Scrapling change cannot hide the page
                        ctx = browser.contexts[0] if browser.contexts else await browser.new_context()
                        pg = await ctx.new_page()
                        await pg.goto(url, wait_until="domcontentloaded", timeout=45000)
                        await asyncio.sleep(random.uniform(2, 4))
                        row.update(parse_tiktok(await pg.content()), how=f"CloakBrowser direct (Scrapling: {type(e).__name__}: {str(e)[:120]})")
                        await pg.close()
                else:
                    # the profile JSON instagram.com itself loads (exact counts), asked from inside
                    # a page of instagram.com so the request carries its cookies and origin
                    ctx = browser.contexts[0] if browser.contexts else await browser.new_context()
                    pg = await ctx.new_page()
                    await pg.goto(f"https://www.instagram.com/{handle}/", wait_until="domcontentloaded", timeout=45000)
                    await asyncio.sleep(random.uniform(2, 4))
                    body = await pg.evaluate("""async ([u, id]) => { const r = await fetch(u, {headers: {'X-IG-App-ID': id}, credentials: 'include'});
                                                 return r.status + ' ' + await r.text(); }""",
                                             [f"https://www.instagram.com/api/v1/users/web_profile_info/?username={handle}", IG_APP_ID])
                    status, _, text = body.partition(" ")
                    row.update(parse_instagram_api(text) if status == "200" else {"error": f"profile API HTTP {status}: {text[:120]}"}, how="profile API")
                    await pg.close()
            except Exception as e:
                row["error"] = f"{type(e).__name__}: {str(e)[:200]}"
            print(json.dumps(row, ensure_ascii=False))
            out.append(row)
    finally:
        await browser.close()
    return out


def main() -> int:
    rows = accounts()
    print(f"{len(rows)} account(s) to read")
    if not rows:
        return 0
    try:
        out = asyncio.run(read_all(rows))
    except Exception as e:
        print(f"browser did not start: {type(e).__name__}: {e}")
        out = []
    json.dump({"rows": out}, open("creator_counts.json", "w"), ensure_ascii=False, indent=1)
    ok = sum(1 for r in out if r.get("followers") is not None)
    print(f"read {ok} of {len(rows)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
