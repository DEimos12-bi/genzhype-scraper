#!/usr/bin/env python3
"""GenZHype | RECEIPTS WORKER (P1). Runs on GitHub Actions (free, datacenter-safe).

Pulls jobs from the site's queue, turns each term's viral source post into a
receipt IMAGE, and posts it back to the token-gated ingest:
  - X/Twitter -> FxTwitter public API (no login, no key) -> rendered post-card PNG
  - TikTok    -> official oEmbed thumbnail (the real video cover)

NO logged-in accounts are used anywhere (brand-account safety rule).
Env: SITE_BASE (https://genzhype.com), INGEST_TOKEN.
"""
import io
import os
import re
import subprocess
import sys
import tempfile
import textwrap

import requests
from PIL import Image, ImageDraw, ImageFont

SITE = os.environ.get("SITE_BASE", "https://genzhype.com").rstrip("/")
TOKEN = os.environ["INGEST_TOKEN"]
UA = {"User-Agent": "GenZHypeReceipts/1.0 (+https://genzhype.com)"}
# some media APIs (tikwm) reject non-browser agents; use this for those
BROWSER_UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"}

# ---------------------------------------------------------------- post card
INK, PAPER, GRAY, RED = (26, 24, 20), (251, 250, 248), (107, 101, 89), (199, 31, 18)


def _font(size, bold=False):
    for p in ("/usr/share/fonts/truetype/dejavu/DejaVuSans%s.ttf" % ("-Bold" if bold else ""),):
        if os.path.exists(p):
            return ImageFont.truetype(p, size)
    return ImageFont.load_default()


def render_tweet_card(data):
    """Render a clean post-card from FxTwitter data (our pixels, real receipt)."""
    text = data.get("text", "")[:600]
    author = data.get("author", {}) or {}
    name = author.get("name", "Unknown")[:40]
    handle = author.get("screen_name", "")[:30]
    likes = data.get("likes", 0)
    date = (data.get("created_at", "") or "")[:16]
    photo = None
    media = (data.get("media", {}) or {}).get("photos") or []
    if media:
        try:
            r = requests.get(media[0]["url"], headers=UA, timeout=30)
            if r.ok:
                photo = Image.open(io.BytesIO(r.content)).convert("RGB")
        except Exception:
            photo = None

    W, PAD = 1000, 48
    f_name, f_handle, f_text, f_meta = _font(34, True), _font(28), _font(34), _font(26)
    lines = []
    for para in text.split("\n"):
        lines += textwrap.wrap(para, width=52) or [""]
    text_h = len(lines) * 46
    img_h = 0
    if photo:
        ratio = min(1.0, (W - 2 * PAD) / photo.width)
        photo = photo.resize((int(photo.width * ratio), int(photo.height * ratio)))
        img_h = min(photo.height, 540) + 24
    H = PAD + 56 + 24 + text_h + 20 + img_h + 56 + PAD

    card = Image.new("RGB", (W, H), PAPER)
    d = ImageDraw.Draw(card)
    d.rectangle([0, 0, W, 8], fill=RED)                       # brand rule
    y = PAD
    d.text((PAD, y), name, font=f_name, fill=INK)
    d.text((PAD, y + 42), "@" + handle, font=f_handle, fill=GRAY)
    y += 56 + 24
    for ln in lines:
        d.text((PAD, y), ln, font=f_text, fill=INK)
        y += 46
    y += 20
    if photo:
        card.paste(photo.crop((0, 0, photo.width, min(photo.height, 540))), (PAD, y))
        y += img_h
    d.text((PAD, y), f"{date}  |  {likes:,} likes  |  via X", font=f_meta, fill=GRAY)
    d.line([PAD, H - PAD + 16, W - PAD, H - PAD + 16], fill=(229, 225, 216), width=2)
    out = io.BytesIO()
    card.save(out, "PNG")
    return out.getvalue()


# ---------------------------------------------------------------- platforms
def screenshot_tweet(url):
    """PRIMARY: tweetcapture (existing tool, github.com/xacnio/tweetcapture) -
    a screenshot of the REAL tweet. X hardens its walls every few weeks, so
    failures here are expected sometimes; the data-rendered card catches them."""
    try:
        out = os.path.join(tempfile.mkdtemp(), "tweet.png")
        r = subprocess.run(["tweetcapture", "-o", out, url],
                           capture_output=True, timeout=90)
        if r.returncode == 0 and os.path.exists(out) and os.path.getsize(out) > 10000:
            with open(out, "rb") as f:
                return f.read()
    except Exception as e:
        print(f"  tweetcapture failed: {e}")
    return None


