#!/usr/bin/env python3
"""Per-term usage harvest for the slang/meme/gaming citation gate (2026-08-29).

Reads the reach-feed branch's terms-wanted.json (terms the site needs real
usage evidence for), searches each on X (twitter-cli, quoted phrase) and
Reddit (rdt search), and writes one machine file for the server ingest:

    {"at": iso, "terms": {"<term>": {"x": [...], "reddit": [...]}}}

X rows keep the twitter-cli shape (id/text/author.screenName/metrics.likes) —
the tweet's DATE is derived server-side from the snowflake id, and every id is
re-verified against the syndication CDN by the gate (fail closed), so a junk
row here can never become a citation. Reddit rows are trimmed listing children
(title/selftext/author/created_utc/permalink).

Courtesy caps (burner accounts): 12 terms max, one search per platform per
term, low like-floor (real usage of a niche word rarely has 50 likes).
Every failure writes an empty list and moves on — the drop must always ship.
"""
import json
import pathlib
import subprocess
import sys
import time

MAX_TERMS = 12
X_PER_TERM = 10
X_MIN_LIKES = 5


def sh(cmd, timeout):
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return r.stdout
    except Exception as e:
        print(f"  {cmd[0]} failed: {e}", flush=True)
        return ""


def main():
    feed_path, out_path = sys.argv[1], sys.argv[2]
    try:
        feed = json.loads(pathlib.Path(feed_path).read_text())
    except Exception:
        feed = {}
    terms = [t.get("term", "").strip() for t in feed.get("terms", [])]
    terms = [t for t in terms if t][:MAX_TERMS]
    print(f"terms wanted: {len(terms)}", flush=True)

    out = {"at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "terms": {}}
    for term in terms:
        entry = {"x": [], "reddit": []}

        raw = sh(["twitter", "search", f'"{term}"', "-n", str(X_PER_TERM),
                  "--min-likes", str(X_MIN_LIKES), "--json"], 120)
        try:
            j = json.loads(raw)
            for tw in (j.get("data") or []):
                if tw.get("id") and tw.get("text"):
                    entry["x"].append({
                        "id": str(tw["id"]),
                        "text": tw["text"][:400],
                        "author": (tw.get("author") or {}).get("screenName", ""),
                        "likes": (tw.get("metrics") or {}).get("likes", 0),
                    })
        except Exception:
            pass

        raw = sh(["rdt", "search", term, "--limit", "10", "--json"], 120)
        try:
            j = json.loads(raw)
            for ch in ((j.get("data") or {}).get("data") or {}).get("children", []):
                d = ch.get("data") or {}
                if not d.get("permalink"):
                    continue
                entry["reddit"].append({
                    "title": (d.get("title") or "")[:300],
                    "text": (d.get("selftext") or "")[:400],
                    "author": d.get("author") or "",
                    "created_utc": int(d.get("created_utc") or 0),
                    "permalink": d.get("permalink"),
                    "ups": int(d.get("ups") or 0),
                })
        except Exception:
            pass

        out["terms"][term] = entry
        print(f"  {term}: x={len(entry['x'])} reddit={len(entry['reddit'])}", flush=True)
        time.sleep(3)   # pace the burners

    pathlib.Path(out_path).write_text(json.dumps(out, ensure_ascii=False))
    print(f"wrote {out_path}", flush=True)


if __name__ == "__main__":
    main()
