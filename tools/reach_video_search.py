#!/usr/bin/env python3
"""Story-driven X harvest (REACH-POWERED VIDEO, 2026-08-05).

Reads the video-feed branch's feed.json (the maker's pending pages: title,
people, gravity per page), builds one or two X search queries per story
(people names + distinctive title keywords), runs `twitter search` and writes
per-page post candidates for the server ingest:

    {"pages": {"<page_id>": [{"id","author","likes","text"}, ...]}}

Flags are the ones verified from twitter-cli's OWN cli.py source (v2.2 study,
git-main install — PyPI 0.8.5 search is broken): -n / --min-likes / --json.
Output shape {ok, data:[{id,text,author:{screenName},metrics:{likes}}]} is
the same one reach_ingest.php already parses for the discovery harvest.

Floors (owner rules): --min-likes 50 keeps junk out; a candidate's text must
mention a story person or a distinctive title keyword (X keyword search
returns off-story noise otherwise); grave-gravity stories are skipped whole
(viral hot-takes have no place on a tragedy — r16 gravity law).

The REAL gate stays server-side: every id is verified against X's syndication
CDN at card-render time (post_cards_recovered, tombstone -> NO card).
"""
import json
import re
import subprocess
import sys

MAX_PAGES = 6         # feed ships at most 8 jobs; bridge feed at most 6
MAX_SEARCHES = 12     # burner-account courtesy cap per run
MAX_PER_PAGE = 8      # candidates kept per page (sorted by likes)

STOP = set("""a an and the this that these those of in on at to for from by
with over after before about into out up down as is are was were be been has
have had how what when where who why will would can could says said say calls
call called new his her their they them its it he she we you your video watch
update confirmed reportedly amid sparks against during""".split())


def keywords(title, people):
    """Distinctive title words: no stopwords, no fragments of people names."""
    people_blob = " ".join(people).lower()
    out = []
    for w in re.findall(r"[A-Za-z0-9']{3,}", title):
        lw = w.lower()
        if lw in STOP or lw in people_blob:
            continue
        if lw not in (o.lower() for o in out):
            out.append(w)
    return out


def x_search(query):
    """One twitter-cli search; [] on any failure (never raises)."""
    try:
        r = subprocess.run(
            ["twitter", "search", query, "-n", "15", "--min-likes", "50", "--json"],
            capture_output=True, text=True, timeout=120)
        data = json.loads(r.stdout or "{}")
        return data.get("data") or []
    except Exception as exc:  # tool exit, timeout, bad JSON — all non-fatal
        print(f"  search {query!r} failed: {exc}")
        return []


def main(feed_path, out_path):
    try:
        with open(feed_path, encoding="utf-8") as fh:
            feed = json.load(fh)
    except Exception as exc:
        print(f"feed unreadable ({exc}) — writing empty pages")
        feed = {}

    pages = {}
    searches = 0
    for post in (feed.get("posts") or [])[:MAX_PAGES]:
        pid = post.get("page_id")
        if not pid:
            continue
        if (post.get("gravity") or "standard") == "grave":
            print(f"  page {pid}: grave story, skipped")
            continue
        people = [p.get("name", "").strip()
                  for p in (post.get("people") or [])
                  if isinstance(p, dict) and p.get("name")]
        kws = keywords(post.get("title") or "", people)

        queries = []
        if people:
            queries.append(f'"{people[0]}" ' + " ".join(kws[:2]))
            if len(people) > 1 and kws:
                queries.append(f'"{people[1]}" {kws[0]}')
        elif kws:
            queries.append(" ".join(kws[:4]))

        needles = [p.lower() for p in people] + [k.lower() for k in kws]
        seen, cand = set(), []
        for q in queries[:2]:
            if searches >= MAX_SEARCHES:
                print("  search cap reached — remaining pages deferred")
                break
            searches += 1
            for tw in x_search(q.strip()):
                tid = str(tw.get("id") or "").strip()
                text = (tw.get("text") or "").strip()
                if not tid or not tid.isdigit() or not text or tid in seen:
                    continue
                if not any(n and n in text.lower() for n in needles):
                    continue  # off-story keyword noise
                seen.add(tid)
                cand.append({
                    "id": tid,
                    "author": str((tw.get("author") or {}).get("screenName") or ""),
                    "likes": int((tw.get("metrics") or {}).get("likes") or 0),
                    "text": text[:400],
                })
        cand.sort(key=lambda c: -c["likes"])
        pages[str(pid)] = cand[:MAX_PER_PAGE]
        print(f"  page {pid}: {len(cand)} candidate(s) from {len(queries)} search(es)")

    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump({"pages": pages}, fh, ensure_ascii=False)
    print(f"x-pages: {len(pages)} page(s), {searches} search(es) used")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit("usage: reach_video_search.py <feed.json> <out.json>")
    main(sys.argv[1], sys.argv[2])
