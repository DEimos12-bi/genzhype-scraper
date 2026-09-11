#!/usr/bin/env python3
"""GenZHype | PROOFS WORKER (r155). Runs on GitHub Actions (.github/workflows/proofs.yml).

Owner request 2026-09-11: the YouTube, X, Reddit, TikTok and Instagram posts and
the articles a story uses as proof must show on the page as screenshots.

One run = one batch from the token-gated queue (GET /api/proofjobs.php). A job is
one source URL cited by a timeline event on a published story page. Every job
that is attempted ends with exactly ONE multipart POST to /api/proofingest.php:
the captured image, or failed=1 with a short reason. The image lands in
assets/proofs/pending/ on the server; the hourly server run safety-checks and
promotes it. No AI runs here.

  news       video_maker.screenshot_articles(): the hardened article crop the
             videos already use (Readability column, ad furniture removal,
             dead-page detection). It launches its own Chromium per call.
  x          https://platform.twitter.com/embed/Tweet.html?id=<id>   element shot
  reddit     https://embed.reddit.com<path>?embed=true&theme=light   element shot
  tiktok     https://www.tiktok.com/embed/v2/<video_id>               element shot
  instagram  https://www.instagram.com/p/<code>/embed/captioned/      element shot
  youtube    oEmbed (title, author) + i.ytimg.com hqdefault, kind=thumbnail

x/reddit/tiktok/instagram share ONE Playwright Chromium session for the run.
Playwright's sync API refuses to start while another sync session is running in
the same thread, and screenshot_articles() starts its own, so the jobs run
grouped: youtube (no browser), then the social group (one session, closed
after), then news. Queue order is kept inside each group.

NOT the source's fault, so NO POST and the URL keeps its attempts: Chromium or
video_maker cannot be loaded ("skipped", run exits 1 so it shows red), or the
wall-clock budget ran out before the job started ("not-started").

No logged-in accounts anywhere. The token is never printed.
Env: SITE_BASE (default https://genzhype.com), INGEST_TOKEN,
     PROOFS_BUDGET_S (default 840), PROOFS_MAX (default 25, server max 40).
"""
import hashlib
import html
import io
import json
import logging
import os
import re
import shutil
import sys
import tempfile
import time
import urllib.parse

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if REPO_ROOT not in sys.path:
    sys.path.insert(0, REPO_ROOT)          # video_maker.py lives at the repo root

import numpy as np  # noqa: E402
import requests  # noqa: E402
from PIL import Image  # noqa: E402


def _env_int(name, default, lo, hi):
    try:
        val = int(str(os.environ.get(name) or "").strip() or default)
    except ValueError:
        val = default
    return max(lo, min(hi, val))


SITE = (os.environ.get("SITE_BASE") or "").strip().rstrip("/") or "https://genzhype.com"
TOKEN = (os.environ.get("INGEST_TOKEN") or "").strip()
BUDGET_S = _env_int("PROOFS_BUDGET_S", 840, 30, 3600)
MAX_JOBS = _env_int("PROOFS_MAX", 25, 1, 40)
# r155 GIT BUS: the host firewall answers GitHub runner IPs with a 403 page (first two runs), so the job
# list arrives as a file (proof-feed branch) and captures leave as files (proof-drop branch) when set.
JOBS_FILE = (os.environ.get("PROOFS_JOBS_FILE") or "").strip()
DROP_DIR = (os.environ.get("PROOFS_DROP_DIR") or "").strip()

# The same Chrome identity video_maker's screenshot browser uses (its r91 note:
# a Chromium that claims to be Firefox is a sharper bot flag than no claim).
CHROME_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
             "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")
CHROME_CH_UA = '"Chromium";v="131", "Not_A Brand";v="24", "Google Chrome";v="131"'

VIEW_W, VIEW_H, DSF = 600, 1100, 2      # embeds render at their natural width
GOTO_MS, ELEMENT_MS = 20000, 12000      # per-page timeouts
MIN_W, MIN_H = 300, 150                 # a smaller capture is refused
BLANK_STD = 8.0                         # grayscale 64x80 std under this = blank
UPLOAD_MAX_W = 1000                     # the server keeps max 1000 wide anyway
UPLOAD_MAX_BYTES = 3_800_000            # the server refuses files over 4 MB
CREDIT_MAX, ERROR_MAX = 180, 250
SOCIAL = ("x", "reddit", "tiktok", "instagram")
GROUP_ORDER = {"youtube": 0, "x": 1, "reddit": 1, "tiktok": 1, "instagram": 1, "news": 2}
# Seconds a job may still need once started. A job is not started with less
# budget left, so the run ends near PROOFS_BUDGET_S instead of well past it.
RESERVE_S = {"youtube": 20, "x": 50, "reddit": 50, "tiktok": 60,
             "instagram": 50, "news": 60}

_DASHES = {cp: "-" for cp in (0x2010, 0x2011, 0x2012, 0x2013, 0x2014, 0x2015, 0x2212)}
_PHP_TRIM = " \t\n\r\0\x0b"             # PHP trim() default set; str.strip() trims more


