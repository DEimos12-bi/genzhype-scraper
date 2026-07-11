#!/usr/bin/env python3
"""
GenZHype faceless-video maker.

Adapted from the open-source MoneyPrinterTurbo (MPT) engine
(https://github.com/harry0703/MoneyPrinterTurbo, MIT). This driver pulls a
token-gated JSON feed from genzhype.com, renders ONE hero image into a 9:16
captioned MP4 (Ken-Burns zoom + dark scrim + edge-tts narration + word-by-word
captions driven by edge-tts WordBoundary timings), and POSTs the artifact back.

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
  * REPLACE -> Turbo's `video.combine_videos` / `video.generate_video` are
              stock-clip oriented (download N clips, concat, phrase-SRT overlay).
              For a single still image we write a clean Ken-Burns compositor here,
              reusing Turbo's MoviePy 2.x idioms (ImageClip.resized(lambda t),
              CompositeVideoClip, TextClip(text=..., font=path), with_start/
              with_end/with_position) and its encode settings (libx264 + aac +
              192k), then add `-movflags +faststart` on a final remux.

Runtime target: GitHub Actions ubuntu-latest (ffmpeg + fonts preinstalled).
"""

import html
import logging
import os
import subprocess
import sys
import time
import traceback

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

VOICE = os.environ.get("VIDEO_VOICE", "en-US-AriaNeural")
VOICE_RATE = float(os.environ.get("VIDEO_VOICE_RATE", "1.05"))
VOICE_VOLUME = float(os.environ.get("VIDEO_VOICE_VOLUME", "1.0"))
VIDEO_BATCH = int(os.environ.get("VIDEO_BATCH", "1"))

