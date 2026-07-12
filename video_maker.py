#!/usr/bin/env python3
"""
GenZHype faceless-video maker — v3 "moving pictures" (real b-roll + judge).

Adapted from the open-source MoneyPrinterTurbo (MPT) engine
(https://github.com/harry0703/MoneyPrinterTurbo, MIT). This driver pulls a
token-gated JSON feed from genzhype.com, renders the drama into a 9:16
captioned MP4 and POSTs the artifact back.

REUSE vs REPLACE (see videorepos/ADAPTATION.md for the full map):
  * REUSE   -> Turbo's `app.services.voice` TTS pipeline (edge-tts with
              WordBoundary events, the signature-probed `boundary` kwarg, the
              streaming-timeout thread and 3x retry). If the MPT tree is present
              (env MPT_HOME or ./mpt / ./videorepos/mpt) we import and call
              `voice.tts()` directly; otherwise we fall back to a compact,
              faithful in-file port (`_edge_tts_synthesize`) so the script still
              runs on a bare runner with only pip deps.
  * REUSE   -> the idea/timings of Turbo's subtitle step: we read the SAME
              edge-tts `SubMaker.cues` (per-word start/end) but keep them at
              WORD granularity instead of aggregating to phrase SRT lines.
  * REUSE   -> Turbo's BGM mixing recipe (AudioFileClip + afx.MultiplyVolume +
              afx.AudioLoop + CompositeAudioClip, video.py generate_video) and
              its encode settings (libx264 + aac + 192k) plus our own
              `-movflags +faststart` remux.
  * REUSE   -> (v3) Turbo's `app.services.material` stock-video approach:
              Pexels `/videos/search?orientation=portrait` + Pixabay
              `/api/videos/` search, best-rendition pick, download-then-probe
              validation (open with VideoFileClip, require duration>0), URL
              dedup and an audio-duration download budget. material.py itself
              is welded to Turbo's config/schema/loguru, so v3 carries a
              compact in-file port (search_broll_pexels / search_broll_pixabay
              / BrollFetcher) instead of importing it.
  * REPLACE -> Turbo's `video.combine_videos` / `generate_video` are stock-clip
              oriented. We keep its MoviePy 2.x idioms (ImageClip.resized(
              lambda t), CompositeVideoClip, with_start/with_end/with_position,
              VideoFileClip.subclipped/.resized/.cropped, vfx.CrossFadeIn) but
              drive them ourselves.

WHAT v2 ADDS over the single-image Ken-Burns v1:
  1. MULTI-SCENE CUTS — the voiceover is split into sentence beats using the
     edge-tts word timings (aligned against the script's punctuation, because
     edge-tts cues usually strip it). Each beat becomes a full-frame scene
     with its own visual and its own motion, cycling zoom-in / zoom-out /
     pan-left / pan-right, so the video never sits still. Cuts snap to the
     next beat's first-word start (a short CrossFadeIn softens the cut).
  2. VISUAL POOL — the feed now sends `post.visuals` (hero photo, tall branded
     card, event YouTube thumbnails). `post.people` names are additionally
     resolved to real photos via Wikidata (wbsearchentities -> P18 ->
     commons Special:FilePath), mirroring the proven image_engine.py flow;
     every lookup/download failure is non-fatal. Visuals are assigned
     round-robin, hero first — with >=2 visuals no scene repeats its
     predecessor's image; with exactly 1 the motion still alternates per beat
     (never-static v1 fallback).
  3. CAPTION POP — captions show 2-3 word chunks; the CURRENTLY SPOKEN word is
     rendered slightly larger in the brand accent (#FF6A5C) while its
     neighbours stay white (PIL-rendered RGBA -> ImageClip, baseline-aligned,
     black stroke). Everything lives in the lower-middle band, clear of the
     platform-UI safe areas (top 220px / bottom 320px). The oversized HOOK
     treatment over the first ~2s is kept from v1.
  4. OPTIONAL BGM — if .social/bgm/*.mp3 exists (drop ONLY CC0/royalty-free
     tracks there!) one is picked deterministically per page_id, looped to the
     video length, mixed at ~0.10 under the voice with 0.5s fades. Missing or
     broken folder/files -> silent, non-fatal.
  5. GUARDS — <=8 scenes (long sentences are split, short ones merged),
     corrupt/failed visual downloads are dropped from the pool, and everything
     new degrades to the proven v1 behaviour instead of crashing.

WHAT v3 ADDS over v2 (owner verdict on the first render: "an image stuck with
captions, not a video; voice sounds 2022; text card crop-zoomed into
unreadable fragments" — the caption sync itself was loved and is untouched):
  1. REAL B-ROLL — the feed now sends `post.broll` (ordered stock-footage
     search phrases). Scenes ALTERNATE real photos (hero/people) with REAL
     STOCK VIDEO matched to those terms in order, via a compact port of
     Turbo's app/services/material.py (Pexels portrait search + Pixabay,
     keys from PEXELS_API_KEY / PIXABAY_API_KEY). Each b-roll clip is trimmed
     to its beat, cover-cropped to 1080x1920, slightly darkened so captions
     pop, and crossfaded like every other scene. Per-run search+download
     caches, URL dedup, and a hard budget (~120s / ~80MB). NO key, empty
     terms, or any search/download failure -> that beat silently falls back
     to a photo scene (exact v2 behaviour).
  2. MODERN VOICE — default voice is now en-US-AndrewMultilingualNeural
     (edge-tts's 2024-gen natural male; Aria was the "sounds 2022" culprit),
     still +5% rate and still overridable via VIDEO_VOICE. The Multilingual
     family emits WordBoundary events like any other edge-tts voice, and the
     SentenceBoundary/even-split fallbacks below remain as safety nets.
  3. TEXT-HEAVY IMAGE GUARD — before a photo becomes a scene it runs a
     conservative poster/card detector (filename hints 'social-'/'card', or
     extreme aspect vs 9:16 AND large flat-color coverage). Text-heavy images
     are NEVER cover-cropped or Ken-Burns-zoomed: they render "contain"
     (whole image visible) over a blurred darkened fill with only a gentle
     <=2% drift. This is the systemic fix for the crop-zoomed-card defect —
     receipts/screenshots arriving later hit the same guard.
  4. GEMINI VISION JUDGE — after the faststart remux, 4 evenly-spaced frames
     + the hook go to gemini-2.5-flash (GEMINI_API_KEY, native REST
     generateContent, strict-JSON verdict). Unreadable/cut-off text, badly
     cropped faces, or all-identical frames -> the video is NOT delivered and
     the run exits non-zero WITHOUT marking the page done, so the next cron
     retries with a fresh render. Missing key / API error / bad JSON -> the
     judge is skipped (non-fatal) and delivery proceeds. One call per video.

PROVEN v1 PARTS KEPT VERBATIM: the multi-engine fetch/post (curl_cffi
browser-TLS first — Hostinger's TLS fingerprint block), base64-in-JSON video
delivery (WAF blocks multipart), edge-tts synthesis with WordBoundary timings
and 403 retries, the ffmpeg resolution chain (_ffmpeg_bin), the dedup state
file and the faststart remux.

Runtime target: GitHub Actions ubuntu-latest (ffmpeg + fonts preinstalled).
"""

import glob
import hashlib
import html
import json
import logging
import math
import os
import subprocess
import sys
import time
import traceback
import urllib.parse

import numpy as np
import requests

# Sanitize a stale IMAGEIO_FFMPEG_EXE BEFORE any moviepy import: moviepy/imageio read
# it blindly, and a wrong path (run #1: hardcoded /usr/bin/ffmpeg) crashes AudioFileClip.
# Unset -> imageio-ffmpeg resolves its own bundled binary; our remux uses _ffmpeg_bin().
_ff_env = os.environ.get("IMAGEIO_FFMPEG_EXE")
if _ff_env and not os.path.exists(_ff_env):
    del os.environ["IMAGEIO_FFMPEG_EXE"]
from PIL import Image

# ----------------------------------------------------------------------------
# Config (all overridable via env)
# ----------------------------------------------------------------------------
BASE = "https://genzhype.com"
NEXT_URL = os.environ.get("VIDEO_NEXT_URL", f"{BASE}/api/video_next.php")
RECEIVE_URL = os.environ.get("VIDEO_RECEIVE_URL", f"{BASE}/api/video_receive.php")
INGEST_TOKEN = os.environ.get("INGEST_TOKEN", "").strip()

STATE_FILE = os.environ.get("VIDEO_STATE_FILE", ".social/video_done.txt")
WORKDIR = os.environ.get("VIDEO_WORKDIR", "build")

# v3: 2024-gen natural voice (the Multilingual family: Andrew/Brian/Emma/Ava).
# Aria is the 2019-gen voice the owner heard as "2022". Env-overridable.
VOICE = os.environ.get("VIDEO_VOICE", "en-US-AndrewMultilingualNeural")
VOICE_RATE = float(os.environ.get("VIDEO_VOICE_RATE", "1.05"))
VOICE_VOLUME = float(os.environ.get("VIDEO_VOICE_VOLUME", "1.0"))
VIDEO_BATCH = int(os.environ.get("VIDEO_BATCH", "1"))

