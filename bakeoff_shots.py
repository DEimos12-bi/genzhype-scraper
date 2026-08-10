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
    {"url": "https://trending.knowyourmeme.com/editorials/meme-review/"
            "the-weekly-meme-roundup-brooke-sullivan-sekiro-and-more",
     "kw": ["brooke", "sullivan", "meme"], "was": "we succeeded"},
    {"url": "https://www.yahoo.com/entertainment/articles/"
            "weekly-meme-roundup-brooke-sullivan-163000129.html",
     "kw": ["brooke", "sullivan", "meme"], "was": "we succeeded"},
    {"url": "https://www.linkedin.com/posts/martech-ai-newsletter_"
            "millions-of-people-remember-an-actress-activity-7346061117063139328-hqZP",
     "kw": ["brooke", "sullivan", "actress"], "was": "we skipped it: no headline block"},
    # r90b: round one used three HOMEPAGES (kotaku.com, gamerant.com, TOI's
    # front page). Our screenshotter refuses a homepage on purpose — there is
    # no single article headline to lock onto — so scoring those as misses
    # measured nothing. These are real article URLs taken from our own events
    # table: the exact pages the pipeline screenshots.
    {"url": "https://variety.com/2026/digital/news/mrbeast-beast-industries-sued-sexual-harassment-lawsuit/",
     "kw": ["mrbeast", "lawsuit", "beast"], "was": "live source, MrBeast story"},
    {"url": "https://apnews.com/article/mrbeast-lawsuit-harassment-beast-industries",
     "kw": ["mrbeast", "lawsuit", "beast"], "was": "live source, MrBeast story"},
    {"url": "https://timesofindia.indiatimes.com/technology/social/ethan-kleins-"
            "reported-1m-lawsuit-against-idubbbz-hit-a-wall-over-the-h3-snark-subreddit/"
            "articleshow/126354200.cms",
     "kw": ["ethan", "klein", "idubbbz", "lawsuit"], "was": "live source, Ethan Klein story"},
    {"url": "https://www.azfamily.com/2026/07/23/pigeons-dyed-blue-gender-reveal-spotted-salt-river/",
     "kw": ["pigeons", "dyed", "gender"], "was": "live source, dyed pigeons story"},
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
def run_ours():
    print("=== METHOD A: our screenshot_articles() ===", flush=True)
    try:
        import video_maker as vm
    except Exception as exc:                                   # noqa: BLE001
        print(f"  cannot import video_maker: {exc}")
        for c in CASES:
            report.append({"url": c["url"], "method": "ours", "ok": False,
                           "note": f"import failed: {exc}"})
        return
    for i, c in enumerate(CASES):
        t0 = time.time()
        try:
            got = vm.screenshot_articles({0: c["url"]}, page_id=900 + i,
                                         topic_kw=c["kw"])
        except Exception as exc:                               # noqa: BLE001
            got = {}
            print(f"  [{i}] threw: {exc}")
        ms = int((time.time() - t0) * 1000)
        p = got.get(0)
        size = shrink(p, f"{OUT}/A{i}.jpg") if p and os.path.isfile(p) else 0
        report.append({"url": c["url"], "was": c["was"], "method": "ours",
                       "ok": bool(size), "ms": ms, "file": f"A{i}.jpg" if size else None})
        print(f"  [{i}] {'OK  ' if size else 'MISS'} {ms:>6}ms  {c['url'][:64]}",
              flush=True)


# ---------------------------------------------------------------- METHOD B
def run_pixelshot():
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


if __name__ == "__main__":
    run_ours()
    run_pixelshot()
    with open(f"{OUT}/report.json", "w") as fh:
        json.dump(report, fh, indent=2)

    ours = [r for r in report if r["method"] == "ours" and r.get("ok")]
    pix = [r for r in report if r["method"] == "pixelshot" and r.get("ok")]
    print(f"\nSCORE  ours: {len(ours)}/{len(CASES)}   "
          f"pixelshot: {len(pix)}/{len(CASES)}")