# ----------------------------------------------------------------- text utils
def scrub(text):
    """The token never reaches a log line or a posted error string. requests
    puts the full URL (query string included) into its exception messages."""
    s = str(text or "")
    if TOKEN:
        s = s.replace(TOKEN, "***")
        quoted = urllib.parse.quote(TOKEN, safe="")
        if quoted != TOKEN:
            s = s.replace(quoted, "***")
    return re.sub(r"(token=)[^&\s'\"]+", r"\1***", s)


def short(text, limit):
    """One scrubbed line, dashes normalized, cut to limit chars."""
    s = scrub(text).translate(_DASHES)
    return re.sub(r"\s+", " ", s).strip()[:limit]


def clean_text(text, limit):
    s = html.unescape(str(text or "")).translate(_DASHES)
    return re.sub(r"\s+", " ", s).strip()[:limit]


def proof_key(url):
    """Mirror of app/proofs.php proof_key(): substr(sha1(trim(url)), 0, 16)."""
    return hashlib.sha1(str(url).strip(_PHP_TRIM).encode("utf-8")).hexdigest()[:16]


def fetchable(url):
    """The URL to load. Some stored URLs carry HTML-escaped '&amp;' in the query
    (seen on twitter.com links); the key and the posted url stay untouched."""
    s = str(url or "").strip()
    while "&amp;" in s:
        s = s.replace("&amp;", "&")
    return s


def _split(url):
    return urllib.parse.urlsplit(fetchable(url))


def host_of(url):
    return re.sub(r"^www\.", "", (_split(url).hostname or "").lower())


# ------------------------------------------------------------------ id parsers
_RESERVED_X = frozenset(("i", "intent", "home", "search", "share", "hashtag", "explore",
                         "notifications", "messages", "settings", "compose", "web"))


def parse_x(url):
    """{'id', 'handle'} from /<handle>/status/<id> (also /i/web/status/<id>)."""
    parts = [s for s in _split(url).path.split("/") if s]
    out = {"id": None, "handle": None}
    for i, seg in enumerate(parts):
        if seg.lower() in ("status", "statuses") and i + 1 < len(parts):
            if re.fullmatch(r"\d{1,25}", parts[i + 1]):
                out["id"] = parts[i + 1]
                if (i == 1 and re.fullmatch(r"[A-Za-z0-9_]{1,15}", parts[0])
                        and parts[0].lower() not in _RESERVED_X):
                    out["handle"] = parts[0]
            break
    return out


def parse_reddit(url):
    """{'sub', 'post', 'path', 'share'}. path = the embed path
    /r/<sub>/comments/<id>/[<slug>/[<comment>/]] (prefix kept as found, so
    /user/<name>/comments/<id>/ works too). share = /r/<sub>/s/<code> link."""
    parts = [s for s in _split(url).path.split("/") if s]
    low = [s.lower() for s in parts]
    out = {"sub": None, "post": None, "path": None, "share": False}
    if len(parts) >= 2 and low[0] == "r" and re.fullmatch(r"[A-Za-z0-9_]{2,32}", parts[1]):
        out["sub"] = parts[1]
        out["share"] = len(parts) >= 4 and low[2] == "s"
    if "comments" in low:
        i = low.index("comments")
        if i + 1 < len(parts) and re.fullmatch(r"[a-z0-9]{2,12}", low[i + 1]):
            out["post"] = low[i + 1]
            keep = parts[:i + 1] + [low[i + 1]]
            for seg in parts[i + 2:i + 4]:          # slug, then comment id
                if not re.fullmatch(r"[A-Za-z0-9_%-]{1,140}", seg):
                    break
                keep.append(seg)
            out["path"] = "/" + "/".join(keep) + "/"
    return out


def parse_tiktok(url):
    """{'id', 'user', 'short'}; short = vm./vt. or /t/<code> link without an id."""
    p = _split(url)
    host = (p.hostname or "").lower()
    out = {"id": None, "user": None, "short": False}
    m = (re.search(r"/(?:video|photo|v)/(\d{8,25})(?:[/.]|$)", p.path)
         or re.search(r"/embed(?:/v2)?/(\d{8,25})(?:/|$)", p.path))
    if m:
        out["id"] = m.group(1)
    else:
        q = urllib.parse.parse_qs(p.query)
        for name in ("item_id", "share_item_id", "aweme_id"):
            val = (q.get(name) or [""])[0]
            if re.fullmatch(r"\d{8,25}", val):
                out["id"] = val
                break
    u = re.search(r"/@([A-Za-z0-9._]{1,40})(?:/|$)", p.path)
    if u:
        out["user"] = u.group(1)
    if not out["id"] and (host.split(".")[0] in ("vm", "vt") or re.match(r"/t/[A-Za-z0-9]+", p.path)):
        out["short"] = True
    return out


def parse_instagram(url):
    """{'code'} from /p/<code>/, /reel/<code>/, /reels/<code>/, /tv/<code>/."""
    m = re.search(r"/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,40})(?:/|$)", _split(url).path)
    return {"code": m.group(1) if m else None}


