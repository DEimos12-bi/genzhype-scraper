#!/usr/bin/env python3
"""
GenZHype | BACKLINK WATCHER (external, GitHub Actions — off-page arm of the Competitor Engine).
STAGE 1: domain AUTHORITY benchmark straight from the COMMON CRAWL public web graph (the same
link-graph Open PageRank was built on) — NO signup, NO API key, nothing to get shut off.

For every rival + us it looks up the domain's harmonic-centrality position in Common Crawl's
domain-ranks file and converts it to a 0-100 authority, then POSTs to /api/backlink_ingest.php.
ref_domains stays empty here (that's STAGE 2, the link-opportunity miner).

Env: BACKLINK_BASE, INGEST_TOKEN, COMPETITORS (csv, optional), CC_RELEASE (optional override).
No pip deps (stdlib + curl/gzip, present on the runner).
"""
import os, sys, json, math, re, subprocess

BASE  = (os.environ.get("BACKLINK_BASE", "https://genzhype.com")).rstrip("/")
TOKEN = os.environ.get("INGEST_TOKEN", "")
RELEASE = os.environ.get("CC_RELEASE", "cc-main-2026-apr-may-jun")
RANKS_URL = f"https://data.commoncrawl.org/projects/hyperlinkgraph/{RELEASE}/domain/{RELEASE}-domain-ranks.txt.gz"
SCAN_LINES = int(os.environ.get("CC_SCAN_LINES", "12000000"))   # top-N most-authoritative domains
RIVALS = [d.strip() for d in (os.environ.get("COMPETITORS") or
    "dexerto.com,distractify.com,knowyourmeme.com,thethings.com,popcrave.com,dailydot.com,thetab.com,screenrant.com").split(",") if d.strip()]
OURS = "genzhype.com"

def log(*a): print(*a, file=sys.stderr, flush=True)
def rev(d): return ".".join(reversed(d.split(".")))      # dexerto.com -> com.dexerto

def authority_from_pos(pos):
    # log scale over ~120M domains: pos 1 -> ~100, deep tail -> 0
    return round(max(0.0, min(100.0, 100 * (1 - math.log10(max(pos, 1)) / 8.1))), 1)

def fetch_authority(domains):
    want = {rev(d): d for d in domains}                  # reversed-host -> original domain
    out = {}
    cmd = f"curl -fsSL --max-time 600 {RANKS_URL} | gzip -dc | head -n {SCAN_LINES}"
    log(f"scanning Common Crawl ranks ({RELEASE}, top {SCAN_LINES:,})...")
    try:
        p = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, text=True, bufsize=1 << 20)
        for line in p.stdout:
            tab = line.rstrip("\n").split("\t")
            if len(tab) < 6 or tab[0].startswith("#"): continue
            host_rev = tab[4]                            # cols: harmonicc_pos,val,pr_pos,pr_val,HOST_REV,n_hosts
            if host_rev in want:
                try: hpos = int(tab[0])
                except Exception: continue
                out[want[host_rev]] = {"authority": authority_from_pos(hpos), "rank": hpos}
                if len(out) == len(want):
                    try: p.kill()
                    except Exception: pass
                    break
        try: p.wait(timeout=5)
        except Exception: pass
    except Exception as e:
        log("  common crawl scan failed:", e)
    return out

def main():
    if not TOKEN: log("missing INGEST_TOKEN"); return 1
    scores = fetch_authority(RIVALS + [OURS])
    log(f"authority found for {len(scores)}/{len(RIVALS)+1} domains")
    items = []
    for d in RIVALS:
        s = scores.get(d, {})
        items.append({"domain": d, "is_ours": 0, "authority": s.get("authority", 0.0), "rank": s.get("rank"), "ref_domains": []})
    s = scores.get(OURS, {})
    # our brand-new site won't be in the graph yet -> authority 0 (honest: no link authority)
    items.append({"domain": OURS, "is_ours": 1, "authority": s.get("authority", 0.0), "rank": s.get("rank"), "ref_domains": []})

    import urllib.request
    data = json.dumps({"token": TOKEN, "items": items}).encode()
    req = urllib.request.Request(BASE + "/api/backlink_ingest.php", data=data, headers={"Content-Type": "application/json"})
    try:
        log("delivered:", urllib.request.urlopen(req, timeout=60).read().decode()[:400])
    except Exception as e:
        log("deliver failed:", e); return 1
    return 0

if __name__ == "__main__":
    sys.exit(main())