W, H = 1080, 1920
FPS = int(os.environ.get("VIDEO_FPS", "30"))
HOOK_FONT = 96
TAIL_SECONDS = 0.45            # small pad so the last word/audio is not clipped
TTS_OUTER_RETRIES = 4          # outer retries around the whole TTS call (403 risk)

# --- v2: scenes / motion ---
MAX_SCENES = int(os.environ.get("VIDEO_MAX_SCENES", "8"))
MIN_SCENE_S = 1.4              # beats shorter than this merge into a neighbour
MAX_BEAT_S = 8.0               # sentences longer than this get split
TARGET_BEAT_S = 5.5            # target sub-beat length when splitting long ones
SCENE_ZOOM = 0.10              # zoom-in/out amount per scene
PAN_SCALE = 1.18               # oversize factor that creates room for pans
XFADE = float(os.environ.get("VIDEO_XFADE", "0.15"))   # 0 -> hard cuts

# --- v2: captions ---
ACCENT = "#FF6A5C"             # GenZHype brand accent — the spoken word pops in it
CHUNK_FONT = int(os.environ.get("VIDEO_CHUNK_FONT", "88"))
HOT_SCALE = 1.18               # spoken word renders this much larger
CHUNK_MAX_WORDS = 3
SAFE_TOP = 220                 # platform UI safe areas (nothing rendered inside)
SAFE_BOTTOM = 320
CAPTION_CENTER_Y = int(H * 0.62)   # lower-middle band, well inside the safe area

# --- v2: people photos (Wikidata, image_engine.py's proven flow) ---
MAX_POOL = 8
PEOPLE_BUDGET_S = 100          # hard wall-clock cap on all person lookups

# --- v2: background music ---
# Drop ONLY CC0 / royalty-free .mp3 tracks in this folder (platform copyright
# strikes kill faceless channels). Missing/empty folder -> video stays silent.
BGM_DIR = os.environ.get("VIDEO_BGM_DIR", ".social/bgm")
BGM_VOLUME = float(os.environ.get("VIDEO_BGM_VOLUME", "0.10"))

# --- v3: real b-roll (Pexels/Pixabay stock video; Turbo material.py port) ---
PEXELS_API_KEY = os.environ.get("PEXELS_API_KEY", "").strip()
PIXABAY_API_KEY = os.environ.get("PIXABAY_API_KEY", "").strip()
BROLL_TIME_BUDGET_S = float(os.environ.get("BROLL_TIME_BUDGET_S", "120"))
BROLL_BYTES_BUDGET = int(os.environ.get("BROLL_BYTES_BUDGET",
                                        str(80 * 1024 * 1024)))
BROLL_CLIP_CAP = 32 * 1024 * 1024   # single-clip cap so one 4K file can't eat it
BROLL_DARKEN = 0.78                 # MultiplyColor factor: captions stay readable

# --- v3: text-heavy image guard (posters/cards/receipts NEVER crop-zoomed) ---
TEXTISH_NAME_HINTS = ("social-", "card")
TEXTISH_DRIFT = 0.02                # gentle drift only, fraction of W; NO zoom
TEXTISH_FLAT_FRAC = 0.55            # top-4 quantized colors must cover >= this

# --- v3: Gemini vision judge (the "brain that can see") ---
GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY", "").strip()
GEMINI_MODEL = os.environ.get("GEMINI_MODEL", "gemini-2.5-flash")
JUDGE_FRAMES = 4

logging.basicConfig(
    level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s"
)
log = logging.getLogger("video_maker")

CAPTION_FONT_CANDIDATES = [
    os.environ.get("CAPTION_FONT", ""),
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
]
# Single-weight bold display font that looks great for captions; downloaded only
# if no system bold font is found. (Google Fonts, OFL.)
ANTON_URL = (
    "https://github.com/google/fonts/raw/main/ofl/anton/Anton-Regular.ttf"
)

# Hostinger's bot protection TLS-fingerprint-blocks datacenter Python intermittently
# (the scraper-v7 lesson; it 403'd run #3 from the GH runner while the same URL was 200
# from elsewhere). Cure = the proven multi-engine pattern: browser-TLS via curl_cffi
# first, then requests — with retries and a browser UA.
_BROWSER_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) "
               "Gecko/20100101 Firefox/130.0")


# ============================================================================
# TTS  (reuse Turbo voice.py when available; faithful in-file fallback otherwise)
# ============================================================================
def _convert_rate_to_percent(rate):
    """Port of voice.convert_rate_to_percent — edge-tts wants '+8%' / '-20%'."""
    try:
        rate = float(rate)
    except (TypeError, ValueError):
        rate = 1.0
    if rate <= 0:
        rate = 1.0
    percent = round((rate - 1.0) * 100)
    return f"+{percent}%" if percent >= 0 else f"{percent}%"


def _ensure_min_config(mpt_home):
    """MPT's app.config.load_config() crashes if config.toml AND
    config.example.toml are both absent (the extracted tree has neither).
    Drop a minimal config.toml so `import app.services.voice` succeeds."""
    cfg = os.path.join(mpt_home, "config.toml")
    if not os.path.isfile(cfg):
        try:
            with open(cfg, "w", encoding="utf-8") as f:
                f.write("[app]\nedge_tts_timeout = 30\n[whisper]\n[ui]\n")
        except OSError as exc:
            log.warning("could not write minimal config.toml: %s", exc)


def _load_mpt_voice():
    """Import Turbo's real voice module if the MPT tree is on disk."""
    here = os.path.dirname(os.path.abspath(__file__))
    candidates = [
        os.environ.get("MPT_HOME", ""),
        os.path.join(os.getcwd(), "mpt"),
        os.path.join(here, "mpt"),
        os.path.join(here, "videorepos", "mpt"),
    ]
    for c in candidates:
        if c and os.path.isdir(os.path.join(c, "app", "services")):
            _ensure_min_config(c)
            if c not in sys.path:
                sys.path.insert(0, c)
            try:
                from app.services import voice as mpt_voice  # type: ignore
                log.info("using Turbo voice module from %s", c)
                return mpt_voice
            except Exception as exc:  # noqa: BLE001
                log.warning("MPT tree at %s not importable (%s); using fallback",
                            c, exc)
    log.info("MPT voice module not found; using in-file edge-tts fallback")
    return None


def _make_communicate(text, voice_name, rate_str):
    """Port of voice.create_edge_tts_communicate: only pass boundary= on
    edge_tts versions whose Communicate accepts it (7.x)."""
    import inspect
    import edge_tts

    kwargs = {"rate": rate_str}
    try:
        sig = inspect.signature(edge_tts.Communicate)
        if "boundary" in sig.parameters:
            kwargs["boundary"] = "WordBoundary"
    except (TypeError, ValueError):
        pass
    return edge_tts.Communicate(text, voice_name, **kwargs)


def _edge_tts_synthesize(text, voice_name, rate_str, out_mp3):
    """Compact port of voice.azure_tts_v1: stream edge-tts audio to disk and
    feed WordBoundary/SentenceBoundary events into a SubMaker (returns cues)."""
    import edge_tts

    communicate = _make_communicate(text, voice_name, rate_str)
    sub = edge_tts.SubMaker()
    os.makedirs(os.path.dirname(os.path.abspath(out_mp3)), exist_ok=True)
    with open(out_mp3, "wb") as f:
        for chunk in communicate.stream_sync():
            ctype = chunk.get("type")
            if ctype == "audio":
                f.write(chunk["data"])
            elif ctype in ("WordBoundary", "SentenceBoundary"):
                sub.feed(chunk)
    if os.path.exists(out_mp3) and os.path.getsize(out_mp3) == 0:
        os.remove(out_mp3)
        raise RuntimeError("edge-tts produced an empty audio file")
    return sub


def _cues_to_word_timings(sub):
    """Extract per-word (text, start_s, end_s) from an edge_tts SubMaker.

    Primary: edge_tts 7.x `.cues` (word-level, timedelta start/end).
    Fallback: Turbo's legacy `.subs`/`.offset` (100ns units)."""
    timings = []
    cues = getattr(sub, "cues", None)
    if cues:
        for cue in cues:
            word = html.unescape((cue.content or "")).strip()
            if not word:
                continue
            timings.append(
                (word, cue.start.total_seconds(), cue.end.total_seconds())
            )
        return timings

    subs = getattr(sub, "subs", []) or []
    offs = getattr(sub, "offset", []) or []
    for text, off in zip(subs, offs):
        word = html.unescape((text or "")).strip()
        if not word:
            continue
        timings.append((word, off[0] / 1e7, off[1] / 1e7))
    return timings


def _explode_multiword(timings):
    """Some voices emit multi-token boundary chunks. Split them into single
    words, distributing the chunk's time span evenly, so captions stay 1 word."""
    out = []
    for word, s, e in timings:
        parts = word.split()
        if len(parts) <= 1:
            out.append((word, s, e))
            continue
        span = max(e - s, 0.001) / len(parts)
        for i, p in enumerate(parts):
            out.append((p, s + i * span, s + (i + 1) * span))
    return out


