#!/usr/bin/env python3
"""SCREENSHOT BAKE-OFF (r90) — our article screenshotter vs PixelRAG's
`pixelshot`, on the exact URLs that have actually failed us.

WHY. The article screenshot is the first rung of the picture ladder and our
weakest step: on the last real render it attempted four and returned two —
one was rejected as ad-cluttered, one was skipped because our code could not
find a headline element in LinkedIn's markup. PixelRAG's claim is that
parsing markup is the wrong move and you should look at the rendered page
instead. This measures that claim on our own failures rather than trusting it.

WHAT IT PROVES AND WHAT IT DOES NOT. It compares CAPTURE only. Our method
crops to the headline and refuses when it cannot find one; pixelshot returns
the whole page cut into fixed-height tiles. So a fair reading is: did we get
a usable picture of the story at all, and if pixelshot did where we did not,
is the right tile obvious to pick? Nothing here changes the pipeline.
"""
import json
import os
import subprocess
import sys
import time

# The real cases, with the story keywords our cropper is given.
# Four are from the AI-actress render (two worked, one was ad-rejected, one
# was skipped); the rest are the sites named in earlier failures.
CASES = [
    # r173 (2026-09-13, owner: "it takes the screenshot when it's not finished
    # loading, or the ads are showing, or login for Google is showing"). The
    # exact sources behind the bad proof cards on pages 827/830/920/823/814/
    # 740/649 — shoot them with the code under test and look at every one.
    {"url": "https://dailyhive.com/edmonton/better-baker-edmonton-viral-video",
     "kw": ["baker", "edmonton", "appropriation"], "was": "827/830: ALLOW ADS wall over the article"},
    {"url": "https://in.ign.com/grand-theft-auto-vi/269189/gta-6-gameplay-and-map-appear-to-leak-online-group-reportedly-responsible-threatens-rockstar-over-al",
     "kw": ["gta 6", "gta vi", "leak"], "was": "920: lead photo not loaded (white hole)"},
    {"url": "https://timesofindia.indiatimes.com/tv/news/hindi/rapper-santy-sharmas-youtube-channel-suspended-cjp-remarks-row-triggers-online-buzz/articleshow/132518067.cms",
     "kw": ["santy", "sharma"], "was": "823: Watch widget still spinning"},
    {"url": "https://www.mid-day.com/entertainment/bollywood-news/article/santy-sharma-claims-youtube-deleted-his-channel-announces-cjp-related-press-conference-23641219",
     "kw": ["santy", "sharma"], "was": "823: sidebar ads + black unloaded box"},
    {"url": "https://www.dexerto.com/twitch/agent-00-offers-to-help-viral-1-viewer-twitch-streamer-after-incident-with-mom-3401116/",
     "kw": ["agent", "nitro", "twitch"], "was": "740: narrow column + white space + Google buttons"},
    {"url": "https://www.koreajoongangdaily.com/entertainment/kiss-of-life-controversy-reignites-debate-over-cultural-appropriation-in-k-pop/12306620",
     "kw": ["kiss of life", "appropriation"], "was": "827/830: audio-player widget + tiny text"},
    {"url": "https://www.moneycontrol.com/entertainment/rapper-santy-sharma-youtube-permanently-deleted-links-action-to-cjp-controversy-article-13978460.html",
     "kw": ["santy", "sharma"], "was": "823: Join/Follow/Google source buttons"},
    {"url": "https://www.dexerto.com/youtube/roblox-youtuber-meganplays-flooded-with-donations-after-best-friend-apologizes-for-affair-with-husband-3401026/",
     "kw": ["meganplays", "roblox"], "was": "649: narrow column + white space"},
]
# r173: a copy site republishing the same article must not become a SECOND
# proof card (page 740: tigerjek.com's copy of the Dexerto piece put the same
# headline and photo on screen a third time). Shot together, in one call.
PAIRS = [
    ("https://www.dexerto.com/twitch/agent-00-offers-to-help-viral-1-viewer-twitch-streamer-after-incident-with-mom-3401116/",
     "https://tigerjek.com/agent-00-offers-to-help-viral-1-viewer-twitch-streamer-after-incident-with-mom/",
     ["agent", "nitro", "twitch"]),
]

OUT = "bakeoff_out"
os.makedirs(OUT, exist_ok=True)
report = []


def shrink(src, dst, maxw=900):
    """Small JPEG so a human can flip through the results quickly."""
    from PIL import Image
    try:
        im = Image.open(src).convert("RGB")
        if im.width > maxw:
            im = im.resize((maxw, int(im.height * maxw / im.width)))
        # a very tall tile tells us nothing once shrunk; cap the height
        if im.height > 1600:
            im = im.crop((0, 0, im.width, 1600))
        im.save(dst, quality=82)
        return os.path.getsize(dst)
    except Exception as exc:                                   # noqa: BLE001
        print(f"  shrink failed: {exc}")
        return 0


