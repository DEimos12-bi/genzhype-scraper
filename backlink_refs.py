#!/usr/bin/env python3
"""
GenZHype | BACKLINK REFS miner — STAGE 2 of the off-page arm. Mines the COMMON CRAWL domain
web graph for the REFERRING DOMAINS of each rival (who links to them), then POSTs them so the
PHP brain builds the LINK-OPPORTUNITY list (domains linking to >=2 rivals but NOT us = exactly
where to go earn backlinks). Free, no key. Heavy (streams a ~14GB edge file) -> monthly.
"""
import os, sys, json, subprocess, urllib.request, urllib.parse

BASE  = (os.environ.get("BACKLINK_BASE", "https://genzhype.com")).rstrip("/")
TOKEN = os.environ.get("INGEST_TOKEN", "")
RELEASE = os.environ.get("CC_RELEASE", "cc-main-2026-apr-may-jun")
CAP = int(os.environ.get("REFS_PER_RIVAL", "2500"))
GB = f"https://data.commoncrawl.org/projects/hyperlinkgraph/{RELEASE}/domain/{RELEASE}-domain"
V_URL = f"{GB}-vertices.txt.gz"
E_URL = f"{GB}-edges.txt.gz"
RIVALS = [d.strip().lower() for d in (os.environ.get("COMPETITORS") or
    "dexerto.com,distractify.com,knowyourmeme.com,thethings.com,popcrave.com,dailydot.com,thetab.com,screenrant.com").split(",") if d.strip()]

def log(*a): print(*a, file=sys.stderr, flush=True)
def rev(d): return ".".join(reversed(d.split(".")))

def stream(url):
    return subprocess.Popen(f"curl -fsSL --max-time 3000 {url} | gzip -dc",
                            shell=True, stdout=subprocess.PIPE, text=True, bufsize=1 << 20)

def resolve_ids(want_rev):
    found = {}
    p = stream(V_URL)
    for line in p.stdout:
        t = line.split("\t")
        if len(t) >= 2 and t[1] in want_rev:
            found[t[1]] = t[0]
            if len(found) == len(want_rev): break
    try: p.kill()
    except Exception: pass
    return found

def mine_edges(rival_ids):
    idset = ",".join(rival_ids)
    awk = ("BEGIN{n=split(ids,a,\",\");for(i=1;i<=n;i++)T[a[i]]=1} ($2 in T){print $1\"\\t\"$2}")
    cmd = (f"curl -fsSL --max-time 3000 {E_URL} | gzip -dc | "
           f"awk -v ids='{idset}' '{awk}' | sort -u")
    log("streaming + filtering the ~14GB edge graph (this is the slow part)...")
    out = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, text=True)
    per_rival = {}
    for line in out.stdout.splitlines():
        f, r = line.split("\t")
        lst = per_rival.setdefault(r, [])
        if len(lst) < CAP: lst.append(f)
    return per_rival

def map_ids_to_domains(ids):
    want = set(ids); out = {}
    p = stream(V_URL)
    for line in p.stdout:
        t = line.split("\t")
        if len(t) >= 2 and t[0] in want:
            out[t[0]] = ".".join(reversed(t[1].split(".")))
            if len(out) == len(want): break
    try: p.kill()
    except Exception: pass
    return out

def main():
    if not TOKEN: log("missing INGEST_TOKEN"); return 1
    want_rev = {rev(d): d for d in RIVALS}
    log(f"resolving ids for {len(RIVALS)} rivals...")
    rev2id = resolve_ids(want_rev)
    id2rivaldomain = {rid: want_rev[rv] for rv, rid in rev2id.items()}
    if not rev2id: log("no rival ids resolved"); return 1
    log(f"resolved {len(rev2id)} rival ids")

    per_rival = mine_edges(list(rev2id.values()))
    need = set(f for lst in per_rival.values() for f in lst)
    log(f"collected {sum(len(v) for v in per_rival.values())} referring edges; mapping {len(need)} domains...")
    fid2dom = map_ids_to_domains(need)

    items = []
    for rid, froms in per_rival.items():
        dom = id2rivaldomain.get(rid)
        if not dom: continue
        refs = sorted({fid2dom[f] for f in froms if f in fid2dom})
        items.append({"domain": dom, "is_ours": 0, "ref_domains": refs})
        log(f"  {dom}: {len(refs)} referring domains")
    if not items: log("no referring data mined"); return 1

    data = json.dumps({"token": TOKEN, "items": items}).encode()
    req = urllib.request.Request(BASE + "/api/backlink_ingest.php", data=data, headers={"Content-Type": "application/json"})
    try:
        log("delivered:", urllib.request.urlopen(req, timeout=120).read().decode()[:400])
    except Exception as e:
        log("deliver failed:", e); return 1
    return 0

if __name__ == "__main__":
    sys.exit(main())