def _even_word_timings(script, duration):
    """Last-resort: no boundaries at all -> split words evenly across audio."""
    words = [w for w in script.split() if w.strip()]
    if not words:
        return []
    step = duration / len(words)
    return [(w, i * step, (i + 1) * step) for i, w in enumerate(words)]


def synthesize(script, out_mp3):
    """Return (word_timings, duration_seconds). Retries the whole TTS call to
    ride out edge-tts 403 / Sec-MS-GEC token failures."""
    mpt_voice = _load_mpt_voice()
    rate_str = _convert_rate_to_percent(VOICE_RATE)
    last_err = None

    for attempt in range(1, TTS_OUTER_RETRIES + 1):
        try:
            log.info("TTS attempt %d/%d voice=%s rate=%s",
                     attempt, TTS_OUTER_RETRIES, VOICE, rate_str)
            if mpt_voice is not None:
                sub = mpt_voice.tts(
                    text=script,
                    voice_name=mpt_voice.parse_voice_name(VOICE),
                    voice_rate=VOICE_RATE,
                    voice_file=out_mp3,
                    voice_volume=VOICE_VOLUME,
                )
                if sub is None:
                    raise RuntimeError("voice.tts() returned None")
                duration = float(mpt_voice.get_audio_duration(sub) or 0)
            else:
                sub = _edge_tts_synthesize(script, VOICE, rate_str, out_mp3)
                duration = 0.0

            timings = _explode_multiword(_cues_to_word_timings(sub))

            # Trust the real audio file for the timeline length.
            file_dur = _audio_duration(out_mp3)
            if file_dur > 0:
                duration = max(duration, file_dur)
            if timings:
                duration = max(duration, timings[-1][2])
            if duration <= 0:
                raise RuntimeError("could not determine audio duration")

            if not timings:
                log.warning("no word boundaries returned; using even split")
                timings = _even_word_timings(script, duration)

            log.info("TTS ok: %.2fs audio, %d word timings", duration, len(timings))
            return timings, duration
        except Exception as exc:  # noqa: BLE001
            last_err = exc
            is_403 = "403" in str(exc) or "Sec-MS-GEC" in str(exc)
            wait = (6 if is_403 else 3) * attempt
            log.warning("TTS failed (%s). retrying in %ds", exc, wait)
            if os.path.exists(out_mp3) and os.path.getsize(out_mp3) == 0:
                try:
                    os.remove(out_mp3)
                except OSError:
                    pass
            time.sleep(wait)

    raise RuntimeError(f"TTS failed after {TTS_OUTER_RETRIES} attempts: {last_err}")


def _audio_duration(path):
    if not os.path.exists(path):
        return 0.0
    try:
        from moviepy import AudioFileClip
        with AudioFileClip(path) as a:
            return float(a.duration or 0)
    except Exception as exc:  # noqa: BLE001
        log.warning("could not read audio duration: %s", exc)
        return 0.0


# ============================================================================
# Fonts
# ============================================================================
def resolve_font():
    for cand in CAPTION_FONT_CANDIDATES:
        if cand and os.path.isfile(cand):
            log.info("caption font: %s", cand)
            return cand
    # No system bold font -> fetch Anton once.
    dest = os.path.join(WORKDIR, "Anton-Regular.ttf")
    try:
        os.makedirs(WORKDIR, exist_ok=True)
        r = requests.get(ANTON_URL, timeout=30)
        r.raise_for_status()
        with open(dest, "wb") as f:
            f.write(r.content)
        log.info("downloaded caption font: %s", dest)
        return dest
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(
            "no usable caption font found and Anton download failed; "
            "set CAPTION_FONT to a .ttf path"
        ) from exc