# ---------------------------------------------------------------- METHOD A
def run_ours(prefix="A", view_w=None, dsf=None):
    print(f"=== METHOD {prefix}: our screenshot_articles() "
          f"(viewport {view_w or 'default'}, dsf {dsf or 'default'}) ===", flush=True)
    try:
        import video_maker as vm
        if view_w:                      # r173c: module globals are read per call
            vm.SHOT_VIEW_W = int(view_w)
        if dsf:
            vm.SHOT_DSF = float(dsf)
    except Exception as exc:                                   # noqa: BLE001
        print(f"  cannot import video_maker: {exc}")
        for c in CASES:
            report.append({"url": c["url"], "method": "ours", "ok": False,
                           "note": f"import failed: {exc}"})
        return
    for i, c in enumerate(CASES):
        t0 = time.time()
        try:
            got = vm.screenshot_articles({0: c["url"]}, page_id=(900 if prefix == "A" else 950) + i,
                                         topic_kw=c["kw"])
        except Exception as exc:                               # noqa: BLE001
            got = {}
            print(f"  [{i}] threw: {exc}")
        ms = int((time.time() - t0) * 1000)
        p = got.get(0)
        size = shrink(p, f"{OUT}/{prefix}{i}.jpg") if p and os.path.isfile(p) else 0
        report.append({"url": c["url"], "was": c["was"], "method": f"ours-{prefix}",
                       "ok": bool(size), "ms": ms, "file": f"{prefix}{i}.jpg" if size else None})
        print(f"  [{i}] {'OK  ' if size else 'MISS'} {ms:>6}ms  {c['url'][:64]}",
              flush=True)


# ---------------------------------------------------------------- METHOD B
def run_pixelshot():
    if os.environ.get("SKIP_PIXELSHOT"):
        print("=== METHOD B skipped (this round tests the bot-block fix only) ===")
        return
    print("=== METHOD B: pixelshot ===", flush=True)
    exe = None
    for cand in ("pixelshot", os.path.expanduser("~/.local/bin/pixelshot")):
        if subprocess.run(["bash", "-lc", f"command -v {cand}"],
                          capture_output=True).returncode == 0:
            exe = cand
            break
    if not exe:
        print("  pixelshot is not on PATH — install failed; that is a finding")
        for c in CASES:
            report.append({"url": c["url"], "method": "pixelshot", "ok": False,
                           "note": "not installed"})
        return
    for i, c in enumerate(CASES):
        d = f"{OUT}/tiles{i}"
        os.makedirs(d, exist_ok=True)
        t0 = time.time()
        r = subprocess.run([exe, c["url"], "--output", d],
                           capture_output=True, text=True, timeout=180)
        ms = int((time.time() - t0) * 1000)
        tiles = []
        for root, _dirs, files in os.walk(d):
            for f in sorted(files):
                if f.lower().endswith((".png", ".jpg", ".jpeg")):
                    tiles.append(os.path.join(root, f))
        kept = 0
        for j, t in enumerate(tiles[:3]):          # first 3 tiles = top of page
            if shrink(t, f"{OUT}/B{i}_{j}.jpg"):
                kept += 1
        report.append({"url": c["url"], "was": c["was"], "method": "pixelshot",
                       "ok": kept > 0, "ms": ms, "tiles": len(tiles),
                       "kept": kept,
                       "err": (r.stderr or "")[-200:] if not tiles else ""})
        print(f"  [{i}] {'OK  ' if kept else 'MISS'} {ms:>6}ms  "
              f"{len(tiles)} tile(s)  {c['url'][:56]}", flush=True)
        if not tiles and r.stderr:
            print(f"       stderr: {r.stderr.strip()[-200:]}")


def run_pairs():
    print("=== COPY-SITE PAIRS: same article on two domains ===", flush=True)
    try:
        import video_maker as vm
    except Exception as exc:                                   # noqa: BLE001
        print(f"  cannot import video_maker: {exc}")
        return
    for k, (a, b, kw) in enumerate(PAIRS):
        got = vm.screenshot_articles({0: a, 1: b}, page_id=990 + k, topic_kw=kw)
        same = bool(got.get(0)) and got.get(0) == got.get(1)
        for j in (0, 1):
            if got.get(j) and os.path.isfile(got[j]):
                shrink(got[j], f"{OUT}/P{k}_{j}.jpg")
        report.append({"pair": [a, b], "method": "pair", "files": [got.get(0), got.get(1)],
                       "copy_reuses_original": same})
        print(f"  [pair {k}] original={got.get(0)} copy={got.get(1)} reuses_original={same}",
              flush=True)


if __name__ == "__main__":
    run_ours()
    run_pairs()
    # r173c A/B: the same pages at a tablet width. Responsive layouts drop the
    # right rail and run the headline + photo edge to edge; 760 css px x 1.4211
    # device scale = exactly the 1080px card width, so nothing is rescaled.
    run_ours(prefix="N", view_w=760, dsf=1080 / 760)
    run_pixelshot()
    with open(f"{OUT}/report.json", "w") as fh:
        json.dump(report, fh, indent=2)

    ours = [r for r in report if r["method"] == "ours-A" and r.get("ok")]
    pix = [r for r in report if r["method"] == "pixelshot" and r.get("ok")]
    print(f"\nSCORE  ours: {len(ours)}/{len(CASES)}   "
          f"pixelshot: {len(pix)}/{len(CASES)}")
