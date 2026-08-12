#!/usr/bin/env python3
"""TIKTOK RIVAL INTELLIGENCE (r112) — the eyes the engine never had.

WHY THIS EXISTS. The intelligence engine watches rival YOUTUBE channels and
writes rules from what wins there. But YouTube is the platform where we average
3 views a video; TikTok averages 127 and carries the whole account. So the
engine has been studying the wrong place, and the owner spotted the gap by
noticing a caption pattern on Instagram that no study of ours had ever
mentioned — because every study read what platforms SAY and never looked at
what creators DO.

WHY IT WORKS WHERE OUR SERVER FAILED. Four routes were measured and all four
were walls: the resolver's feed endpoint 403s, a rival's profile page comes
back as an empty shell (0 followers, no captions), oEmbed needs a URL we do
not have, and web search indexes almost no individual TikToks. The wall is
TikTok's request SIGNING — which a real browser does for free. TikTok-Api
(6.5k stars) drives Playwright to get those signatures, and this runner
already has Playwright installed for article screenshots.

WHAT IT COLLECTS. Per rival: recent posts with play/like/comment counts and
the full caption. That is the observation half of a study — what real accounts
in our niche actually do — as counts, not opinions.

FAILS QUIET. No ms_token, no browser, a rate limit, a changed API: it writes
what it got and exits 0. This is a learning input, never a blocker.
"""
import asyncio
import json
import os
import sys
import time

# Rivals worth learning from: drama/commentary accounts in our exact lane.
# Handles only — the library resolves them.
# r112b: read the env var but fall back when it is EMPTY, not merely absent.
# The workflow passes ${{ vars.TIKTOK_RIVALS }}, which resolves to an empty
# string when the variable does not exist — and os.environ.get() treats "" as
# a real value, so the first run observed nobody at all.
RIVALS = [s.strip() for s in (
    os.environ.get("TIKTOK_RIVALS")
    or "dramaalert,defnoodles,spillsesh,tiktokroom,popcrave,dexerto"
).split(",") if s.strip()]

PER_RIVAL = int(os.environ.get("TIKTOK_RIVAL_POSTS", "20"))
OUT = os.environ.get("TIKTOK_INTEL_OUT", "tiktok_intel.json")
MS_TOKEN = os.environ.get("ms_token") or os.environ.get("TIKTOK_MS_TOKEN")


def log(*a):
    print(*a, flush=True)


async def collect():
    try:
        from TikTokApi import TikTokApi
    except Exception as exc:                                   # noqa: BLE001
        log(f"TikTokApi not installed ({exc}); nothing collected")
        return []

    rows = []
    async with TikTokApi() as api:
        try:
            await api.create_sessions(
                ms_tokens=[MS_TOKEN] if MS_TOKEN else None,
                num_sessions=1, sleep_after=3,
                headless=(os.getenv("TIKTOK_HEADLESS", "0") == "1"),
                browser=os.getenv("TIKTOK_BROWSER", "chromium"))
        except Exception as exc:                               # noqa: BLE001
            log(f"session failed ({str(exc)[:120]}); nothing collected")
            return []

        for handle in RIVALS:
            got = 0
            try:
                user = api.user(username=handle)
                async for video in user.videos(count=PER_RIVAL):
                    d = video.as_dict or {}
                    stats = d.get("stats") or {}
                    rows.append({
                        "rival": handle,
                        "video_id": str(d.get("id") or ""),
                        "caption": (d.get("desc") or "")[:600],
                        "created": int(d.get("createTime") or 0),
                        "plays": int(stats.get("playCount") or 0),
                        "likes": int(stats.get("diggCount") or 0),
                        "comments": int(stats.get("commentCount") or 0),
                        "shares": int(stats.get("shareCount") or 0),
                        "duration": int(((d.get("video") or {}).get("duration")) or 0),
                    })
                    got += 1
            except Exception as exc:                           # noqa: BLE001
                log(f"  {handle}: {str(exc)[:110]}")
            log(f"  {handle}: {got} post(s)")
            time.sleep(2)                                      # be a guest
    return rows


def summarise(rows):
    """The observation half, as numbers. Deliberately simple and checkable:
    a rival's own median separates their hits from their normal, exactly as
    the YouTube arm does — a big account always out-plays a small one, so raw
    counts measure the account, not the choice."""
    out = {"collected": len(rows), "rivals": {}, "winners": []}
    by = {}
    for r in rows:
        by.setdefault(r["rival"], []).append(r)
    for handle, posts in by.items():
        plays = sorted(p["plays"] for p in posts if p["plays"] > 0)
        if not plays:
            continue
        med = plays[len(plays) // 2]
        hits = [p for p in posts if p["plays"] >= med * 2]
        caps = [len(p["caption"]) for p in posts if p["caption"]]
        hit_caps = [len(p["caption"]) for p in hits if p["caption"]]
        out["rivals"][handle] = {
            "posts": len(posts),
            "median_plays": med,
            "hits": len(hits),
            "caption_chars_median": sorted(caps)[len(caps) // 2] if caps else 0,
            "hit_caption_chars_median": (sorted(hit_caps)[len(hit_caps) // 2]
                                         if hit_caps else 0),
            "hashtags_median": (sorted(p["caption"].count("#") for p in posts
                                       )[len(posts) // 2] if posts else 0),
        }
        for p in sorted(hits, key=lambda x: -x["plays"])[:3]:
            out["winners"].append({
                "rival": handle, "plays": p["plays"],
                "vs_their_median": round(p["plays"] / med, 1) if med else 0,
                "caption_chars": len(p["caption"]),
                "caption": p["caption"][:220],
            })
    out["winners"].sort(key=lambda w: -w["vs_their_median"])
    return out


def main():
    log(f"rivals: {', '.join(RIVALS)}")
    log(f"ms_token: {'present' if MS_TOKEN else 'ABSENT (public endpoints only)'}")
    rows = asyncio.run(collect())
    report = summarise(rows)
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump({"rows": rows, "report": report}, fh, indent=2, ensure_ascii=False)
    log(f"\ncollected {len(rows)} post(s) from {len(report['rivals'])} rival(s)")
    for h, s in report["rivals"].items():
        log(f"  {h:<14} {s['posts']:>3} posts · median {s['median_plays']:>9,} plays"
            f" · captions {s['caption_chars_median']:>4} chars"
            f" · winners' captions {s['hit_caption_chars_median']:>4} chars")
    if report["winners"]:
        log("\ntop outliers (beat their OWN median):")
        for w in report["winners"][:5]:
            log(f"  {w['vs_their_median']}x  {w['plays']:>9,}  [{w['caption_chars']} chars]"
                f"  {w['caption'][:70]}")


if __name__ == "__main__":
    main()