_YT_ID = re.compile(r"[A-Za-z0-9_-]{11}")


def parse_youtube(url):
    """{'id'} from watch?v=<id>, youtu.be/<id>, /shorts/<id> (also /embed, /live, /v)."""
    p = _split(url)
    host = (p.hostname or "").lower()
    vid = None
    v = (urllib.parse.parse_qs(p.query).get("v") or [""])[0]
    if _YT_ID.fullmatch(v):
        vid = v
    elif host == "youtu.be" or host.endswith(".youtu.be"):
        seg = (p.path.strip("/").split("/") or [""])[0]
        vid = seg if _YT_ID.fullmatch(seg) else None
    else:
        m = re.match(r"/(?:shorts|embed|live|v|e)/([A-Za-z0-9_-]{11})(?:/|$)", p.path)
        vid = m.group(1) if m else None
    return {"id": vid}


# --------------------------------------------------------------------- credits
def build_credit(platform, job, info=None):
    """Caption credit shown under the proof. No em-dashes (dashes normalized)."""
    info = info or {}
    if platform == "news":
        pub = clean_text(job.get("publisher"), 120) or host_of(job.get("url")) or "the source"
        text = "Screenshot of %s" % pub
    elif platform == "x":
        text = "Post by @%s on X" % info["handle"] if info.get("handle") else "Post on X"
    elif platform == "reddit":
        text = "Post on r/%s" % info["sub"] if info.get("sub") else "Post on Reddit"
    elif platform == "tiktok":
        text = "Video by @%s on TikTok" % info["user"] if info.get("user") else "Video on TikTok"
    elif platform == "instagram":
        text = "Post on Instagram"
    elif platform == "youtube":
        author = clean_text(info.get("author"), 120)
        text = "YouTube video by %s" % author if author else "YouTube video"
    else:
        text = "Source screenshot"
    return clean_text(text, CREDIT_MAX)


# Generic words that would make video_maker's on-topic headline lock match any
# headline (including a 'trending now' module). Stored source titles are often
# the article's first sentence, photo credit included, not its headline.
_STOP = frozenset("""
a about above after again against all also am amid among an and any are around as at back
be because been before being below between both but by can could did do does doing done down
during each even ever every few first for from further get gets got had has have having he her
here hers him his how however i if in into is it its just last latest least less like made make
many may me more most much must my new next no nor not now of off on once one only or other our
out over own per please same say says said she should since so some still such than that the
their them then there these they this those though three through to today too two under until
up upon very via was way we week weeks well were what when where which while who whom why will
with within without would year years yet you your yesterday month months day days time times
news update updated breaking report reports reported reportedly according claims claim claimed
image images photo photos picture credit credits getty source sources published read watch
video videos clip clips post posts tweet tweets official exclusive live people fans viewers
users community internet online social media streamer streamers creator creators youtuber
youtubers influencer influencers game games gaming player players rumor rumors rumour rumours
january february march april june july august september october november december
jan feb mar apr jun jul aug sep sept oct nov dec monday tuesday wednesday thursday friday
saturday sunday gmt utc edt est bst pdt pst
dans avec pour sur les des une est qui que comme plus sans mais del las los una para por con
como sobre der die das und mit
""".split())


_RSQUO = chr(0x2019)                    # typographic apostrophe (U+2019)
# No "." inside a token: "internet.This" in a stored title is two words.
_TOKEN_RE = re.compile(r"[^\W_][\w'" + _RSQUO + r"&-]*")
_POSSESSIVE_RE = re.compile(r"['" + _RSQUO + r"]s$", re.I)


def topic_keywords(title, limit=6):
    """3-6 distinctive lowercase words from the job title for
    video_maker.screenshot_articles(topic_kw=...), which shoots only a headline
    containing one of them. Proper nouns first, then the longest other words.
    Fewer (even none, meaning no lock) when the title has nothing distinctive."""
    text = html.unescape(str(title or "")).translate(_DASHES)
    seen, proper, other = set(), [], []
    tokens = _TOKEN_RE.findall(text)
    if len(text) >= 250:
        tokens = tokens[:-1]            # stored titles stop at 255 chars: the last word may be cut
    for raw in tokens:
        word = _POSSESSIVE_RE.sub("", raw.strip("'&-" + _RSQUO))
        low = word.lower()
        if not low or low in seen or low in _STOP or not re.search(r"[^\W\d_]", low):
            continue
        is_proper = word[:1].isupper()
        if len(low) < (3 if is_proper else 4):
            continue
        seen.add(low)
        (proper if is_proper else other).append(low)
    other.sort(key=len, reverse=True)
    return (proper + other)[:limit]


# ------------------------------------------------------------------------ HTTP
class Resp:
    """The few response fields the worker reads, from either HTTP engine."""

    def __init__(self, status, content, url, headers):
        self.status = int(status or 0)
        self.content = content or b""
        self.url = str(url or "")
        self.headers = headers if headers is not None else {}

    @property
    def text(self):
        return self.content.decode("utf-8", "replace")

    def json(self):
        return json.loads(self.text)

    def header(self, name):
        try:
            return self.headers.get(name) or ""
        except Exception:  # noqa: BLE001
            return ""