def fetch_tweet(url):
    m = re.search(r"/status/(\d+)", url)
    if not m:
        return None, None
    r = requests.get(f"https://api.fxtwitter.com/i/status/{m.group(1)}", headers=UA, timeout=30)
    if not r.ok:
        return None, None
    tw = (r.json() or {}).get("tweet") or {}
    if not tw.get("text") and not tw.get("media"):
        return None, None
    author = (tw.get("author") or {}).get("screen_name", "")
    credit = f"Post by @{author} on X"
    shot = screenshot_tweet(url)               # existing tool first (real look)
    if shot:
        return shot, credit
    return render_tweet_card(tw), credit       # fallback: card from FxTwitter data


def render_tiktok_card(cover_bytes, author, title, likes):
    """HD TikTok cover + brand caption strip (author, caption, like count) -
    a real receipt card matching the tweet-card style."""
    cover = Image.open(io.BytesIO(cover_bytes)).convert("RGB")
    W = 1000
    cover = cover.resize((W, int(cover.height * W / cover.width)))
    cover_h = min(cover.height, 760)
    cover = cover.crop((0, 0, W, cover_h))
    f_name, f_cap = _font(30, True), _font(28)
    cap_lines = textwrap.wrap(title, width=58)[:3] if title else []
    H = 8 + cover_h + 26 + 42 + len(cap_lines) * 40 + 50
    card = Image.new("RGB", (W, H), PAPER)
    d = ImageDraw.Draw(card)
    d.rectangle([0, 0, W, 8], fill=RED)
    card.paste(cover, (0, 8))
    y = 8 + cover_h + 22
    d.text((40, y), f"@{author}", font=f_name, fill=INK)
    y += 44
    for ln in cap_lines:
        d.text((40, y), ln, font=f_cap, fill=INK)
        y += 40
    d.text((40, y + 4), f"♥ {likes:,}  ·  via TikTok", font=f_cap, fill=GRAY)
    out = io.BytesIO()
    card.save(out, "PNG")
    return out.getvalue()


def fetch_tiktok(url):
    """PRIMARY: tikwm.com (free, no auth) -> HD cover + author + caption + likes
    rendered as a receipt card. FALLBACK: official oEmbed thumbnail."""
    try:
        j = requests.get("https://www.tikwm.com/api/", params={"url": url},
                         headers=BROWSER_UA, timeout=30).json()
        d = (j or {}).get("data") or {}
        cover_url = d.get("origin_cover") or d.get("cover")
        if cover_url:
            img = requests.get(cover_url, headers=BROWSER_UA, timeout=30)
            if img.ok and len(img.content) > 5000:
                author = (d.get("author") or {}).get("unique_id", "")
                likes = int(d.get("digg_count", 0) or 0)
                card = render_tiktok_card(img.content, author, d.get("title", ""), likes)
                return card, f"Video by @{author} · {likes:,} likes on TikTok"
    except Exception as e:
        print(f"  tikwm failed: {e}")
    # fallback: official oEmbed thumbnail
    r = requests.get("https://www.tiktok.com/oembed", params={"url": url}, headers=UA, timeout=30)
    if not r.ok:
        return None, None
    j = r.json() or {}
    thumb = j.get("thumbnail_url")
    if not thumb:
        return None, None
    img = requests.get(thumb, headers=UA, timeout=30)
    if not img.ok or len(img.content) < 5000:
        return None, None
    return img.content, f"Video by {j.get('author_name', '')} on TikTok"


# ---------------------------------------------------------------- main loop
def main():
    jobs = requests.get(f"{SITE}/api/imgjobs.php", params={"token": TOKEN}, headers=UA, timeout=30).json().get("jobs", [])
    print(f"jobs: {len(jobs)}")
    done = 0
    for j in jobs:
        try:
            if j["platform"] == "twitter":
                png, credit = fetch_tweet(j["url"])
            elif j["platform"] == "tiktok":
                png, credit = fetch_tiktok(j["url"])
            else:
                continue
            if not png:
                print(f"  {j['slug']}: no media ({j['platform']})")
                continue
            r = requests.post(f"{SITE}/api/imgingest.php",
                              data={"token": TOKEN, "slug": j["slug"], "platform": j["platform"],
                                    "source_url": j["url"], "credit": credit or ""},
                              files={"file": (j["slug"] + ".png", png, "image/png")}, timeout=60)
            print(f"  {j['slug']}: HTTP {r.status_code} {r.text[:80]}")
            if r.ok:
                done += 1
        except Exception as e:
            print(f"  {j.get('slug', '?')}: ERROR {e}")
    print(f"done: {done}/{len(jobs)}")


if __name__ == "__main__":
    sys.exit(main())
