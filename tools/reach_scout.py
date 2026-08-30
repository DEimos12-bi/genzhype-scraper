#!/usr/bin/env python3
"""THE SCOUT — listening harvest (2026-08-30).

Broad-net collection of raw posts where new slang/memes are BORN, for the
server-side Scout (app/scout.php) to run burst detection + an AI usage screen
over. This tool only LISTENS and ships posts; it judges nothing.

Output: {"at": iso, "posts": [{platform, author, text, id?|permalink?,
created_utc?, likes|ups}]}

Channels (same burner accounts as the discovery harvest):
  - Reddit listening subs: where Gen Z talks to itself (not news subs)
  - X meta-searches: posts where people react to NEW words ("new slang",
    "why is everyone saying...") — emergence shows up as confusion first

Courtesy caps: 4 subs + 3 searches per run, 6-hourly. TikTok slot reserved
(needs owner Creative Center cookies — platform key "tiktok" when it lands).
"""
import json
import pathlib
import subprocess
import sys
import time

SUBS = ["teenagers", "GenZ", "memes", "OutOfTheLoop"]
X_QUERIES = ["new slang", "why is everyone saying", "what does it mean when someone says"]


def sh(cmd, timeout):
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return r.stdout
    except Exception as e:
        print(f"  {cmd[0]} failed: {e}", flush=True)
        return ""


def main():
    out_path = sys.argv[1]
    posts = []

    for sub in SUBS:
        raw = sh(["rdt", "sub", sub, "--limit", "25", "--json"], 120)
        n = 0
        try:
            j = json.loads(raw)
            for ch in ((j.get("data") or {}).get("data") or {}).get("children", []):
                d = ch.get("data") or {}
                text = ((d.get("title") or "") + " " + (d.get("selftext") or "")).strip()
                if not text or not d.get("author") or not d.get("permalink"):
                    continue
                posts.append({
                    "platform": "reddit",
                    "author": d["author"],
                    "text": text[:500],
                    "permalink": d["permalink"],
                    "created_utc": int(d.get("created_utc") or 0),
                    "ups": int(d.get("ups") or 0),
                })
                n += 1
        except Exception:
            pass
        print(f"  r/{sub}: {n} posts", flush=True)
        time.sleep(2)

    for q in X_QUERIES:
        raw = sh(["twitter", "search", q, "-n", "20", "--min-likes", "10", "--json"], 120)
        n = 0
        try:
            j = json.loads(raw)
            for tw in (j.get("data") or []):
                if not tw.get("id") or not tw.get("text"):
                    continue
                posts.append({
                    "platform": "x",
                    "author": (tw.get("author") or {}).get("screenName", ""),
                    "text": tw["text"][:500],
                    "id": str(tw["id"]),
                    "likes": (tw.get("metrics") or {}).get("likes", 0),
                })
                n += 1
        except Exception:
            pass
        print(f"  x '{q}': {n} posts", flush=True)
        time.sleep(3)

    out = {"at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "posts": posts}
    pathlib.Path(out_path).write_text(json.dumps(out, ensure_ascii=False))
    print(f"scout harvest: {len(posts)} posts -> {out_path}", flush=True)


if __name__ == "__main__":
    main()