def _looks_json(content):
    return (content or b"").lstrip()[:1] in (b"{", b"[")


def http_get(url, params=None, timeout=30, redirects=True):
    """GET: curl_cffi with Chrome TLS first (the site drops plain datacenter TLS
    at times), plain requests as the fallback. Raises only if both fail."""
    first = ""
    try:
        from curl_cffi import requests as cffi
        r = cffi.get(url, params=params, impersonate="chrome", timeout=timeout,
                     allow_redirects=redirects)
        return Resp(r.status_code, r.content, r.url, r.headers)
    except Exception as exc:  # noqa: BLE001 (ImportError included)
        first = str(exc)[:90]
    try:
        r = requests.get(url, params=params, timeout=timeout, allow_redirects=redirects,
                         headers={"User-Agent": CHROME_UA, "Accept-Language": "en-US,en;q=0.9"})
        return Resp(r.status_code, r.content, r.url, r.headers)
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(short("GET failed (curl_cffi: %s; requests: %s)"
                                 % (first, str(exc)[:90]), 240)) from None


def http_post(url, fields, file_part=None, timeout=90):
    """Multipart POST. curl_cffi first (it has no files= support; CurlMime is its
    multipart API), requests second. The second engine runs only when the first
    raised or answered a non-JSON body (an edge or WAF page: the PHP endpoint
    answers JSON only). file_part = (filename, bytes, mime)."""
    first = ""
    try:
        from curl_cffi import requests as cffi
        try:
            from curl_cffi import CurlMime
        except ImportError:
            from curl_cffi.curl import CurlMime
        mp = CurlMime()
        try:
            for name, value in fields.items():
                mp.addpart(name=name, data=str(value).encode("utf-8"))
            if file_part:
                fname, data, mime = file_part
                mp.addpart(name="file", content_type=mime, filename=fname, data=data)
            r = cffi.post(url, multipart=mp, impersonate="chrome", timeout=timeout)
            resp = Resp(r.status_code, r.content, r.url, r.headers)
        finally:
            mp.close()
        if _looks_json(resp.content):
            return resp
        first = "non-JSON answer, HTTP %d" % resp.status
    except Exception as exc:  # noqa: BLE001
        first = str(exc)[:90]
    try:
        parts = {name: (None, str(value)) for name, value in fields.items()}
        if file_part:
            parts["file"] = file_part
        r = requests.post(url, files=parts, timeout=timeout, headers={"User-Agent": CHROME_UA})
        return Resp(r.status_code, r.content, r.url, r.headers)
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(short("POST failed (curl_cffi: %s; requests: %s)"
                                 % (first, str(exc)[:90]), 240)) from None


def resolve_redirects(url, found, max_hops=5, timeout=15):
    """Follow Location headers one hop at a time (GET, no redirect following, no
    page body needed) until found(url) is truthy. Short links: vm.tiktok.com,
    reddit /s/ share links. Returns the resolved URL or None."""
    cur = fetchable(url)
    for _ in range(max_hops):
        try:
            r = http_get(cur, timeout=timeout, redirects=False)
        except Exception:  # noqa: BLE001
            return None
        loc = r.header("location")
        if r.status not in (301, 302, 303, 307, 308) or not loc:
            return None
        cur = urllib.parse.urljoin(cur, loc)
        if found(cur):
            return cur
    return None


# ---------------------------------------------------------------- image checks
def _flatten(im):
    """RGB over white: transparent pixels would otherwise read as black."""
    if im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info):
        rgba = im.convert("RGBA")
        bg = Image.new("RGB", rgba.size, (255, 255, 255))
        bg.paste(rgba, mask=rgba.getchannel("A"))
        return bg
    return im.convert("RGB")


def is_blank(im):
    """video_maker's near-blank test: grayscale 64x80, std under 8."""
    g = im.convert("L").resize((64, 80))
    return float(np.asarray(g).std()) < BLANK_STD


def check_capture(raw):
    """Refuse unreadable, small (< 300x150) or near-blank captures, then encode
    for upload (max 1000 wide, PNG, JPEG when too heavy).
    Returns (bytes, mime, ext, w, h, None) or (None, None, None, w, h, reason)."""
    try:
        im = Image.open(io.BytesIO(raw))
        im.load()
    except Exception as exc:  # noqa: BLE001
        return None, None, None, 0, 0, "capture unreadable (%s)" % short(exc, 60)
    w, h = im.size
    if w < MIN_W or h < MIN_H:
        return None, None, None, w, h, "capture too small (%dx%d)" % (w, h)
    im = _flatten(im)
    if is_blank(im):
        return None, None, None, w, h, "near-blank capture"
    if w > UPLOAD_MAX_W and round(h * UPLOAD_MAX_W / w) >= MIN_H:
        im = im.resize((UPLOAD_MAX_W, round(h * UPLOAD_MAX_W / w)), Image.Resampling.LANCZOS)
    buf = io.BytesIO()
    im.save(buf, "PNG")
    if buf.tell() <= UPLOAD_MAX_BYTES:
        return buf.getvalue(), "image/png", "png", im.width, im.height, None
    for quality in (90, 82, 72, 60):
        buf = io.BytesIO()
        im.save(buf, "JPEG", quality=quality)
        if buf.tell() <= UPLOAD_MAX_BYTES:
            break
    return buf.getvalue(), "image/jpeg", "jpg", im.width, im.height, None