W, H = 1080, 1920
FPS = int(os.environ.get("VIDEO_FPS", "30"))
KEN_BURNS_ZOOM = 0.12          # zoom-in amount across the whole clip
HOOK_FONT = 96
WORD_FONT = 104
TAIL_SECONDS = 0.45            # small pad so the last word/audio is not clipped
TTS_OUTER_RETRIES = 4          # outer retries around the whole TTS call (403 risk)

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
# Image + composition helpers
# ============================================================================
def download_image(url, dest):
    # same multi-engine + browser-UA treatment as fetch_next: this URL is on
    # genzhype.com too, so Hostinger's TLS bot-block can hit it identically.
    last = None
    for attempt in range(1, 4):
        try:
            from curl_cffi import requests as cffi
            r = cffi.get(url, impersonate="firefox", timeout=60,
                         headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.content:
                with open(dest, "wb") as f:
                    f.write(r.content)
                return dest
            last = f"curl_cffi HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        try:
            r = requests.get(url, timeout=60, headers={"User-Agent": _BROWSER_UA})
            r.raise_for_status()
            with open(dest, "wb") as f:
                f.write(r.content)
            return dest
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        time.sleep(4 * attempt)
    raise RuntimeError(f"image download failed: {last}")


def cover_fit(pil_img, tw, th):
    """Scale to COVER (tw, th) and center-crop — fills the 9:16 frame, no bars."""
    pil_img = pil_img.convert("RGB")
    w, h = pil_img.size
    scale = max(tw / w, th / h)
    nw, nh = max(tw, int(round(w * scale))), max(th, int(round(h * scale)))
    pil_img = pil_img.resize((nw, nh), Image.Resampling.LANCZOS)
    left, top = (nw - tw) // 2, (nh - th) // 2
    return pil_img.crop((left, top, left + tw, top + th))


def make_scrim(duration):
    """Vertical dark gradient: darker at top (hook) and bottom (captions),
    lighter in the middle so the hero photo still reads."""
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


def ken_burns(image_path, duration):
    from moviepy import CompositeVideoClip, ImageClip

    pil = cover_fit(Image.open(image_path), W, H)
    base = ImageClip(np.array(pil)).with_duration(duration)
    # Slow zoom-in from 1.0 -> 1.0+KEN_BURNS_ZOOM; composite crops the overflow.
    zoomed = base.resized(
        lambda t: 1.0 + KEN_BURNS_ZOOM * (t / max(duration, 0.001))
    ).with_position("center")
    return CompositeVideoClip([zoomed], size=(W, H)).with_duration(duration)


def _fit_font_size(text, base_size, max_w, font_path):
    """Shrink font until `text` fits `max_w` (guards very long single words)."""
    from PIL import ImageFont

    size = base_size
    while size > 34:
        try:
            font = ImageFont.truetype(font_path, size)
            l, _, r, _ = font.getbbox(text)
            if (r - l) <= max_w:
                return size
        except Exception:  # noqa: BLE001
            return size
        size -= 4
    return size


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


def caption_clip(text, start, end, base_y, base_size, font_path,
                 color="#FFFFFF", wrap=False, rise=14.0):
    """One animated caption. `rise` gives a short slide-up-in animation and we
    add a quick CrossFadeIn — cheap 'animated' feel without scale-anchor math.
    Always renders with method='label' (text pre-wrapped when needed)."""
    from moviepy import TextClip, vfx

    text = text.strip()
    if not text:
        return None

    if wrap:
        size = base_size
        render_text = _wrap_text(text, font_path, size, int(W * 0.86))
    else:
        size = _fit_font_size(text, base_size, int(W * 0.90), font_path)
        render_text = text
    stroke = max(4, int(size * 0.06))

    tc = TextClip(
        text=render_text, font=font_path, font_size=size, color=color,
        stroke_color="#000000", stroke_width=stroke, method="label",
        text_align="center",
    )

    dur = max(end - start, 0.05)
    tc = tc.with_start(start).with_end(start + dur)
    x_center = (W - tc.w) / 2.0

    def _pos(t):
        dy = -rise * max(0.0, 1.0 - (t / 0.14))  # slide up over first 0.14s
        return (x_center, base_y + dy)

    tc = tc.with_position(_pos)
    fade = min(0.08, dur / 2.0)
    if fade > 0:
        tc = tc.with_effects([vfx.CrossFadeIn(fade)])
    return tc


def compose_video(image_path, mp3_path, hook, word_timings, duration, font_path,
                  out_path):
    from moviepy import AudioFileClip, CompositeVideoClip, afx

    total = duration + TAIL_SECONDS
    layers = [ken_burns(image_path, total), make_scrim(total)]

    # Hook window = end of the last hook word (else ~fraction of audio), clamped.
    hook_words = [w for w in hook.split() if w.strip()]
    n_hook = len(hook_words)
    if word_timings and len(word_timings) >= n_hook >= 1:
        hook_end = word_timings[n_hook - 1][2]
    else:
        hook_end = min(2.4, duration * 0.16)
    hook_end = max(1.2, min(hook_end, 3.2))

    hook_clip = caption_clip(
        hook.upper(), 0.0, hook_end, base_y=H * 0.34, base_size=HOOK_FONT,
        font_path=font_path, wrap=True, rise=20.0,
    )
    if hook_clip is not None:
        layers.append(hook_clip)

    # Word-by-word captions for everything after the hook window.
    body = [wt for wt in word_timings if wt[1] >= hook_end - 1e-3]
    for i, (word, s, e) in enumerate(body):
        if i == len(body) - 1:
            e = max(e, duration)          # let the final word ride out the audio
        clip = caption_clip(
            word.upper(), s, e, base_y=H * 0.64, base_size=WORD_FONT,
            font_path=font_path, wrap=False,
        )
        if clip is not None:
            layers.append(clip)

    video = CompositeVideoClip(layers, size=(W, H)).with_duration(total)
    audio = AudioFileClip(mp3_path).with_effects([afx.MultiplyVolume(VOICE_VOLUME)])
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


# Hostinger's bot protection TLS-fingerprint-blocks datacenter Python intermittently
# (the scraper-v7 lesson; it 403'd run #3 from the GH runner while the same URL was 200
# from elsewhere). Cure = the proven multi-engine pattern: browser-TLS via curl_cffi
# first, then requests, then urllib — with retries and a browser UA.
_BROWSER_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) "
               "Gecko/20100101 Firefox/130.0")


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
    fname = f"{slug or 'video'}-{page_id}.mp4"
    data = {"token": INGEST_TOKEN, "page_id": str(page_id), "slug": slug or ""}
    last = None
    for attempt in range(1, 5):
        # engine 1: curl_cffi browser TLS
        try:
            from curl_cffi import requests as cffi
            with open(mp4_path, "rb") as fh:
                r = cffi.post(RECEIVE_URL, data=data,
                              files={"file": (fname, fh.read(), "video/mp4")},
                              timeout=300, headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.json().get("ok"):
                log.info("posted video for page_id=%s", page_id)
                return
            last = f"curl_cffi HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        # engine 2: requests
        try:
            with open(mp4_path, "rb") as fh:
                r = requests.post(RECEIVE_URL, data=data,
                                  files={"file": (fname, fh, "video/mp4")},
                                  timeout=300, headers={"User-Agent": _BROWSER_UA})
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
    image_url = post.get("image", "")
    if not script or not image_url:
        raise ValueError(f"post {page_id} missing script/image")
    if not hook:
        hook = " ".join(script.split()[:8])

    os.makedirs(WORKDIR, exist_ok=True)
    img = download_image(image_url, os.path.join(WORKDIR, f"hero-{page_id}"))
    mp3 = os.path.join(WORKDIR, f"voice-{page_id}.mp3")
    timings, duration = synthesize(script, mp3)

    out = os.path.join(WORKDIR, f"video-{page_id}.mp4")
    compose_video(img, mp3, hook, timings, duration, font_path, out)
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