# ============================================================================
# Visual pool: feed visuals + Wikidata person photos (all non-fatal)
# ============================================================================
def _download_bytes(url):
    """Multi-engine download (curl_cffi browser-TLS first — the proven pattern).
    Returns bytes or None; NEVER raises."""
    last = None
    for attempt in range(1, 3):
        try:
            from curl_cffi import requests as cffi
            r = cffi.get(url, impersonate="firefox", timeout=45,
                         headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.content:
                return r.content
            last = f"curl_cffi HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        try:
            r = requests.get(url, timeout=45, headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.content:
                return r.content
            last = f"requests HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        time.sleep(2 * attempt)
    log.warning("visual download failed (%s): %s", last, url[:120])
    return None


def _trim_letterbox(img, thr=16.0, max_frac=0.28):
    """Crop uniform near-black letterbox/pillarbox bars off the edges (YouTube
    hqdefault thumbnails ship 4:3 with baked-in bars; cover-fitting those to
    9:16 would blow the bars up into huge black bands). Trims only contiguous
    dark edge rows/cols, at most `max_frac` per side; on any doubt returns the
    image unchanged."""
    try:
        g = np.asarray(img.convert("L"), dtype=np.float32)
        h, w = g.shape
        row, col = g.mean(axis=1), g.mean(axis=0)
        top, bot, left, right = 0, h, 0, w
        while top < int(h * max_frac) and row[top] < thr:
            top += 1
        while bot > h - int(h * max_frac) and row[bot - 1] < thr:
            bot -= 1
        while left < int(w * max_frac) and col[left] < thr:
            left += 1
        while right > w - int(w * max_frac) and col[right - 1] < thr:
            right -= 1
        if (top, left, bot, right) != (0, 0, h, w) \
                and (bot - top) >= h * 0.5 and (right - left) >= w * 0.5:
            return img.crop((left, top, right, bot))
        return img
    except Exception:  # noqa: BLE001
        return img


def fetch_visual(url, dest):
    """Download + validate one visual. Corrupt/tiny/unreadable -> None (dropped
    from the pool), never a crash. Letterbox bars are trimmed on arrival."""
    data = _download_bytes(url)
    if not data or len(data) < 2000:
        return None
    try:
        with open(dest, "wb") as f:
            f.write(data)
        img = Image.open(dest)
        img.load()                           # force full decode: catches truncation
        img = img.convert("RGB")
        trimmed = _trim_letterbox(img)
        if trimmed.size != img.size:
            log.info("trimmed letterbox %s -> %s: %s", img.size, trimmed.size,
                     url[:120])
            trimmed.save(dest, "JPEG", quality=92)
        w, h = trimmed.size
        if min(w, h) < 200:
            log.warning("visual too small (%dx%d), dropped: %s", w, h, url[:120])
            return None
        return dest
    except Exception as exc:  # noqa: BLE001
        log.warning("visual corrupt (%s), dropped: %s", exc, url[:120])
        return None


def _public_json(url, timeout=20):
    """GET public JSON (Wikidata/Commons). Browser UA + curl_cffi first, exactly
    like image_engine.py's http_json. Returns dict or None; never raises."""
    try:
        from curl_cffi import requests as cffi
        r = cffi.get(url, impersonate="firefox", timeout=timeout,
                     headers={"User-Agent": _BROWSER_UA,
                              "Accept": "application/json"})
        if r.status_code == 200:
            return r.json()
    except Exception:  # noqa: BLE001
        pass
    try:
        r = requests.get(url, timeout=timeout,
                         headers={"User-Agent": _BROWSER_UA,
                                  "Accept": "application/json"})
        if r.status_code == 200:
            return r.json()
    except Exception:  # noqa: BLE001
        pass
    return None


# Occupation words that signal a famous NAMESAKE in another field (the
# "Ben Schneider the folk musician" defamation bug) — from image_engine.py.
_NAMESAKE_WORDS = ("musician", "singer", "songwriter", "guitarist", "drummer",
                   "band", "composer", "footballer", "football player",
                   "basketball", "baseball", "cricketer", "politician",
                   "senator", "governor", "novelist", "author", "painter",
                   "economist", "scientist", "physician", "astronaut")
_CREATORISH_WORDS = ("youtuber", "streamer", "internet", "influencer",
                     "content creator", "twitch", "social media", "online",
                     "personality", "gamer", "tiktok", "podcaster", "media")


def wikidata_person_photo_url(name, context=""):
    """Resolve a person name to a real photo URL via Wikidata — the PROVEN
    image_engine.py flow: wbsearchentities -> entity -> wbgetclaims P18 ->
    commons Special:FilePath. Returns a URL or None; STRICTLY non-fatal."""
    try:
        q = _public_json(
            "https://www.wikidata.org/w/api.php?action=wbsearchentities"
            "&format=json&language=en&type=item&limit=5&search="
            + urllib.parse.quote(name))
        results = (q or {}).get("search") or []
        if not results:
            return None
        top = results[0]
        desc = (top.get("description") or "").lower()
        mismatch = any(w in desc for w in _NAMESAKE_WORDS)
        creatorish = any(w in desc for w in _CREATORISH_WORDS)
        ctx = (name + " " + context).lower()
        ctx_ok = any(w in ctx for w in ("music", "song", "album", "rap",
                                        "concert", "band", "sport", "politic",
                                        "film", "movie", "novel", "paint",
                                        "science"))
        if mismatch and not creatorish and not ctx_ok:
            log.info("wikidata: '%s' resolves to a non-creator namesake (%s); "
                     "skipped", name, desc[:60])
            return None
        ent = top.get("id")
        if not ent:
            return None
        d = _public_json(
            "https://www.wikidata.org/w/api.php?action=wbgetclaims"
            f"&format=json&property=P18&entity={ent}")
        claims = ((d or {}).get("claims") or {}).get("P18") or [{}]
        img = (((claims[0].get("mainsnak") or {}).get("datavalue") or {})
               .get("value"))
        if not img or not isinstance(img, str):
            return None
        fn = img.replace(" ", "_")
        return ("https://commons.wikimedia.org/wiki/Special:FilePath/"
                + urllib.parse.quote(fn) + "?width=1400")
    except Exception as exc:  # noqa: BLE001
        log.warning("wikidata lookup failed for '%s': %s", name, exc)
        return None


def _flat_color_fraction(img):
    """Fraction of pixels covered by the 4 most common quantized colors on a
    64x64 thumbnail. Posters/branded cards have big flat fills; real photos
    almost never cross ~0.5. Any failure -> 0.0 (treated as a normal photo)."""
    try:
        small = img.convert("RGB").resize((64, 64))
        arr = np.asarray(small, dtype=np.int32) // 32
        codes = arr[..., 0] * 64 + arr[..., 1] * 8 + arr[..., 2]
        _, counts = np.unique(codes, return_counts=True)
        top = int(np.sort(counts)[::-1][:4].sum())
        return top / float(codes.size)
    except Exception:  # noqa: BLE001
        return 0.0


def is_text_heavy(path, src_url=""):
    """v3 guard: conservative text-heavy/poster detector. True only when the
    source filename carries a hint ('social-'/'card') OR the image is BOTH
    extreme-aspect vs the 9:16 frame AND dominated by flat color. Text-heavy
    images are rendered 'contain' (never cover-cropped / Ken-Burns-zoomed) —
    the systemic fix for the crop-zoomed-unreadable-card defect."""
    name = (os.path.basename(urllib.parse.urlparse(src_url or "").path)
            + " " + os.path.basename(path)).lower()
    if any(hint in name for hint in TEXTISH_NAME_HINTS):
        return True
    try:
        with Image.open(path) as img:
            w, h = img.size
            ratio = w / float(h)
            if 0.42 <= ratio <= 1.95:       # near-frame or normal photo shapes
                return False
            return _flat_color_fraction(img) >= TEXTISH_FLAT_FRAC
    except Exception:  # noqa: BLE001
        return False


def build_visual_pool(post, page_id):
    """Assemble the scene visual pool: feed visuals (hero first) + resolved
    person photos, deduped, downloaded, validated. Returns a list of
    {"path", "textish"} dicts (v3: textish photos get the contain renderer)."""
    urls = []
    vis = post.get("visuals")
    if isinstance(vis, list):
        urls = [u for u in vis if isinstance(u, str) and u.startswith("http")]
    if not urls and post.get("image"):
        urls = [post["image"]]

    # People -> real photos (more real faces = more scenes). Never fatal.
    person_urls = []
    people = post.get("people") or []
    if isinstance(people, list) and people:
        context = f"{post.get('title', '')} {(post.get('script') or '')[:200]}"
        t0 = time.time()
        for name in people[:4]:
            if time.time() - t0 > PEOPLE_BUDGET_S:
                log.info("people budget exhausted; skipping remaining names")
                break
            name = str(name).strip()
            if not name:
                continue
            u = wikidata_person_photo_url(name, context)
            if u:
                log.info("person photo resolved: %s", name)
                person_urls.append(u)

    # Hero first, then real faces, then the rest (card, receipts thumbnails).
    ordered, seen = [], set()
    for u in urls[:1] + person_urls + urls[1:]:
        if u not in seen:
            seen.add(u)
            ordered.append(u)

    pool = []
    for i, u in enumerate(ordered[:MAX_POOL]):
        p = fetch_visual(u, os.path.join(WORKDIR, f"vis-{page_id}-{i}"))
        if p:
            textish = is_text_heavy(p, src_url=u)
            if textish:
                log.info("text-heavy visual -> contain mode (no crop/zoom): %s",
                         u[:120])
            pool.append({"path": p, "textish": textish})
    log.info("visual pool: %d usable of %d candidates (%d from people)",
             len(pool), len(ordered), len(person_urls))
    return pool


# ============================================================================
# v3: real B-ROLL — compact port of Turbo app/services/material.py
# (search Pexels portrait + Pixabay, pick best rendition, download, probe with
# VideoFileClip, dedup URLs, hard time/byte budget). ALL failures degrade to
# photo scenes; a missing key simply disables the whole feature.
# ============================================================================
def _api_json(url, headers=None, timeout=30):
    """GET JSON from a stock API. requests first (Pexels/Pixabay don't TLS-
    fingerprint-block), curl_cffi browser-TLS as backup. Never raises."""
    hdrs = dict(headers or {})
    hdrs.setdefault("User-Agent", _BROWSER_UA)
    try:
        r = requests.get(url, headers=hdrs, timeout=timeout)
        if r.status_code == 200:
            return r.json()
        log.warning("stock api HTTP %d: %s", r.status_code, url[:120])
    except Exception as e:  # noqa: BLE001
        log.warning("stock api requests failed (%s); trying curl_cffi", e)
    try:
        from curl_cffi import requests as cffi
        r = cffi.get(url, headers=hdrs, impersonate="firefox", timeout=timeout)
        if r.status_code == 200:
            return r.json()
        log.warning("stock api curl_cffi HTTP %d: %s", r.status_code, url[:120])
    except Exception as e:  # noqa: BLE001
        log.warning("stock api curl_cffi failed: %s", e)
    return None


def search_broll_pexels(term):
    """Port of material.search_videos_pexels, loosened: Turbo demanded an
    exact 1080x1920 file; we take that when present, else the smallest
    portrait rendition >=1280 tall (we cover-crop to the frame anyway)."""
    if not PEXELS_API_KEY:
        return []
    q = urllib.parse.urlencode(
        {"query": term, "per_page": 15, "orientation": "portrait"})
    data = _api_json(f"https://api.pexels.com/videos/search?{q}",
                     headers={"Authorization": PEXELS_API_KEY})
    items = []
    for v in (data or {}).get("videos") or []:
        try:
            dur = float(v.get("duration") or 0)
        except (TypeError, ValueError):
            continue
        best = None                      # (height, url); exact match wins
        for f in v.get("video_files") or []:
            w, h = int(f.get("width") or 0), int(f.get("height") or 0)
            link = f.get("link")
            if not link or not w or not h:
                continue
            if (w, h) == (1080, 1920):
                best = (h, link)
                break
            if h > w and h >= 1280 and (best is None or h < best[0]):
                best = (h, link)
        if best and dur > 0:
            items.append({"url": best[1], "duration": dur,
                          "provider": "pexels"})
    return items


def search_broll_pixabay(term):
    """Port of material.search_videos_pixabay. Pixabay has no portrait filter;
    prefer a portrait variant, else any >=1080 (cover-crop handles landscape)."""
    if not PIXABAY_API_KEY:
        return []
    q = urllib.parse.urlencode({"q": term, "per_page": 30,
                                "video_type": "all", "key": PIXABAY_API_KEY})
    data = _api_json(f"https://pixabay.com/api/videos/?{q}")
    items = []
    for v in (data or {}).get("hits") or []:
        try:
            dur = float(v.get("duration") or 0)
        except (TypeError, ValueError):
            continue
        files = v.get("videos") or {}
        best = None
        for variant in ("large", "medium", "small"):
            f = files.get(variant) or {}
            w, h = int(f.get("width") or 0), int(f.get("height") or 0)
            url = f.get("url")
            if not url or not w or not h:
                continue
            if h > w and h >= 1080:      # portrait first
                best = url
                break
            if best is None and max(w, h) >= 1080:
                best = url
        if best and dur > 0:
            items.append({"url": best, "duration": dur,
                          "provider": "pixabay"})
    return items


class BrollFetcher:
    """Per-run b-roll manager: walks the feed's `broll` terms IN ORDER (cursor
    cycles), caches searches and downloads, dedups URLs, and enforces a hard
    wall-clock + byte budget. clip_for() returns a validated local .mp4 at
    least `need_s` long, or None -> the caller falls back to a photo scene."""

    def __init__(self, terms):
        self.terms = [str(t).strip() for t in (terms or []) if str(t).strip()]
        self.enabled = bool(self.terms) and bool(PEXELS_API_KEY
                                                 or PIXABAY_API_KEY)
        if self.terms and not self.enabled:
            log.info("broll terms present but no PEXELS/PIXABAY key; "
                     "photos-only (v2 behaviour)")
        elif not self.terms:
            log.info("no broll terms in feed; photos-only (v2 behaviour)")
        self.searches = {}     # term -> [items]
        self.downloads = {}    # url-hash -> local path or None (failed)
        self.used = set()      # urls already placed in a scene
        self.cursor = 0
        self.t0 = time.time()
        self.bytes = 0

    def _budget_ok(self):
        if time.time() - self.t0 > BROLL_TIME_BUDGET_S:
            log.info("broll time budget (%.0fs) exhausted; photos from here",
                     BROLL_TIME_BUDGET_S)
            self.enabled = False
        elif self.bytes > BROLL_BYTES_BUDGET:
            log.info("broll byte budget (%.0f MB) exhausted; photos from here",
                     BROLL_BYTES_BUDGET / 1024 / 1024)
            self.enabled = False
        return self.enabled

    def _search(self, term):
        if term not in self.searches:
            items = search_broll_pexels(term) + search_broll_pixabay(term)
            log.info("broll search '%s': %d candidate(s)", term, len(items))
            self.searches[term] = items
        return self.searches[term]

    def _download(self, url):
        key = hashlib.md5(url.split("?")[0].encode()).hexdigest()
        if key in self.downloads:
            return self.downloads[key]
        dest = os.path.join(WORKDIR, f"broll-{key}.mp4")
        self.downloads[key] = self._stream_to(url, dest)
        return self.downloads[key]

    def _stream_to(self, url, dest):
        try:
            got = 0
            with requests.get(url, stream=True, timeout=(30, 120),
                              headers={"User-Agent": _BROWSER_UA}) as r:
                if r.status_code != 200:
                    raise RuntimeError(f"HTTP {r.status_code}")
                with open(dest, "wb") as f:
                    for chunk in r.iter_content(512 * 1024):
                        got += len(chunk)
                        if got > BROLL_CLIP_CAP:
                            raise RuntimeError("clip exceeds per-clip cap")
                        f.write(chunk)
            if got < 20000:
                raise RuntimeError("suspiciously small file")
            self.bytes += got
        except Exception as e:  # noqa: BLE001
            log.warning("broll download failed (%s): %s", e, url[:120])
            try:
                os.remove(dest)
            except OSError:
                pass
            return None
        # Validate like Turbo's save_video: must open and report a duration.
        try:
            from moviepy import VideoFileClip
            with VideoFileClip(dest) as probe:
                if not probe.duration or probe.duration <= 0:
                    raise RuntimeError("zero duration")
        except Exception as e:  # noqa: BLE001
            log.warning("broll file invalid (%s): %s", e, url[:120])
            try:
                os.remove(dest)
            except OSError:
                pass
            return None
        return dest

    def clip_for(self, need_s):
        """Local mp4 >= need_s long for the NEXT term in feed order, or None."""
        if not self.enabled or not self._budget_ok():
            return None
        for _ in range(len(self.terms)):
            term = self.terms[self.cursor % len(self.terms)]
            self.cursor += 1
            for item in self._search(term):
                if item["duration"] < need_s + 0.25 or item["url"] in self.used:
                    continue
                if not self._budget_ok():
                    return None
                path = self._download(item["url"])
                if path:
                    self.used.add(item["url"])
                    log.info("broll matched '%s' (%.1fs clip for %.1fs beat, "
                             "%s)", term, item["duration"], need_s,
                             item["provider"])
                    return path
        return None


# ============================================================================
# Sentence beats (scene boundaries snapped to word timings)
# ============================================================================
_ABBREV = {"mr", "mrs", "ms", "dr", "st", "vs", "jr", "sr", "no", "etc", "approx"}
_TRIM = "\"'“”‘’()[]"


def _is_sentence_end(word):
    w = (word or "").strip().strip(_TRIM)
    if not w or w[-1] not in ".!?…":
        return False
    core = w.rstrip(".!?…").strip(_TRIM).lower()
    if core in _ABBREV:
        return False
    if "." in core:                     # "U.S." / "e.g." style abbreviations
        return False
    return True


def split_beats(script, timings):
    """Group word timings into sentence beats. edge-tts cues usually STRIP
    punctuation, so when the cues themselves carry none we detect sentence
    ends in the SCRIPT text and map them onto the timings proportionally
    (script word k -> timing index k*len(timings)/len(script_words))."""
    n = len(timings)
    if n == 0:
        return []

    if any(_is_sentence_end(t[0]) for t in timings):
        breaks = [i for i, t in enumerate(timings) if _is_sentence_end(t[0])]
    else:
        words = [w for w in script.split() if w.strip()]
        breaks = []
        if words:
            ends = [i for i, w in enumerate(words) if _is_sentence_end(w)]
            breaks = sorted({
                max(0, min(n - 1, int(round((i + 1) * n / len(words))) - 1))
                for i in ends
            })
    if not breaks or breaks[-1] != n - 1:
        breaks = list(breaks) + [n - 1]

    beats, prev = [], 0
    for b in breaks:
        if b < prev:
            continue
        beats.append(list(timings[prev:b + 1]))
        prev = b + 1

    # Merge too-short beats into the previous one (forward pass + tail fix).
    merged = []
    for beat in beats:
        if merged and (merged[-1][-1][2] - merged[-1][0][1]) < MIN_SCENE_S:
            merged[-1].extend(beat)
        else:
            merged.append(beat)
    if len(merged) > 1 and (merged[-1][-1][2] - merged[-1][0][1]) < MIN_SCENE_S:
        tail = merged.pop()
        merged[-1].extend(tail)

    # Split marathon sentences so the motion keeps changing.
    split = []
    for beat in merged:
        span = beat[-1][2] - beat[0][1]
        if span <= MAX_BEAT_S or len(beat) < 4:
            split.append(beat)
            continue
        k = min(int(math.ceil(span / TARGET_BEAT_S)), len(beat))
        step = len(beat) / k
        for j in range(k):
            part = beat[int(round(j * step)):int(round((j + 1) * step))]
            if part:
                split.append(part)

    # Cap the scene count: repeatedly merge the shortest adjacent pair.
    while len(split) > MAX_SCENES:
        durs = [b[-1][2] - b[0][1] for b in split]
        i = min(range(len(split) - 1), key=lambda k2: durs[k2] + durs[k2 + 1])
        nxt = split.pop(i + 1)
        split[i].extend(nxt)

    return split


# ============================================================================
# Composition: scenes, scrim, hook, chunk captions
# ============================================================================
def cover_fit(pil_img, tw, th):
    """Scale to COVER (tw, th) and center-crop — fills the frame, no bars."""
    pil_img = pil_img.convert("RGB")
    w, h = pil_img.size
    scale = max(tw / w, th / h)
    nw, nh = max(tw, int(round(w * scale))), max(th, int(round(h * scale)))
    pil_img = pil_img.resize((nw, nh), Image.Resampling.LANCZOS)
    left, top = (nw - tw) // 2, (nh - th) // 2
    return pil_img.crop((left, top, left + tw, top + th))


def make_scrim(duration):
    """Vertical dark gradient: darker at top (hook) and bottom (captions),
    lighter in the middle so the visuals still read."""
    from moviepy import ImageClip

    ys = np.linspace(0.0, 1.0, H)
    alpha = np.interp(
        ys,
        [0.00, 0.12, 0.30, 0.58, 0.80, 1.00],
        [130, 70, 22, 22, 130, 185],
    ).astype(np.uint8)
    grad = np.zeros((H, W, 4), dtype=np.uint8)
    grad[..., 3] = alpha[:, None]
    return ImageClip(grad, transparent=True).with_duration(duration)


def scene_clip(image_path, start, end, motion):
    """One full-frame scene with its own motion. `motion` cycles through
    zoom-in / zoom-out / pan-left / pan-right per scene. Pans on portrait
    sources become vertical pans (a horizontal pan would crop a tall branded
    card to a sliver). Cuts land on `start`; XFADE softens the incoming edge."""
    from moviepy import CompositeVideoClip, ImageClip, vfx

    dur = max(end - start, 0.2)
    pil = Image.open(image_path)
    src_w, src_h = pil.size
    portrait = src_h > src_w

    if motion in ("panl", "panr") and not portrait:
        bw = int(W * PAN_SCALE)
        base = ImageClip(np.array(cover_fit(pil, bw, H))).with_duration(dur)
        travel = float(bw - W)
        x0, x1 = (0.0, -travel) if motion == "panl" else (-travel, 0.0)

        def _pos(t, x0=x0, x1=x1, d=dur):
            return (x0 + (x1 - x0) * (t / d), 0)

        moving = base.with_position(_pos)
    elif motion in ("panl", "panr"):
        bh = int(H * PAN_SCALE)
        base = ImageClip(np.array(cover_fit(pil, W, bh))).with_duration(dur)
        travel = float(bh - H)
        y0, y1 = (0.0, -travel) if motion == "panl" else (-travel, 0.0)

        def _pos(t, y0=y0, y1=y1, d=dur):
            return (0, y0 + (y1 - y0) * (t / d))

        moving = base.with_position(_pos)
    else:
        base = ImageClip(np.array(cover_fit(pil, W, H))).with_duration(dur)
        if motion == "out":
            def _scale(t, d=dur):
                return max(1.001, 1.0 + SCENE_ZOOM - SCENE_ZOOM * (t / d))
        else:
            def _scale(t, d=dur):
                return max(1.001, 1.0 + SCENE_ZOOM * (t / d))
        moving = base.resized(_scale).with_position("center")
    pil.close()

    clip = CompositeVideoClip([moving], size=(W, H)).with_duration(dur)
    clip = clip.with_start(start)
    if XFADE > 0 and start > 0:
        try:
            clip = clip.with_effects([vfx.CrossFadeIn(min(XFADE, dur / 2))])
        except Exception as exc:  # noqa: BLE001
            log.warning("crossfade unavailable (%s); hard cut", exc)
    return clip


def contain_scene_clip(image_path, start, end):
    """v3 text-heavy renderer: the WHOLE image fits inside the frame
    ('contain') over a blurred darkened fill of itself — no cover-crop, no
    Ken-Burns zoom, only a gentle <=2% horizontal drift so the scene is not
    dead-static. This is what posters/cards/receipts/screenshots get."""
    from moviepy import CompositeVideoClip, ImageClip, vfx
    from PIL import ImageEnhance, ImageFilter

    dur = max(end - start, 0.2)
    drift = max(2, int(W * TEXTISH_DRIFT))
    pil = Image.open(image_path).convert("RGB")

    canvas_w = W + drift                       # oversize -> room to drift
    bg = cover_fit(pil, canvas_w, H).filter(ImageFilter.GaussianBlur(32))
    bg = ImageEnhance.Brightness(bg).enhance(0.45)

    w, h = pil.size
    scale = min((W * 0.94) / w, (H * 0.90) / h)
    fg = pil.resize((max(1, int(w * scale)), max(1, int(h * scale))),
                    Image.Resampling.LANCZOS)
    canvas = bg.copy()
    canvas.paste(fg, ((canvas_w - fg.width) // 2, (H - fg.height) // 2))
    pil.close()

    base = ImageClip(np.array(canvas)).with_duration(dur)

    def _pos(t, d=dur, px=float(drift)):
        return (-px * (t / d), 0)

    clip = CompositeVideoClip([base.with_position(_pos)],
                              size=(W, H)).with_duration(dur)
    clip = clip.with_start(start)
    if XFADE > 0 and start > 0:
        try:
            clip = clip.with_effects([vfx.CrossFadeIn(min(XFADE, dur / 2))])
        except Exception as exc:  # noqa: BLE001
            log.warning("crossfade unavailable (%s); hard cut", exc)
    return clip


def broll_scene_clip(video_path, start, end):
    """v3: one full-frame B-ROLL scene — trim to the beat length, cover-crop
    to 1080x1920 (MoviePy 2.x .subclipped/.resized/.cropped), darken slightly
    so the captions pop over busy footage, crossfade in like every scene.
    Returns (clip, source): the VideoFileClip must stay OPEN until after
    write_videofile — the caller closes it."""
    from moviepy import CompositeVideoClip, VideoFileClip, vfx

    dur = max(end - start, 0.2)
    src = VideoFileClip(video_path)
    clip = src.without_audio()
    if clip.duration and clip.duration > dur + 0.05:
        off = min(0.3, clip.duration - dur - 0.05)   # skip a hair of slate
        clip = clip.subclipped(off, off + dur)
    elif clip.duration and clip.duration < dur:      # guarded by selection;
        try:                                         # belt-and-suspenders
            clip = clip.with_effects([vfx.Loop(duration=dur)])
        except Exception as exc:  # noqa: BLE001
            log.warning("broll loop unavailable (%s); trimming beat", exc)

    w, h = clip.size
    scale = max(W / float(w), H / float(h)) * 1.002   # epsilon: rounding can
    clip = clip.resized(scale)                        # leave the frame 1px shy
    clip = clip.cropped(width=W, height=H,
                        x_center=clip.w / 2.0, y_center=clip.h / 2.0)
    try:
        clip = clip.with_effects([vfx.MultiplyColor(BROLL_DARKEN)])
    except Exception as exc:  # noqa: BLE001
        log.warning("broll darken unavailable (%s)", exc)

    out = CompositeVideoClip([clip.with_position("center")],
                             size=(W, H)).with_duration(dur)
    out = out.with_start(start)
    if XFADE > 0 and start > 0:
        try:
            out = out.with_effects([vfx.CrossFadeIn(min(XFADE, dur / 2))])
        except Exception as exc:  # noqa: BLE001
            log.warning("crossfade unavailable (%s); hard cut", exc)
    return out, src


def plan_scenes(beats, pool, fetcher, total):
    """v3 scene planner. Scene N starts exactly at beat N's first-word start
    and runs to the next beat's start (+XFADE overlap), so cuts land with the
    voice — unchanged from v2. NEW: scenes ALTERNATE real photos (hero first,
    round-robin over the photo pool) with B-ROLL video matched to the feed's
    `broll` terms in order. A beat only becomes b-roll when the fetcher
    actually delivers a long-enough validated clip; otherwise it falls back
    to a photo (exact v2 behaviour). Text-heavy photos are flagged for the
    contain renderer; normal photos keep the v2 motion cycle."""
    motions = ("in", "out", "panl", "panr")
    if not beats:
        starts_ends = [(0.0, total)]
    else:
        starts = [0.0] + [b[0][1] for b in beats[1:]]
        starts_ends = []
        for i in range(len(beats)):
            end = min(starts[i + 1] + XFADE, total) if i + 1 < len(beats) \
                else total
            starts_ends.append((starts[i], end))

    scenes, photo_i, motion_i = [], 0, 0
    for i, (start, end) in enumerate(starts_ends):
        # Odd slots try b-roll (photo opens the video, hero first). If the
        # photo pool is empty every slot tries b-roll.
        want_broll = (i % 2 == 1) or not pool
        broll_path = fetcher.clip_for(end - start) if want_broll else None
        if broll_path:
            scenes.append({"start": start, "end": end, "type": "broll",
                           "path": broll_path, "motion": "video",
                           "textish": False})
            continue
        if not pool:
            raise ValueError("no usable photos and no b-roll for a scene")
        entry = pool[photo_i % len(pool)]
        photo_i += 1
        scenes.append({"start": start, "end": end, "type": "photo",
                       "path": entry["path"],
                       "motion": "contain" if entry["textish"]
                       else motions[motion_i % len(motions)],
                       "textish": entry["textish"]})
        if not entry["textish"]:
            motion_i += 1
    return scenes


def _wrap_text(text, font_path, size, max_w):
    """Greedy word-wrap to fit `max_w` at `size`, returning newline-joined text.
    Mirrors Turbo's approach of pre-wrapping before rendering with method=label,
    which sidesteps MoviePy 2.x `caption` size quirks."""
    from PIL import ImageFont

    try:
        font = ImageFont.truetype(font_path, size)
    except Exception:  # noqa: BLE001
        return text
    lines, cur = [], ""
    for word in text.split():
        cand = f"{cur} {word}".strip()
        l, _, r, _ = font.getbbox(cand)
        if (r - l) <= max_w or not cur:
            cur = cand
        else:
            lines.append(cur)
            cur = word
    if cur:
        lines.append(cur)
    return "\n".join(lines)


def hook_clip(text, start, end, font_path):
    """The oversized HOOK card over the first ~2s (kept from v1): TextClip with
    pre-wrapped text, slide-up + CrossFadeIn."""
    from moviepy import TextClip, vfx

    text = text.strip()
    if not text:
        return None
    render_text = _wrap_text(text, font_path, HOOK_FONT, int(W * 0.86))
    stroke = max(4, int(HOOK_FONT * 0.06))
    tc = TextClip(
        text=render_text, font=font_path, font_size=HOOK_FONT, color="#FFFFFF",
        stroke_color="#000000", stroke_width=stroke, method="label",
        text_align="center",
    )
    dur = max(end - start, 0.05)
    tc = tc.with_start(start).with_end(start + dur)
    x_center = (W - tc.w) / 2.0
    base_y = H * 0.34

    def _pos(t):
        dy = -20.0 * max(0.0, 1.0 - (t / 0.14))   # slide up over first 0.14s
        return (x_center, base_y + dy)

    tc = tc.with_position(_pos)
    fade = min(0.08, dur / 2.0)
    if fade > 0:
        tc = tc.with_effects([vfx.CrossFadeIn(fade)])
    return tc


def _chunk_words(beat_words):
    """Split one beat's words into caption chunks of 2-3 words (a leftover
    group of 4 becomes 2+2 so no chunk gets overlong)."""
    chunks, i, n = [], 0, len(beat_words)
    while i < n:
        rem = n - i
        take = CHUNK_MAX_WORDS
        if rem == 4:
            take = 2
        elif rem < CHUNK_MAX_WORDS:
            take = rem
        chunks.append(beat_words[i:i + take])
        i += take
    return chunks


def render_chunk_frame(words, hot_idx, font_path):
    """Render one caption state as an RGBA array: the whole 2-3 word chunk on
    one line, every word white with a black stroke EXCEPT the currently spoken
    word which is slightly larger and in the brand accent. Baselines aligned."""
    from PIL import ImageDraw, ImageFont

    words = [w.upper() for w in words]
    max_w = int(W * 0.88)
    measurer = ImageDraw.Draw(Image.new("RGB", (8, 8)))

    scale = 1.0
    fonts, widths, gap, total = [], [], 0, 0
    for _ in range(4):
        sizes = [
            max(20, int(round(CHUNK_FONT * scale
                              * (HOT_SCALE if i == hot_idx else 1.0))))
            for i in range(len(words))
        ]
        fonts = [ImageFont.truetype(font_path, s) for s in sizes]
        gap = max(8, int(CHUNK_FONT * scale * 0.30))
        widths = [int(math.ceil(measurer.textlength(w, font=f)))
                  for w, f in zip(words, fonts)]
        total = sum(widths) + gap * (len(words) - 1)
        if total <= max_w:
            break
        scale *= (max_w / float(total)) * 0.97

    metrics = [f.getmetrics() for f in fonts]
    asc = max(m[0] for m in metrics)
    desc = max(m[1] for m in metrics)
    stroke = max(3, int(CHUNK_FONT * scale * 0.07))
    pad = stroke + 6
    canvas = Image.new("RGBA", (total + 2 * pad, asc + desc + 2 * pad),
                       (0, 0, 0, 0))
    draw = ImageDraw.Draw(canvas)
    x = pad
    for i, (word, font) in enumerate(zip(words, fonts)):
        a = font.getmetrics()[0]
        y = pad + (asc - a)                 # equal baselines across sizes
        color = ACCENT if i == hot_idx else "#FFFFFF"
        draw.text((x, y), word, font=font, fill=color,
                  stroke_width=stroke, stroke_fill="#000000")
        x += widths[i] + gap
    return np.array(canvas)


def chunk_caption_clips(beats, hook_end, duration, font_path):
    """Word-pop captions: for every chunk, one ImageClip per word-state (the
    spoken word accent-colored + larger). Each state runs from its word's
    start to the next word's start; the chunk's last state holds until the
    next chunk begins (captions never flicker off during speech pauses); the
    final state rides out to the end of the audio (v1 behaviour)."""
    from moviepy import ImageClip

    chunks = []
    for beat in beats:
        body = [wt for wt in beat if wt[1] >= hook_end - 1e-3]
        if body:
            chunks.extend(_chunk_words(body))
    clips = []
    for ci, chunk in enumerate(chunks):
        if ci + 1 < len(chunks):
            chunk_end = chunks[ci + 1][0][1]
        else:
            chunk_end = max(duration, chunk[-1][2])
        chunk_words = [wt[0] for wt in chunk]
        for k, (_, ws, _we) in enumerate(chunk):
            st = ws
            en = chunk[k + 1][1] if k + 1 < len(chunk) else chunk_end
            en = max(en, st + 0.05)
            try:
                arr = render_chunk_frame(chunk_words, k, font_path)
            except Exception as exc:  # noqa: BLE001
                log.warning("caption render failed (%s); skipped state", exc)
                continue
            ic = ImageClip(arr, transparent=True)
            ic = ic.with_start(st).with_end(en).with_position(
                ((W - arr.shape[1]) / 2.0,
                 CAPTION_CENTER_Y - arr.shape[0] / 2.0))
            clips.append(ic)
    return clips


# ============================================================================
# Background music (optional, deterministic, non-fatal)
# ============================================================================
def pick_bgm(page_id):
    """Deterministically pick a track from BGM_DIR by page_id hash. The folder
    must contain ONLY CC0/royalty-free .mp3 files. Missing/empty -> None."""
    try:
        files = sorted(glob.glob(os.path.join(BGM_DIR, "*.mp3")))
        if not files:
            return None
        idx = int(hashlib.md5(str(page_id).encode()).hexdigest(), 16) % len(files)
        log.info("bgm: %s (%d candidate(s))", files[idx], len(files))
        return files[idx]
    except Exception as exc:  # noqa: BLE001
        log.warning("bgm selection failed (%s); staying silent", exc)
        return None


# ============================================================================
# Main composition
# ============================================================================
def compose_video(pool, broll_terms, mp3_path, hook, script, word_timings,
                  duration, font_path, out_path, bgm_path=None):
    from moviepy import AudioFileClip, CompositeVideoClip, afx

    total = duration + TAIL_SECONDS

    # --- scenes: sentence beats -> photo/b-roll alternating full-frame cuts ---
    beats = split_beats(script, word_timings)
    fetcher = BrollFetcher(broll_terms)
    scenes = plan_scenes(beats, pool, fetcher, total)
    n_broll = sum(1 for sc in scenes if sc["type"] == "broll")
    log.info("scene plan: %d scene(s) (%d b-roll), pool=%d",
             len(scenes), n_broll, len(pool))
    for i, sc in enumerate(scenes):
        log.info("  scene %d: %.2f-%.2fs type=%s motion=%s visual=%s", i + 1,
                 sc["start"], sc["end"], sc["type"], sc["motion"],
                 os.path.basename(sc["path"]))

    layers, open_sources = [], []
    for sc in scenes:
        if sc["type"] == "broll":
            clip, src = broll_scene_clip(sc["path"], sc["start"], sc["end"])
            open_sources.append(src)     # must stay open until after encode
            layers.append(clip)
        elif sc["textish"]:
            layers.append(contain_scene_clip(sc["path"], sc["start"],
                                             sc["end"]))
        else:
            layers.append(scene_clip(sc["path"], sc["start"], sc["end"],
                                     sc["motion"]))
    layers.append(make_scrim(total))

    # --- hook window (v1 logic kept) ---
    hook_words = [w for w in hook.split() if w.strip()]
    n_hook = len(hook_words)
    if word_timings and len(word_timings) >= n_hook >= 1:
        hook_end = word_timings[n_hook - 1][2]
    else:
        hook_end = min(2.4, duration * 0.16)
    hook_end = max(1.2, min(hook_end, 3.2))

    hc = hook_clip(hook.upper(), 0.0, hook_end, font_path)
    if hc is not None:
        layers.append(hc)

    # --- word-pop chunk captions after the hook ---
    layers.extend(chunk_caption_clips(beats, hook_end, duration, font_path))

    video = CompositeVideoClip(layers, size=(W, H)).with_duration(total)

    # --- audio: voice + optional quiet BGM (Turbo's generate_video recipe) ---
    audio = AudioFileClip(mp3_path).with_effects([afx.MultiplyVolume(VOICE_VOLUME)])
    if bgm_path:
        try:
            from moviepy import CompositeAudioClip
            bgm = AudioFileClip(bgm_path).with_effects([
                afx.MultiplyVolume(BGM_VOLUME),
                afx.AudioLoop(duration=total),
                afx.AudioFadeIn(0.5),
                afx.AudioFadeOut(0.5),
            ])
            audio = CompositeAudioClip([audio, bgm])
        except Exception as exc:  # noqa: BLE001
            log.warning("bgm mix failed (%s); voice only", exc)
    video = video.with_audio(audio)

    tmp = out_path + ".tmp.mp4"
    # Turbo's encode settings: libx264 + aac + 192k. faststart added on remux.
    video.write_videofile(
        tmp,
        fps=FPS,
        codec="libx264",
        audio_codec="aac",
        audio_bitrate="192k",
        preset="medium",
        threads=int(os.environ.get("VIDEO_THREADS", "2")),
        ffmpeg_params=["-pix_fmt", "yuv420p"],
        temp_audiofile=os.path.join(WORKDIR, "temp-audio.m4a"),
        logger=None,
    )
    try:
        video.close()
        audio.close()
    except Exception:  # noqa: BLE001
        pass
    for src in open_sources:             # release b-roll readers post-encode
        try:
            src.close()
        except Exception:  # noqa: BLE001
            pass

    _faststart_remux(tmp, out_path)
    try:
        os.remove(tmp)
    except OSError:
        pass
    return out_path


def _ffmpeg_bin():
    import shutil
    env = os.environ.get("IMAGEIO_FFMPEG_EXE")
    if env and os.path.exists(env):          # ignore a stale/wrong env path (run #1 bug)
        return env
    which = shutil.which("ffmpeg")
    if which:
        return which
    try:                                      # last resort: imageio-ffmpeg's bundled binary
        import imageio_ffmpeg
        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:
        return "ffmpeg"


def _faststart_remux(src, dst):
    """Guarantee a web-streamable MP4: relocate the moov atom to the front."""
    cmd = [_ffmpeg_bin(), "-y", "-i", src, "-c", "copy",
           "-movflags", "+faststart", dst]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0 or not os.path.exists(dst):
        log.warning("faststart remux failed (%s); falling back to raw output",
                    (r.stderr or "").strip()[:300])
        os.replace(src, dst)


# ============================================================================
# v3: Gemini vision judge — "the brain that can see" the finished video.
# Unavailability (no key / API down / bad JSON) is NON-fatal: skip + deliver.
# A NEGATIVE VERDICT is fatal for this page's run: no delivery, no done-mark,
# so the next cron retries with a freshly-varied render.
# ============================================================================
class JudgeRejected(RuntimeError):
    """The vision judge failed the video: do NOT deliver, do NOT mark done."""


_JUDGE_PROMPT = """You are a strict visual QA judge for 9:16 vertical short-form social videos.
You are given {n} evenly spaced frames from ONE rendered video.
The video's hook/title is: "{hook}"

FAIL the video if ANY of these is true:
1. READABILITY: any on-screen text (the hook, captions, or text inside an image) is cut off mid-word or mid-letter, cropped by the frame edge, or zoomed/mangled into unreadable fragments. The big 1-3 word styled captions are intentional and fine; text visibly amputated by cropping is NOT fine.
2. FRAMING: a human face is badly cropped (eyes or forehead sliced by the frame edge, face half outside the frame).
3. VARIETY: all frames look essentially identical — a static image with only caption changes, no visual variety between frames.

Minor blur, film grain, compression artifacts, or a darkened background are acceptable. Judge ONLY the three criteria above.

Respond with ONLY this JSON object, no markdown fences, no extra text:
{{"pass": true, "issues": [], "scores": {{"readability": 0, "framing": 0, "variety": 0}}}}
where pass is true/false, issues is a list of short problem descriptions (empty when passing), and each score is an integer 0-10."""


def _extract_judge_frames(mp4_path, total_s, n=JUDGE_FRAMES):
    """Grab n evenly-spaced jpeg frames (frame i at (2i+1)/2n of the runtime)
    downscaled to 540px wide — small payload, still legible for the judge."""
    ff = _ffmpeg_bin()
    paths = []
    for i in range(n):
        t = max(0.0, total_s * (2 * i + 1) / (2.0 * n))
        p = os.path.join(WORKDIR, f"judge-{i}.jpg")
        cmd = [ff, "-y", "-ss", f"{t:.2f}", "-i", mp4_path,
               "-frames:v", "1", "-q:v", "4", "-vf", "scale=540:-2", p]
        r = subprocess.run(cmd, capture_output=True, text=True)
        if r.returncode == 0 and os.path.exists(p) and os.path.getsize(p) > 1000:
            paths.append(p)
        else:
            log.warning("judge frame %d extraction failed", i)
    return paths


def vision_judge(mp4_path, hook, title, total_s):
    """One gemini-2.5-flash generateContent call (native REST, inline_data
    jpegs, response_mime_type=application/json). Returns the verdict dict or
    None when the judge is unavailable — the caller only blocks delivery on
    an explicit pass=false."""
    if not GEMINI_API_KEY:
        log.info("GEMINI_API_KEY not set; skipping vision judge")
        return None
    import base64
    try:
        frames = _extract_judge_frames(mp4_path, total_s)
        if len(frames) < 2:
            log.warning("too few judge frames (%d); skipping judge",
                        len(frames))
            return None
        prompt = _JUDGE_PROMPT.format(
            n=len(frames), hook=(hook or title or "").replace('"', "'")[:200])
        parts = [{"text": prompt}]
        for p in frames:
            with open(p, "rb") as fh:
                parts.append({"inline_data": {
                    "mime_type": "image/jpeg",
                    "data": base64.b64encode(fh.read()).decode("ascii")}})
        body = {"contents": [{"parts": parts}],
                "generationConfig": {"temperature": 0.0,
                                     "response_mime_type": "application/json"}}
        url = ("https://generativelanguage.googleapis.com/v1beta/models/"
               f"{GEMINI_MODEL}:generateContent?key={GEMINI_API_KEY}")
        r = requests.post(url, json=body, timeout=90)
        if r.status_code != 200:
            log.warning("judge HTTP %d (%s); skipping judge",
                        r.status_code, r.text[:200])
            return None
        text = (r.json()["candidates"][0]["content"]["parts"][0]["text"]
                or "").strip()
        if text.startswith("```"):       # belt-and-suspenders fence strip
            text = text.strip("`").strip()
            if text.lower().startswith("json"):
                text = text[4:].strip()
        verdict = json.loads(text)
        if not isinstance(verdict, dict) or "pass" not in verdict:
            log.warning("judge returned unusable JSON; skipping judge")
            return None
        log.info("vision judge: pass=%s scores=%s issues=%s",
                 verdict.get("pass"), verdict.get("scores"),
                 verdict.get("issues"))
        return verdict
    except Exception as exc:  # noqa: BLE001
        log.warning("vision judge unavailable (%s); delivering unjudged", exc)
        return None


# ============================================================================
# Feed I/O + dedup
# ============================================================================
def read_done():
    ids = []
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line.isdigit():
                    ids.append(line)
    return ids


def append_done(page_id):
    os.makedirs(os.path.dirname(STATE_FILE) or ".", exist_ok=True)
    with open(STATE_FILE, "a", encoding="utf-8") as f:
        f.write(f"{page_id}\n")


def _get_json(url, params):
    qs = "?" + "&".join(f"{k}={requests.utils.quote(str(v))}" for k, v in params.items())
    last = None
    for attempt in range(1, 5):
        # engine 1: curl_cffi browser TLS (dodges the fingerprint block)
        try:
            from curl_cffi import requests as cffi
            r = cffi.get(url + qs, impersonate="firefox", timeout=45,
                         headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200:
                return r.json()
            last = f"curl_cffi HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        # engine 2: requests with a browser UA
        try:
            r = requests.get(url, params=params, timeout=45,
                             headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200:
                return r.json()
            last = f"requests HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        log.warning("fetch attempt %d/4 failed (%s); retrying", attempt, last)
        time.sleep(5 * attempt)
    raise RuntimeError(f"fetch_next failed after retries: {last}")


def fetch_next(done_ids):
    data = _get_json(NEXT_URL, {"token": INGEST_TOKEN, "done": ",".join(done_ids)})
    return data.get("post")


def post_video(page_id, slug, mp4_path):
    # Deliver as base64-in-JSON, the image-engine's proven daily-working pattern.
    # Hostinger's WAF 403-blocks multipart file uploads from datacenter IPs (run #4)
    # but passes JSON POSTs (scraper + image engine deliver this way every day).
    import base64
    with open(mp4_path, "rb") as fh:
        b64 = base64.b64encode(fh.read()).decode("ascii")
    body = {"token": INGEST_TOKEN, "page_id": int(page_id),
            "slug": slug or "", "video_b64": b64}
    log.info("delivering %s (%.1f MB as base64)", os.path.basename(mp4_path),
             len(b64) / 1024 / 1024)
    last = None
    for attempt in range(1, 5):
        # engine 1: curl_cffi browser TLS (the pattern that dodges the WAF)
        try:
            from curl_cffi import requests as cffi
            r = cffi.post(RECEIVE_URL, json=body, impersonate="firefox",
                          timeout=300, headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.json().get("ok"):
                log.info("posted video for page_id=%s", page_id)
                return
            last = f"curl_cffi HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        # engine 2: requests JSON
        try:
            r = requests.post(RECEIVE_URL, json=body, timeout=300,
                              headers={"User-Agent": _BROWSER_UA})
            ok = r.status_code == 200
            try:
                ok = ok and bool(r.json().get("ok", ok))
            except Exception:  # noqa: BLE001
                pass
            if ok:
                log.info("posted video for page_id=%s", page_id)
                return
            last = f"requests HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        log.warning("post attempt %d/4 failed (%s); retrying", attempt, last)
        time.sleep(10 * attempt)
    raise RuntimeError(f"receive failed after retries: {last}")


# ============================================================================
# Main
# ============================================================================
def make_one(post, font_path):
    page_id = int(post["page_id"])
    slug = post.get("slug", "")
    hook = (post.get("hook") or "").strip()
    script = (post.get("script") or "").strip()
    if not script:
        raise ValueError(f"post {page_id} missing script")
    if not hook:
        hook = " ".join(script.split()[:8])

    os.makedirs(WORKDIR, exist_ok=True)
    pool = build_visual_pool(post, page_id)
    broll_terms = post.get("broll") if isinstance(post.get("broll"), list) \
        else []
    if not pool and not (broll_terms and (PEXELS_API_KEY or PIXABAY_API_KEY)):
        raise ValueError(f"post {page_id}: no usable visuals at all")

    mp3 = os.path.join(WORKDIR, f"voice-{page_id}.mp3")
    timings, duration = synthesize(script, mp3)

    out = os.path.join(WORKDIR, f"video-{page_id}.mp4")
    compose_video(pool, broll_terms, mp3, hook, script, timings, duration,
                  font_path, out, bgm_path=pick_bgm(page_id))

    # v3: the vision judge sees the FINISHED (faststart-remuxed) artifact.
    verdict = vision_judge(out, hook, post.get("title", ""),
                           duration + TAIL_SECONDS)
    if verdict is not None and verdict.get("pass") is not True:
        raise JudgeRejected(
            f"vision judge rejected page {page_id}: "
            f"issues={verdict.get('issues')} scores={verdict.get('scores')}")

    post_video(page_id, slug, out)
    append_done(page_id)


def main():
    if not INGEST_TOKEN:
        log.error("INGEST_TOKEN not set")
        return 2

    font_path = resolve_font()
    made = 0
    for _ in range(VIDEO_BATCH):
        done = read_done()
        try:
            post = fetch_next(done)
        except Exception as exc:  # noqa: BLE001
            log.error("fetch_next failed: %s", exc)
            return 1
        if not post:
            log.info("no more posts to process")
            break
        log.info("processing page_id=%s slug=%s", post.get("page_id"),
                 post.get("slug"))
        try:
            make_one(post, font_path)
            made += 1
        except Exception as exc:  # noqa: BLE001
            log.error("failed to make video for %s: %s", post.get("page_id"), exc)
            traceback.print_exc()
            # Do NOT mark done on failure — it will be retried next run.
            return 1
    log.info("done. made %d video(s)", made)
    return 0


if __name__ == "__main__":
    sys.exit(main())