# --------------------------------------------------------------------- youtube
def capture_youtube(info):
    """No browser: oEmbed proves the video is public and names the channel, the
    hqdefault still is the image. Returns (bytes, None) or (None, reason)."""
    watch = "https://www.youtube.com/watch?v=%s" % info["id"]
    oe = http_get("https://www.youtube.com/oembed", params={"url": watch, "format": "json"},
                  timeout=20)
    if oe.status != 200:
        return None, "YouTube oEmbed HTTP %d (private, removed or not embeddable)" % oe.status
    try:
        meta = oe.json()
    except ValueError:
        return None, "YouTube oEmbed answer is not JSON"
    info["author"] = str(meta.get("author_name") or "")
    info["title"] = str(meta.get("title") or "")
    img = http_get("https://i.ytimg.com/vi/%s/hqdefault.jpg" % info["id"], timeout=20)
    if img.status != 200 or len(img.content) < 1500:
        return None, "YouTube thumbnail HTTP %d" % img.status
    return img.content, None


# ---------------------------------------------------------------------- social
EMBED = {
    # pick: element candidates in order (first visible one >= 250x100 is shot).
    # bad: wording of a missing post. Tested against the page text when no
    # candidate renders, and against a picked element only when its text is
    # short, so a real post that merely quotes the words still gets shot.
    "x": {
        "pick": ["article", "[role='article']"],
        "bad": r"this (?:post|tweet) is unavailable|this (?:post|tweet) (?:was|has been) deleted"
               r"|doesn.t exist|account (?:is )?suspended|posts are protected|age-restricted"
               r"|something went wrong",
    },
    "reddit": {
        # measured 2026-09-11: embed.reddit.com renders the card as
        # div#t3_<id>-embed-wrapper > div#embed-container
        "pick": ["div[id$='-embed-wrapper']", "#embed-container"],
        "bad": r"blocked by network security|whoa there, pardner"
               r"|this post (?:was|has been) (?:removed|deleted)|sorry, this post"
               r"|page not found|community (?:is private|not found|has been banned)",
    },
    "tiktok": {
        # measured 2026-09-11: tiktok.com/embed/v2/<id> server-renders
        # [data-e2e=Player-index-Container] around the player card
        "pick": ["[data-e2e='Player-index-Container']",
                 "[data-e2e='Player-index-EmbedPlayerContainer']"],
        "bad": r"video (?:is )?(?:currently )?unavailable|this video isn.t available"
               r"|couldn.t find this|content is unavailable|private (?:video|account)",
    },
    "instagram": {
        "pick": [".EmbedFrame", ".Embed", "article", "main"],
        "bad": r"no longer available|isn.t available|link you followed may be broken"
               r"|log in to (?:see|continue)|login required",
    },
}

_STEALTH_JS = ("Object.defineProperty(navigator,'webdriver',{get:()=>undefined});"
               "Object.defineProperty(navigator,'languages',{get:()=>['en-US','en']});"
               "window.chrome = window.chrome || {runtime:{}};")

_SETTLE_JS = """async () => {
  const imgs = Array.from(document.images || []).filter(i => !i.complete);
  const loaded = Promise.all(imgs.map(i => new Promise(r => {
    i.addEventListener('load', r, {once: true});
    i.addEventListener('error', r, {once: true});
  })));
  const fonts = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
  await Promise.race([Promise.all([loaded, fonts]), new Promise(r => setTimeout(r, 4000))]);
  return imgs.length;
}"""


class SocialBrowser:
    """ONE Playwright Chromium for every x/reddit/tiktok/instagram job of the
    run: launched on the first such job, closed before the news group starts."""

    def __init__(self):
        self._pw = None
        self._browser = None
        self.ctx = None
        self.error = ""

    def start(self):
        if self.ctx is not None or self.error:
            return self.ctx
        try:
            from playwright.sync_api import sync_playwright
            self._pw = sync_playwright().start()
            self._browser = self._pw.chromium.launch(
                headless=True,
                args=["--disable-blink-features=AutomationControlled",
                      "--disable-features=IsolateOrigins,site-per-process"])
            self.ctx = self._browser.new_context(
                viewport={"width": VIEW_W, "height": VIEW_H},
                device_scale_factor=DSF, user_agent=CHROME_UA, locale="en-US",
                timezone_id="America/New_York",
                extra_http_headers={"Accept-Language": "en-US,en;q=0.9",
                                    "sec-ch-ua": CHROME_CH_UA,
                                    "sec-ch-ua-mobile": "?0",
                                    "sec-ch-ua-platform": '"Windows"'})
            self.ctx.add_init_script(_STEALTH_JS)
        except Exception as exc:  # noqa: BLE001
            self.error = "infra: browser unavailable (%s)" % short(exc, 120)
            self.close()
        return self.ctx

    @property
    def running(self):
        return self._pw is not None

    def close(self):
        for obj, method in ((self.ctx, "close"), (self._browser, "close"), (self._pw, "stop")):
            if obj is not None:
                try:
                    getattr(obj, method)()
                except Exception:  # noqa: BLE001
                    pass
        self.ctx = self._browser = self._pw = None


