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
import datetime
import json
import re
import subprocess
import sys

MAX_PAGES = 6         # feed ships at most 8 jobs; bridge feed at most 6
MAX_SEARCHES = 12     # burner-account courtesy cap per run
MAX_PER_PAGE = 8      # candidates kept per page (sorted by likes)
SINCE_DAYS = 60       # current dramas only (run 7: a 2022 tweet ranked top)

STOP = set("""a an and the this that these those of in on at to for from by
with over after before about into out up down as is are was were be been has
have had how what when where who why will would can could says said say calls
call called new his her their they them its it he she we you your video watch
update confirmed reportedly amid sparks against during
influencer influencers streamer streamers creator creators youtuber tiktoker
backlash drama controversy apology apologizes apologized viral trend trending
internet online social media star celebrity fans slams facing faces accused
claims responds response explained explainer timeline story""".split())
# the second block is NICHE-GENERIC vocabulary (run 7 lesson): on a drama
# site every story title contains these, so as keywords they matched
# OFF-STORY tweets (plane-yoga page harvested Meghan Markle + OpenAI posts
# via "influencer backlash"). Distinctive words only — yoga, plane, zietz.


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


def anchor_tokens(name):
    """The strings that prove a tweet is about THIS person.

    r86: the full name alone is too strict. Real posts say "Kai" or tag
    "@KaiCenat"; the person's OWN post says neither, because their name sits
    in the author field. So an anchor is: the full name, or a distinctive
    surname (>=4 chars, not vocabulary), or the name with spaces removed
    (how handles are written). Acronym/handle-shaped "names" shorter than 4
    characters carry no identity and are dropped — page 244's "MTAAB"
    searched as a person and found nothing, twice.
    """
    n = re.sub(r"\s+", " ", (name or "")).strip().lower()
    if len(n.replace(" ", "")) < 4:
        return []
    toks = {n, n.replace(" ", ""), n.replace(" ", "_")}
    parts = [p for p in n.split(" ") if p]
    if len(parts) > 1:
        last = parts[-1]
        # >=5, not 4: a four-letter surname is ordinary English ("West"
        # matches "West Coast", "Khan" matches a film title) and a substring
        # match on it would drag off-story tweets in under the anchor rule.
        if len(last) >= 5 and last not in STOP:
            toks.add(last)
    return [t for t in toks if t]


def author_matches(tw, tokens):
    """True when the tweet was POSTED BY one of the story's people.

    r86: the anchor rule used to read only the text, so a story's central
    post — the one the person wrote themselves — was rejected every time
    (their name is the author, not the words). Authorship is the strongest
    identity evidence we can have, so it anchors on its own.
    """
    a = tw.get("author") or {}
    blob = " ".join([str(a.get("screenName") or ""), str(a.get("name") or "")]).lower()
    blob_tight = re.sub(r"[^a-z0-9]", "", blob)
    return any(t in blob or re.sub(r"[^a-z0-9]", "", t) in blob_tight for t in tokens)


def x_search(query):
    """One twitter-cli search; [] on any failure (never raises).

    Flags verified from twitter-cli's cli.py + search.py sources: --since /
    --lang / --exclude compose X advanced-search operators into the query.
    """
    since = (datetime.date.today() - datetime.timedelta(days=SINCE_DAYS)).isoformat()
    try:
        r = subprocess.run(
            ["twitter", "search", query, "-n", "15", "--min-likes", "50",
             "--since", since, "--lang", "en", "--exclude", "retweets", "--json"],
            capture_output=True, text=True, timeout=120)
        data = json.loads(r.stdout or "{}")
        hits = data.get("data") or []
        if not hits:  # empty is a finding, not an error — show WHY (ok:false?)
            print(f"  search {query!r}: 0 hits, raw={r.stdout[:160]!r}")
        return hits
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

        # r86: per-person anchor token sets (full name / surname / handle
        # spelling). A person whose name yields no usable token (acronym,
        # initials) is NOT an anchor — the story falls back to keywords
        # rather than searching a meaningless string. Page 244 burned two of
        # its three searches on the literal string "MTAAB".
        named = [p for p in people if anchor_tokens(p)]
        anchor_all = sorted({t for p in named for t in anchor_tokens(p)})

        # progressive relaxation (run #6 lesson: '"Rosa.Adventures" Influencer
        # Backlash' = handle AND both keywords in one tweet -> 0 hits; real
        # tweets describe the story, not the handle). Tightest first, stop
        # early once 3 candidates land.
        queries = []
        if named:
            queries.append(f'"{named[0]}" ' + " ".join(kws[:2]))
            queries.append(f'"{named[0]}"')
        if kws:
            queries.append(" ".join(kws[:4]))
        if not queries:
            print(f"  page {pid}: no searchable person or keyword, skipped")
            pages[str(pid)] = []
            continue
        kw_needles = [k.lower() for k in kws]
        # ANCHOR RULE (run 8 lesson, final form): "posts are about-the-story
        # by search construction" only holds when the person's name anchors
        # the match. Run 8 proved keyword co-occurrence cannot carry story
        # identity — "yoga"+"routine" matched wellness spam, "startup"
        # matched YC ads. So: a page WITH named people accepts ONLY tweets
        # naming one of them (nobody tweets the person -> ZERO posts, the
        # honest outcome); a no-people page needs 3 distinctive keywords.
        kw_need = min(3, max(1, len(kw_needles)))
        seen, cand = set(), []
        used = 0
        for q in queries[:3]:
            if len(cand) >= 3:
                break
            if searches >= MAX_SEARCHES:
                print("  search cap reached — remaining pages deferred")
                break
            searches += 1
            used += 1
            raw = x_search(q.strip())
            kept = drop_anchor = drop_kw = 0
            for tw in raw:
                tid = str(tw.get("id") or "").strip()
                text = (tw.get("text") or "").strip()
                if not tid or not tid.isdigit() or not text or tid in seen:
                    continue
                tl = text.lower()
                # r86: anchored by NAME IN TEXT *or* BY AUTHORSHIP. Before
                # this, a search returning 15 real hits could reject all 15
                # in silence — which is exactly what page 192 did.
                person_hit = (any(t in tl for t in anchor_all)
                              or author_matches(tw, anchor_all))
                kw_hits = sum(1 for k in kw_needles if k and k in tl)
                if anchor_all:
                    if not person_hit:
                        drop_anchor += 1
                        continue  # people-story: identity is the only anchor
                elif kw_hits < kw_need:
                    drop_kw += 1
                    continue  # off-story keyword noise
                kept += 1
                seen.add(tid)
                cand.append({
                    "id": tid,
                    "author": str((tw.get("author") or {}).get("screenName") or ""),
                    "likes": int((tw.get("metrics") or {}).get("likes") or 0),
                    "text": text[:400],
                })
            if raw:
                # r86: never let a search disappear. "15 hits, 0 kept" is the
                # finding that a filter is wrong; silence looks like "X had
                # nothing", which sent us hunting the renderer for a week.
                print(f"    -> {len(raw)} raw hit(s), {kept} kept "
                      f"(dropped: {drop_anchor} not-this-person, "
                      f"{drop_kw} off-topic)")
        cand.sort(key=lambda c: -c["likes"])
        pages[str(pid)] = cand[:MAX_PER_PAGE]
        print(f"  page {pid}: {len(cand)} candidate(s) from {used} search(es)")

    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump({"pages": pages}, fh, ensure_ascii=False)
    print(f"x-pages: {len(pages)} page(s), {searches} search(es) used")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit("usage: reach_video_search.py <feed.json> <out.json>")
    main(sys.argv[1], sys.argv[2])