def social_target(platform, url):
    """(info, embed URL, None) or (info, None, reason) for one social job."""
    if platform == "x":
        info = parse_x(url)
        if not info["id"]:
            return info, None, "no status id in the URL"
        return info, ("https://platform.twitter.com/embed/Tweet.html?id=%s&theme=light&dnt=true"
                      % info["id"]), None
    if platform == "reddit":
        info = parse_reddit(url)
        if not info["post"] and info["share"]:
            final = resolve_redirects(url, lambda u: parse_reddit(u)["post"])
            if final:
                sub, info = info["sub"], parse_reddit(final)
                info["sub"] = info["sub"] or sub
        if not info["post"]:
            return info, None, "no post id in the URL"
        return info, "https://embed.reddit.com%s?embed=true&theme=light" % info["path"], None
    if platform == "tiktok":
        info = parse_tiktok(url)
        if not info["id"] and info["short"]:
            final = resolve_redirects(url, lambda u: parse_tiktok(u)["id"])
            if final:
                user, info = info["user"], parse_tiktok(final)
                info["user"] = info["user"] or user
            else:
                return info, None, "short link did not resolve to a video id"
        if not info["id"]:
            return info, None, "no video id in the URL"
        return info, "https://www.tiktok.com/embed/v2/%s" % info["id"], None
    if platform == "instagram":
        info = parse_instagram(url)
        if not info["code"]:
            return info, None, "no post code in the URL"
        return info, "https://www.instagram.com/p/%s/embed/captioned/" % info["code"], None
    return {}, None, "not a social platform"


def _page_text(page):
    try:
        return page.evaluate("() => ((document.body && document.body.innerText) || '').slice(0, 4000)")
    except Exception:  # noqa: BLE001
        return ""


def capture_embed(ctx, platform, target):
    """Element screenshot of the rendered post. Returns (png, None) or (None, reason)."""
    cfg = EMBED[platform]
    bad = re.compile(cfg["bad"], re.I)
    page = None
    try:
        page = ctx.new_page()
        page.set_default_timeout(ELEMENT_MS)
        resp = page.goto(target, wait_until="domcontentloaded", timeout=GOTO_MS)
        status = resp.status if resp is not None else 0
        if status >= 400:
            return None, "embed HTTP %d" % status
        try:
            page.wait_for_selector(", ".join(cfg["pick"]), state="visible", timeout=ELEMENT_MS)
        except Exception:  # noqa: BLE001
            m = bad.search(_page_text(page))
            if m:
                return None, "post unavailable (%s)" % m.group(0)
            return None, "post did not render within %ds" % (ELEMENT_MS // 1000)
        try:
            page.evaluate(_SETTLE_JS)            # images + fonts, capped at 4 s
        except Exception:  # noqa: BLE001
            pass
        page.wait_for_timeout(700)
        for sel in cfg["pick"]:
            loc = page.locator(sel).first
            try:
                if loc.count() == 0:
                    continue
                box = loc.bounding_box()
            except Exception:  # noqa: BLE001
                continue
            if not box or box["width"] < 250 or box["height"] < 100:
                continue
            try:
                txt = (loc.inner_text(timeout=3000) or "").strip()
            except Exception:  # noqa: BLE001
                txt = ""
            m = bad.search(txt)
            if m and len(txt) < 200:
                return None, "post unavailable (%s)" % m.group(0)
            return loc.screenshot(type="png", animations="disabled", timeout=ELEMENT_MS), None
        return None, "post element missing or too small"
    except Exception as exc:  # noqa: BLE001
        return None, "embed load failed: %s" % short(exc, 120)
    finally:
        if page is not None:
            try:
                page.close()
            except Exception:  # noqa: BLE001
                pass


# ------------------------------------------------------------------------ news
class _Collect(logging.Handler):
    """Keeps video_maker's log lines for one call: they name why a shot failed."""

    def __init__(self):
        super().__init__(logging.INFO)
        self.lines = []

    def emit(self, record):
        try:
            self.lines.append(record.getMessage())
        except Exception:  # noqa: BLE001
            pass


def news_reason(lines):
    """Short failure reason from video_maker.screenshot_articles log lines.
    'infra:' reasons are runner problems, not the source's."""
    text = "\n".join(lines)
    if "playwright not installed" in text:
        return "infra: playwright not installed"
    if "screenshot engine unavailable" in text:
        return "infra: browser unavailable"
    m = re.search(r"DEAD PAGE \(http (\d+), title (.{0,70}?)\)", text)
    if m:
        return short("dead page (HTTP %s, title %s)" % (m.group(1), m.group(2)), 200)
    if "no ON-TOPIC headline" in text:
        return "no on-topic headline on the page"
    if "no headline block found" in text:
        return "no headline found on the page"
    if "SHOT AD-GATE" in text:
        return "capture rejected: ad-cluttered"
    if "SHOT DEAD-ZONE" in text:
        return "capture rejected: blank player area"
    if "near-blank" in text:
        return "near-blank capture"
    m = re.search(r"screenshot failed \((.*?)\):", text)
    if m:
        return short("page load failed: %s" % m.group(1), 200)
    return "no screenshot produced"


def capture_news(vm, job):
    """video_maker.screenshot_articles for one article. Returns (png, None) or
    (None, reason)."""
    kw = topic_keywords(job.get("title") or "")
    col = _Collect()
    vm.log.addHandler(col)
    try:
        shots = vm.screenshot_articles({0: fetchable(job["url"])}, page_id=job["key"], topic_kw=kw)
    except Exception as exc:  # noqa: BLE001 (it never raises today; stay safe)
        shots = {}
        col.lines.append("screenshot failed (%s): -" % str(exc)[:80])
    finally:
        vm.log.removeHandler(col)
    path = (shots or {}).get(0)
    if path and os.path.isfile(path):
        try:
            with open(path, "rb") as fh:
                return fh.read(), None
        finally:
            try:
                os.remove(path)
            except OSError:
                pass
    return None, news_reason(col.lines)


def load_video_maker():
    """Import video_maker from the repo root. Import side effects: logging setup,
    constants, and its IPv4-only getaddrinfo filter (GitHub runners often have
    no IPv6 route while genzhype.com publishes AAAA), which requests then uses."""
    try:
        import video_maker as vm
    except Exception as exc:  # noqa: BLE001
        return None, short(exc, 160)
    vm.WORKDIR = tempfile.mkdtemp(prefix="proofs-shots-")
    if not os.environ.get("VIDEO_READABILITY_JS"):
        rjs = os.path.join(REPO_ROOT, "vendor", "Readability.js")
        if os.path.isfile(rjs):
            vm.READABILITY_JS = rjs
    return vm, ""


# ------------------------------------------------------------------------ main
def fetch_jobs():
    if JOBS_FILE:                               # r155 git bus
        with open(JOBS_FILE, encoding="utf-8") as fh:
            data = json.load(fh)
    else:
        r = http_get(SITE + "/api/proofjobs.php", params={"token": TOKEN, "limit": MAX_JOBS},
                     timeout=45)
        if r.status != 200:
            raise RuntimeError("job queue HTTP %d: %s" % (r.status, short(r.text, 120)))
        data = r.json()
    jobs, seen = [], set()
    for job in data.get("jobs") or []:
        if not isinstance(job, dict):
            continue
        key, url = str(job.get("key") or ""), str(job.get("url") or "")
        if not key or not url or key in seen:
            continue
        seen.add(key)
        jobs.append(job)
    return jobs[:MAX_JOBS], data.get("remaining")


def _line(key, platform, outcome, nbytes, secs, detail):
    print("%-16s  %-9s  %-11s  %8d B  %6.1f s  %s"
          % (key, platform or "?", outcome, nbytes, secs, short(detail, 200)), flush=True)


def run_job(job, vm, vm_err, browser, stats):
    t0 = time.monotonic()
    key, url = str(job["key"]), str(job["url"])
    platform = str(job.get("platform") or "")
    info, raw, reason, infra = {}, None, None, False
    kind = "thumbnail" if platform == "youtube" else "screenshot"
    try:
        if platform == "youtube":
            info = parse_youtube(url)
            if info["id"]:
                raw, reason = capture_youtube(info)
            else:
                reason = "no video id in the URL"
        elif platform in SOCIAL:
            info, target, reason = social_target(platform, url)
            if target:
                ctx = browser.start()
                if ctx is None:
                    infra, reason = True, browser.error
                else:
                    raw, reason = capture_embed(ctx, platform, target)
        elif platform == "news":
            if vm is None:
                infra, reason = True, "infra: video_maker import failed (%s)" % vm_err
            else:
                raw, reason = capture_news(vm, job)
                infra = bool(reason and reason.startswith("infra:"))
        else:
            reason = "unknown platform '%s'" % short(platform, 20)
    except Exception as exc:  # noqa: BLE001
        raw, reason = None, "worker error: %s" % short(exc, 150)

    if infra:
        stats["skipped"] += 1
        _line(key, platform, "skipped", 0, time.monotonic() - t0, reason)
        return

    upload, mime, ext, w, h = None, None, None, 0, 0
    if raw is not None:
        upload, mime, ext, w, h, bad = check_capture(raw)
        if bad:
            reason = bad
    warn = "" if proof_key(url) == key else "key mismatch; "
    if DROP_DIR:                                # r155 git bus: exactly one manifest per attempted job
        try:
            os.makedirs(DROP_DIR, exist_ok=True)
            if upload:
                credit = build_credit(platform, job, info)
                fname = key + "." + ext
                with open(os.path.join(DROP_DIR, fname), "wb") as fh:
                    fh.write(upload)
                manifest = {"key": key, "url": url, "platform": platform, "kind": kind,
                            "credit": credit, "file": fname}
                outcome, nbytes = "posted", len(upload)
                detail = "%s%dx%d %s | %s (to drop)" % (warn, w, h, kind, credit)
            else:
                error = short(reason or "capture failed", ERROR_MAX)
                manifest = {"key": key, "url": url, "failed": "1", "error": error}
                outcome, nbytes, detail = "failed", 0, warn + error
            with open(os.path.join(DROP_DIR, key + ".json"), "w", encoding="utf-8") as fh:
                json.dump(manifest, fh, ensure_ascii=False)
        except Exception as exc:  # noqa: BLE001
            outcome, nbytes, detail = "post-error", 0, warn + short(exc, 160)
        stats[outcome] += 1
        _line(key, platform, outcome, nbytes, time.monotonic() - t0, detail)
        return
    ingest = SITE + "/api/proofingest.php"
    try:                                        # exactly one POST per attempted job
        if upload:
            credit = build_credit(platform, job, info)
            resp = http_post(ingest, {"token": TOKEN, "key": key, "url": url,
                                      "platform": platform, "kind": kind, "credit": credit},
                             (key + "." + ext, upload, mime))
            ok = resp.status == 200 and _looks_json(resp.content) and bool(resp.json().get("ok"))
            outcome, nbytes = ("posted" if ok else "post-error"), len(upload)
            detail = ("%s%dx%d %s | %s" % (warn, w, h, kind, credit) if ok
                      else "%sHTTP %d %s" % (warn, resp.status, short(resp.text, 120)))
        else:
            error = short(reason or "capture failed", ERROR_MAX)
            resp = http_post(ingest, {"token": TOKEN, "key": key, "url": url,
                                      "failed": "1", "error": error})
            ok = resp.status == 200 and _looks_json(resp.content) and bool(resp.json().get("failed"))
            outcome, nbytes = ("failed" if ok else "post-error"), 0
            detail = (warn + error if ok
                      else "%sHTTP %d %s (reason was: %s)" % (warn, resp.status, short(resp.text, 80), error))
    except Exception as exc:  # noqa: BLE001
        outcome, nbytes = "post-error", 0
        detail = warn + short(exc, 160)
    stats[outcome] += 1
    _line(key, platform, outcome, nbytes, time.monotonic() - t0, detail)


def main():
    start = time.monotonic()
    if not TOKEN and not (JOBS_FILE and DROP_DIR):
        print("INGEST_TOKEN is not set; nothing to do", flush=True)
        return 2
    vm, vm_err = load_video_maker()             # before any HTTP (IPv4 filter)
    if vm is None:
        print("video_maker import failed: %s (news jobs will be skipped)" % vm_err, flush=True)
    try:
        jobs, remaining = fetch_jobs()
    except Exception as exc:  # noqa: BLE001
        print("job queue unavailable: %s" % short(exc, 200), flush=True)
        # r155: GitHub run logs need a login; an ::error:: line becomes a public annotation the server can read
        print("::error title=proofs worker::job queue unavailable: %s" % short(scrub(str(exc)), 200).replace("\n", " "), flush=True)
        return 1
    jobs.sort(key=lambda j: GROUP_ORDER.get(str(j.get("platform") or ""), 3))   # stable
    counts = {}
    for job in jobs:
        group = str(job.get("platform") or "?")
        group = "social" if group in SOCIAL else group
        counts[group] = counts.get(group, 0) + 1
    print("proofs worker: %d job(s), queue remaining %s, budget %ds, max %d | %s"
          % (len(jobs), remaining, BUDGET_S, MAX_JOBS,
             ", ".join("%s %d" % kv for kv in sorted(counts.items())) or "nothing to do"),
          flush=True)

    stats = {"posted": 0, "failed": 0, "post-error": 0, "skipped": 0, "not-started": 0}
    browser = SocialBrowser()
    try:
        for job in jobs:
            platform = str(job.get("platform") or "")
            if platform not in SOCIAL and browser.running:
                browser.close()                 # Playwright sync sessions cannot nest
            left = BUDGET_S - (time.monotonic() - start)
            if left < RESERVE_S.get(platform, 60):
                stats["not-started"] += 1
                _line(str(job["key"]), platform, "not-started", 0, 0.0,
                      "budget: %ds left, this job needs up to %ds" % (max(0, left), RESERVE_S.get(platform, 60)))
                continue
            run_job(job, vm, vm_err, browser, stats)
    finally:
        browser.close()
        if vm is not None:
            shutil.rmtree(vm.WORKDIR, ignore_errors=True)

    attempted = stats["posted"] + stats["failed"] + stats["post-error"]
    print("summary: %d job(s) | posted %d | failed %d | post errors %d | skipped (runner) %d"
          " | not started (budget) %d | %.0f s"
          % (len(jobs), stats["posted"], stats["failed"], stats["post-error"], stats["skipped"],
             stats["not-started"], time.monotonic() - start), flush=True)
    if stats["skipped"] or (attempted and stats["post-error"] == attempted):
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
