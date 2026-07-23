#!/usr/bin/env python3
"""
GenZHype faceless-video maker — v6 "human-editor taste" (person-pinned
photos + real event images + face-aware phone framing).

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
     card, event YouTube thumbnails + v8: the site's stored per-drama images).
     `post.people` arrives as [{"name","photo"}] (v8): a feed-provided photo
     (server-resolved via the site's arsenal — entity QIDs, verified creator
     photos, YouTube channel avatars) is that person's FIRST choice; names
     without one (or plain-string people, the old shape) are resolved via
     Wikidata (wbsearchentities -> P18 -> commons Special:FilePath), the
     proven image_engine.py flow;
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

WHAT v4 ADDS over v3 (owner verdict on v3: "clips side by side; a shot dragged
after its word passed; zero sound design = feels dead/beginner". Spec:
videorepos/V4-EDITOR-SPEC.md — the researched editor law-book):
  1. EDL EXECUTION (vertical editing, Laws 3/4/9) — the feed now sends
     `post.shotlist`: a Director-authored shot list anchored by WORD INDEX
     into `script.split()`. The maker maps token index -> milliseconds using
     the TTS WordBoundary timings (1:1 when counts match, the proven
     proportional fallback otherwise) and renders each shot from
     `word[w_in].start - 300ms` (visual leads audio, Law 9; clamped monotonic,
     first shot at 0) to the next shot's t_in. Every shot dies with its
     phrase. `shot_class: subject` -> next photo from the REAL-photo pool
     (hero/person/receipt — never stock); `broll` -> Pexels/Pixabay clip for
     `shot.query`; a failed b-roll fetch falls back to a subject photo (never
     black, never a crash). Motions: punch_hit (snap 1.0->1.12 in ~3 frames AT
     the emphasis word, then hold), punch_build (ease 1.0->1.10 across the
     shot), zoom_out (1.12->1.0), pan_left/right (v3 pans). Identical motion
     never repeats back-to-back (guarded even though the Director promises).
     HARD CUTS between shots (Law 7 — the v3 0.15s crossfade is gone inside
     the sequence; a tiny fade remains only on video start/end).
     `shotlist` null/malformed -> the entire v3 beat/alternation path runs.
  2. SOUND ENGINE (Laws 12-19, the missing half) — pydub mix built BEFORE the
     video encode: VO normalized to -16 dBFS; music bed picked
     deterministically from .social/bgm (md5 of page_id), looped, at -18dB vs
     VO, 0.5s master fades; per-shot music states (`bed` / `silence` = bed
     fully out from 300ms before the shot, back with the next shot's impact /
     `duck` = extra -4dB); SFX from .social/sfx by filename prefix
     (whoosh_*/riser_*/impact_*/pop_*): whoosh & impact at the shot's t_in,
     pop at the emphasis word, riser trimmed to its last <=3s and ending
     EXACTLY at the NEXT shot's t_in; all SFX >=6dB below VO, variants
     rotated by shot-index hash; 30ms fades at every music seam (Law 19).
     LOUDNESS: the mixed track is gain-normalized in pydub toward -14 dBFS
     average (approx -14 LUFS) with a -1.5 dBFS peak cap, then attached to
     the video — chosen over an ffmpeg loudnorm pass because it needs no
     second encode. Missing folders/files or ANY mix failure -> the v3
     voice+bgm path runs instead (never fatal).
  3. HOUSE GRADE (Law 22) — one look over every visual so mixed sources feel
     like one shoot: vectorized numpy grade (teal-lifted shadows +6% blue,
     warmed highlights +4% red, 1.06 contrast, 1.05 saturation) applied ONCE
     per photo array and per-frame on b-roll, plus a cached radial vignette
     (corners to ~0.85) composited as a single static overlay layer.
  4. JUDGE: one added criterion — consecutive sampled frames must show
     varied, story-relevant visuals (not near-identical).

WHAT v4.5 ADDS over v4 (owner verdict on v4: narration said "MrBeast's
assistant" while a generic stock clip showed a random assistant dressing an
actor — every SPECIFIC fact must show its REAL visual. Stock is already
demoted server-side; this adds the evidence layer):
  1. RECEIPT SHOTS — the feed now sends `post.receipts`: PNG "evidence cards"
     (1080x1350, one per dated event, rendered server-side from the REAL
     event text + source). The Director may emit shot_class 'receipt' with
     `receipt_i` (index into post.receipts). The maker downloads the cards
     and renders a receipt shot through the PROVEN text-heavy CONTAIN path
     (whole card visible over a blurred fill, gentle <=2% drift, NEVER
     crop/zoom — the systemic fix from v3 applies unchanged). The receipt is
     on screen exactly while its fact is spoken: the drama-genre trust move.
  2. RECEIPT SLAM — a receipt shot whose Director sfx is 'none' defaults to a
     'pop' at its t_in (V4 spec Law 15: message-pop on every receipt is the
     genre signature). These default pops are budget-exempt: they replace
     visual variety, they don't compete with the 3-5 story-beat SFX.
  3. A-ROLL ACCOUNTING — receipts count as A-roll: they reset the
     consecutive-b-roll counter (new defensive cap: never >2 stock clips in a
     row even if a malformed shotlist asks for it).
  4. FALLBACK LADDER — missing receipts array / failed card download ->
     subject photo (never black, never a crash). No shotlist -> v3 path.

WHAT v5 ADDS over v4.5 (owner round-4 verdict: a BLM-protest stock clip played
over "fans demanding accountability" on an unrelated story — keyword stock
search has NO story understanding):
  1. VISION RE-RANK OF STOCK (the Kapwing move, V4-EDITOR-SPEC.md Law 24) —
     for a b-roll shot we no longer download the first keyword hit. The stock
     search now keeps each candidate's PREVIEW IMAGE (Pexels returns a video
     'image' thumbnail; Pixabay per-rendition 'thumbnail'); up to 5 candidate
     thumbnails + the shot's exact narration phrase + the story title go to
     gemini-2.5-flash in ONE call, which picks the candidate that matches
     WHAT IS BEING SAID and rejects any unsafe/mismatched frame (protests,
     flags, religious/political imagery, children, medical, misreadable human
     context). Only the chosen candidate's video is downloaded; best=-1 ->
     subject photo. Verdicts are cached PER QUERY so shots sharing a query
     share one call; hard cap ~8 vision calls/video (free-tier quota is
     shared with the site). GEMINI_API_KEY absent or VIDEO_VISION_RERANK=0
     -> exact v4.5 behaviour (first candidate). ALL failures non-fatal ->
     first candidate, or the usual subject-photo fallback.
  2. REAL-POST CARDS (server-side) — post.receipts now also carries tweet-
     style cards of the REAL posts (text/author/@handle parsed verbatim from
     the stored X embeds). Nothing changes here beyond the receipts cap: the
     cards flow through the same receipt_i -> contain-render path.

WHAT v6 ADDS over v5 (owner round-6 verdict: the Director lacks a human
editor's taste — a named person spoken must show THAT person's real photo on
those words; a big event must show its real image; and framing must respect
the phone screen):
  1. PERSON -> PHOTO — Director shots may carry "person": "<exact name>".
     The Wikidata person-photo fetch (which already runs) now keeps a
     name -> pool-entry map; a person shot renders THAT person's photo on
     exactly those words. Missing/failed photo -> the hero/subject pool
     round-robin, exactly as before (never a crash).
  2. visual_i -> REAL EVENT IMAGE — shots may carry "visual_i": n, an index
     into the feed's visuals[] (hero cover + event YouTube thumbnails; the
     feed also sends aligned visual_titles[] for logging). The shot then
     shows that REAL story image. Entries already in the pool are reused;
     missing ones are fetched on demand; any failure -> pool fallback.
  3. FACE-AWARE PHONE FRAMING — opencv-python-headless haarcascade
     frontal-face detection on every PHOTO scene (cached per image). The
     cover-crop is chosen so the largest face's eyeline sits ~40% from the
     top of the 1080x1920 frame (upper-third rule), the face stays out of
     the top-220px/bottom-320px platform UI zones AND above the caption
     band (face bottom <= 55% of frame height). Ken-Burns/punch motions are
     ANCHORED on the face point (the image scales around the eyeline, so
     zoom drift can never push the face out of the safe zone); pans on
     face photos become face-anchored zooms. No face / no cv2 -> the exact
     v5 center-crop behaviour.
  4. PROMO CARD — post.receipts now ends with the single branded GenZHype
     promo card (kind order server-side: events, posts, promo). It arrives
     as a receipt index like any card and renders through the same CONTAIN
     path; the Director/validator guarantee it only rides the final CTA
     shot. Receipts download cap raised 16 -> 20 so the last card (the
     promo) is never truncated off the list.

WHAT r11 ADDS (owner round-11 verdict: "more images and persons; more
intelligent shots — it keeps showing the same image again and again,
sometimes while talking about something else"; a 17-shot video ran on a
3-image pool):
  1. FLOODED POOL — the server now sends up to 24 visuals (per-person recent
     channel thumbnails + multiple og:images) and people carry
     "photos": [urls] PLURAL. MAX_POOL raised 8 -> 16 (env-overridable).
  2. PERSON VARIETY — person_map values are now LISTS of that person's pool
     entries (avatar first, then recent thumbnails); consecutive shots of the
     same person cycle their images instead of freezing on the avatar.
  3. LRU SMART FALLBACK — unpinned subject shots pick the LEAST-RECENTLY-USED
     pool image outside a 3-scene no-repeat window (replaces blind
     round-robin; the old adjacent-duplicate guard is subsumed).
  Server-side the same round adds the Director laws: every subject shot must
  pin person or visual_i, and a deterministic no-repeat validator (a
  visual_i never twice in any 4 consecutive shots, max 3 uses per video).

WHAT r12 ADDS (owner: "any topic, however complicated — the video looks
NORMAL the whole runtime, nothing weird ever appears" + close the
produced-energy gap):
  1. NORMALITY JUDGE — the Gemini judge now samples 10-12 evenly-spaced
     frames (env VIDEO_JUDGE_FRAMES, still 540px, still ONE call) and runs a
     WEIRDNESS CHECKLIST (cut text, sliced face, same image in 3+ frames,
     dead/blank frames, context-mismatched stock, caption-on-card collisions).
     Verdict JSON gains "weird": [{frame, issue}]. Pass/fail semantics and
     the JudgeRejected flow are unchanged; no key -> skipped (non-fatal).
  2. PRE-ENCODE SELFCHECK (no AI, runs before the encode): (a) no scene
     reuses an image path within a 3-scene window — the r11 guard is now
     enforced across pinned person/visual_i shots, receipts AND b-roll
     (plan_scenes_edl holes closed), and a violation HARD-FAILS the run
     (SelfCheckFailed -> no delivery, retry next run; window relaxes when
     the total asset count is smaller than the window); (b) scene durations
     < 0.8s and (c) caption coverage < 80% of speech are logged as warnings
     only. One SELFCHECK log line carries all results.
  3. BEAT-CHANGE TRANSITIONS — shots the Director marked sfx='whoosh'
     (story-beat changes) get a fast produced transition instead of a bare
     hard cut: a 3-frame horizontal whip-blur slide and a fast cross-zoom
     punch (pure numpy/PIL, no new deps — the xfade-easing idea ported, not
     its ffmpeg code), rotating variants, max 3 per video. Everywhere else
     stays hard cuts. Any failure -> the hard cut we already had.
  4. PATTERN INTERRUPT (dormant until curated) — if .social/hooks/ holds
     LICENSED mp4 clips (see ADAPTATION.md), ONE 0.7-1.2s cover-cropped
     interrupt clip is spliced as an overlay at the Director's riser-shot
     start (the mid-video re-hook trap) with an impact SFX, rotated per
     page_id. Empty/missing folder -> feature off. EDL timing untouched.
  5. EXPRESSIVE NARRATION — the script is synthesized in up to 3 edge-tts
     segments (hook sentence rate +12% & pitch +2Hz; body at base rate;
     GenZHype CTA line rate -4%), concatenated sample-accurately with pydub;
     word-timing offsets are shifted by each segment's REAL audio length and
     asserted monotonic. Any structural doubt, cue mismatch >10%, or concat
     failure -> the proven single-pass synthesize() (captions sync is
     sacred). Kill switch: VIDEO_EXPRESSIVE_TTS=0.

WHAT r13 ADDS (owner-approved REAL FOOTAGE — the standard drama-channel
fair-use posture: short MUTED excerpts of the actual videos being discussed,
transformed under our commentary/cards/captions):
  1. Story visuals that are YouTube thumbnails (i.ytimg.com/vi/<id>/...) can
     be UPGRADED from a still to a short muted clip of that exact video:
     yt-dlp downloads ONLY a 14s section (12s-26s, skipping intros) at
     <=720p, 25s timeout per attempt, android player_client retry, cached
     per id per run. The scene shows <=3.5s of it (starting 2s into the
     window), cover-cropped/graded/scrimmed through the existing
     broll_scene_clip path with motion=punch_build.
  2. HARD BUDGETS (the fair-use guardrails): max 3 footage scenes per video,
     max ~8 borrowed seconds total, never two footage scenes consecutive,
     footage counts as b-roll for the max-2-in-a-row rule, always muted.
  3. NEVER FATAL: yt-dlp missing, YouTube bot-walling the runner IP, a
     short/broken file — every miss falls back to the thumbnail still (the
     exact pre-r13 behaviour) with a loud FOOTAGE log line either way.
     Kill switch: VIDEO_REAL_FOOTAGE=0.

WHAT r14 ADDS (owner: "the director doesn't really SEE what's going on" —
sight at both ends; the server side is the seeing pass in visual_sight.php):
  1. CLIP VERIFYING EYE (render-time, quota-free): sentence-transformers
     CLIP ViT-B-32 runs on the runner CPU after plan_scenes_edl resolved the
     photo scenes. Each plain photo scene's image is scored against its
     shot's exact narration phrase (cosine); a clear mismatch (< 0.18) is
     SWAPPED to the best pool alternative that beats it by >= 0.06, still
     respecting the no-repeat window; person-pinned and text-heavy scenes
     are never touched (the person law and contain path outrank CLIP).
     Encode budget ~40 images/video (pool encoded once, embeddings reused).
     Model/install missing -> silently skipped. Kill: VIDEO_CLIP_VERIFY=0.
  2. SIGHT FLAGS: the feed's visual_flags[] (aligned with visuals[]; from
     the server's Gemini seeing pass, which actually LOOKED at each image)
     override the filename/aspect is_text_heavy heuristic for pool entries —
     sight beats filename guessing. Absent flags -> old heuristic.

WHAT r17 ADDS (owner round-17: clips are a PLANNED Director decision; the
evidence chain drops the beige cards and the raw-screenshot ad-grabs):
  1. PLANNED CLIPS — Director shots may carry "clip": true on a visual_i
     pinned to a YouTube thumbnail: an explicit order to play the real muted
     clip of that described moment there. Planned ids are prefetched FIRST
     (the yt-dlp attempt cap serves the plan before any opportunistic
     upgrade), a planned scene may run up to 4.5s, and when any planned clip
     exists the budget rises to 4 footage scenes / 12 borrowed seconds —
     opportunistic upgrades only fill what upcoming planned scenes won't
     need. Muting, cover-crop, never-two-consecutive, the b-roll chain rule
     and VIDEO_REAL_FOOTAGE=0 all stay exactly as r13 shipped them.
  2. BEIGE CARDS RETIRED — event receipts now arrive as metadata only
     (url=''; the server renders no event PNG and prunes the old ones). The
     evidence chain per event: clean article screenshot > the article's real
     og:image photo (receipt_meta.og_image; rendered as a NORMAL cover-crop
     face-aware photo scene — it IS the real moment photo) > subject photo.
     A beige card can never be chosen because none exists; any stale event
     card from an old feed is dropped before resolution. X post cards and
     the branded promo card are unchanged.
  3. SCREENSHOT HARDENING — ad/newsletter/subscribe/sponsor furniture is
     visibility-hidden before the shot, and the headline block is REQUIRED:
     no h1 -> NO screenshot for that URL (the raw top-of-page fallback that
     grabbed ads/page furniture is dead).
  4. JUDGE — new weirdness criterion (g): a proof/screenshot frame cluttered
     with website ads, cookie banners, subscribe boxes or unrelated page
     furniture fails the video.

WHAT r24 ADDS (owner round-24: FOOTAGE-FIRST — after 23 rounds the videos
still read as slideshows, because YouTube bot-walls anonymous cloud
downloads and the r13/r17 footage engine almost never actually fired):
  1. COOKIES UNLOCK — the workflow writes the YT_COOKIES secret (cookies.txt
     of a logged-in secondary account) to <WORKDIR>/yt_cookies.txt; when the
     file exists and is >100 bytes, every yt-dlp call runs with --cookies
     and reliably succeeds. Logged once as "footage: cookies active".
     Cookies absent = the EXACT pre-r24 behavior; VIDEO_REAL_FOOTAGE=0
     still kills the whole feature either way.
  2. MULTI-WINDOW FETCH (cookies only) — each story video id serves up to 3
     DIFFERENT 16s sections (early/middle/late; each window its own cached
     attempt/file foot-<id>-w<k>.mp4; a video shorter than a window just
     fails that window), so ONE story video yields up to 3 distinct moving
     scenes instead of 1.
  3. BUDGETS FLIP (cookies only) — planned Director clips up to 5.0s,
     opportunistic scenes up to 4.5s, max 8 footage scenes per video, total
     borrowed capped at min(30s, 60% of runtime). Consecutive footage
     scenes are now ALLOWED, but never the same (id, window) file twice in
     a row (window/id rotation); footage still counts as b-roll for the
     max-2-in-a-row rule (so stills remain the accents) and every footage
     file still respects the 3-scene no-repeat window.
  4. STILL-HOLD LIMIT (always on, cookies or not) — the SAME still image may
     carry at most 2 CONSECUTIVE scenes (pins included): a 3rd consecutive
     hold swaps to the LRU pool alternative, else tries a footage window,
     else stays with a loud log. This kills the "5s single-visual opener".
  5. ACCOUNT SAFETY — yt-dlp attempts capped per run (12 with cookies, 6
     without) with a 2-4s sleep between spawns when cookies are active.
     Muting, cover-crop, grade, judge and selfcheck are untouched.

PROVEN v1 PARTS KEPT VERBATIM: the multi-engine fetch/post (curl_cffi
browser-TLS first — Hostinger's TLS fingerprint block), base64-in-JSON video
delivery (WAF blocks multipart), edge-tts synthesis with WordBoundary timings
and 403 retries, the ffmpeg resolution chain (_ffmpeg_bin), the dedup state
file and the faststart remux. Also kept whole from v3: visual pool +
text-heavy guard, BrollFetcher budgets, captions, hook, Gemini judge.

Runtime target: GitHub Actions ubuntu-latest (ffmpeg + fonts preinstalled).
"""

import glob
import hashlib
import html
import json
import logging
import math
import os
import random
import re
import subprocess
import sys
import time
import traceback
import urllib.parse

import numpy as np
import requests

# r18 FORCE IPv4 (run #79 post-mortem): genzhype.com now publishes AAAA (IPv6)
# records, and GitHub runners frequently have NO IPv6 route — Python's requests/
# urllib then dial the IPv6 address and die with [Errno 101] Network is
# unreachable after multi-minute hangs. Filter IPv6 out of ALL Python name
# resolution. (curl_cffi is unaffected AND safe: libcurl races v4/v6 itself and
# falls back to IPv4 fast.) Kill switch: VIDEO_FORCE_IPV4=0.
import socket as _socket
if os.environ.get("VIDEO_FORCE_IPV4", "1") != "0":
    _orig_getaddrinfo = _socket.getaddrinfo

    def _v4_getaddrinfo(host, port, family=0, *args, **kwargs):
        res = _orig_getaddrinfo(host, port, family, *args, **kwargs)
        v4 = [r for r in res if r[0] == _socket.AF_INET]
        return v4 or res
    _socket.getaddrinfo = _v4_getaddrinfo


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
SCENE_ZOOM = 0.16              # r25 motion-lite: stronger, clearly-visible push
                               # (footage is bot-walled on free cloud, so the
                               # LIFE has to come from real camera movement on
                               # the real stills — was 0.10, too timid = frozen)
PAN_SCALE = 1.24               # oversize factor that creates room for pans
                               # (r25: more travel so pans actually read)
XFADE = float(os.environ.get("VIDEO_XFADE", "0.15"))   # 0 -> hard cuts

# --- v2: captions ---
ACCENT = "#FF6A5C"             # GenZHype brand accent — the spoken word pops in it
CHUNK_FONT = int(os.environ.get("VIDEO_CHUNK_FONT", "88"))
HOT_SCALE = 1.18               # spoken word renders this much larger
CHUNK_MAX_WORDS = 3
SAFE_TOP = 220                 # platform UI safe areas (nothing rendered inside)
SAFE_BOTTOM = 320
CAPTION_CENTER_Y = int(H * 0.62)   # lower-middle band, well inside the safe area
# v9 (owner round-9): on CARD scenes (receipt/post/promo) captions must never sit
# on the card's own text. The card anchors top (y=240, below the 220px UI zone)
# and is capped so its bottom lands <=1350; captions on those scenes drop to the
# cleared band below it, centered here (band ~1420-1540, above the y1600 bottom UI).
CARD_TOP_Y       = 190     # r25: cards (screenshots/posts) start higher and
CARD_MAX_BOTTOM  = 1440     # extend lower so a real proof FILLS the phone
CARD_CAPTION_Y   = 1500     # (owner: "not fitting the phone"); caption band
                            # sits just below the enlarged card, no overlap

# --- v2: people photos (Wikidata, image_engine.py's proven flow) ---
# r11: 8 -> 16. The server now floods the feed with real story imagery
# (per-person recent channel thumbnails, multiple og:images, people photos
# PLURAL); an 8-image cap would throw most of it away and the owner's verdict
# was exactly "same image again and again" on a 3-image pool.
MAX_POOL = int(os.environ.get("VIDEO_MAX_POOL", "16"))
PEOPLE_BUDGET_S = 100          # hard wall-clock cap on all person lookups
POOL_NO_REPEAT_WINDOW = 3      # r11: an image never reappears within 3 scenes

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
TEXTISH_DRIFT = 0.045               # r25 motion-lite: cards (screenshots, X
                                    # posts) were nearly frozen at 0.02 — the
                                    # owner paused on exactly these. More drift.
CARD_ZOOM = 0.07                    # r25: gentle push-in on cards so they are
                                    # ALIVE, not static — still fully readable
TEXTISH_FLAT_FRAC = 0.55            # top-4 quantized colors must cover >= this

# --- v3: Gemini vision judge (the "brain that can see") ---
GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY", "").strip()
GEMINI_MODEL = os.environ.get("GEMINI_MODEL", "gemini-2.5-flash")
# r12: 4 -> 12 sampled frames (the normality floor needs runtime coverage,
# not spot checks). Still 540px jpegs, still ONE generateContent call.
JUDGE_FRAMES = int(os.environ.get("VIDEO_JUDGE_FRAMES", "12"))

# --- r16 CLOSED LOOP: said-vs-seen enforcement + re-plan trigger ---
# When the judge sees >=2 clear frame<->words mismatches, the maker asks the
# server to NULL the shotlist (the cron re-directs it) instead of re-rendering
# the same bad plan. Counts per page live in .social/video_replans.txt
# ("page_id count" lines, committed like video_done.txt); at REPLAN_CAP the
# video is delivered anyway with a loud log — the loop is never infinite.
REPLAN_FILE = os.environ.get("VIDEO_REPLAN_FILE", ".social/video_replans.txt")
REPLAN_CAP = int(os.environ.get("VIDEO_REPLAN_CAP", "3"))

# r16: the judge pairs sampled frames with the EDL shot phrases spoken under
# them; compose_video parks its final EDL here for make_one to pass along.
LAST_EDL = None

# --- r12: pre-encode selfcheck (no AI; SELFCHECK log line every run) ---
SELFCHECK_MIN_SHOT_S = 0.8     # scenes shorter than this are logged (warn only)
CAPTION_COVERAGE_MIN = 0.80    # captions must cover >=80% of speech (warn only)

# --- r12: beat-change transitions (whoosh shots only; max 3/video) ---
TRANSITIONS_ON = os.environ.get("VIDEO_TRANSITIONS", "1").strip() != "0"
TRANSITION_MAX = 3             # produced transitions per video, hard cap
TRANSITION_WHIP_FRAMES = 3     # horizontal whip-blur slide length (frames)
TRANSITION_ZOOM_FRAMES = 6     # cross-zoom punch length (frames)

# --- r12: pattern-interrupt clip pool (dormant while the folder is empty).
# LICENSED clips only — curation rules documented in videorepos/ADAPTATION.md.
HOOKS_DIR = os.environ.get("VIDEO_HOOKS_DIR", ".social/hooks")
INTERRUPT_MIN_S = 0.7
INTERRUPT_MAX_S = 1.2

# --- r12: expressive narration (segmented edge-tts; fallback = single pass) ---
EXPRESSIVE_TTS = os.environ.get("VIDEO_EXPRESSIVE_TTS", "1").strip() != "0"
EXPR_HOOK_RATE = 1.12          # hook sentence: urgency
EXPR_HOOK_PITCH = "+2Hz"       # only passed when edge-tts supports pitch=
EXPR_CTA_RATE = 0.96           # final CTA line: landing
EXPR_CUE_TOLERANCE = 0.10      # >10% word-cue mismatch -> single-pass fallback

# --- r18 GRAFT A: FORCED ALIGNMENT (measure the REAL audio, not edge-tts's
# self-reported WordBoundary cues + r12 concat offsets — the source of the
# owner's "narration is late" audio-visual drift). whisperx aligns the KNOWN
# transcript (== the script) against the rendered mp3 on CPU; only if the
# measurement is provably at least as trustworthy as the edge timings (caption
# sync is sacred) does it replace them for BOTH captions and the EDL. Any
# failure (import / model-download / empty / gate) -> keep edge timings.
FORCED_ALIGN = os.environ.get("VIDEO_FORCED_ALIGN", "1").strip() != "0"
FORCED_ALIGN_COVERAGE = 0.70   # measured words must difflib-match >=70% of tokens
FORCED_ALIGN_DUR_TOL = 0.5     # measured total span within ~0.5s of audio length

# --- v5: vision re-rank of stock candidates (Law 24, the Kapwing move) ---
# Whole step skippable (quota lever): VIDEO_VISION_RERANK=0 -> v4.5 behaviour.
VISION_RERANK = os.environ.get("VIDEO_VISION_RERANK", "1").strip() != "0"
VISION_MAX_CALLS = int(os.environ.get("VIDEO_VISION_MAX_CALLS", "8"))
VISION_CANDIDATES = 5          # thumbnails per call (4-6 band from the spec)
VISION_THUMB_TIMEOUT = 10      # seconds per thumbnail download

# --- r14: CLIP verifying eye (quota-free render-time image<->phrase check) ---
# sentence-transformers 'clip-ViT-B-32' (~605MB, downloads at first use on the
# runner; any import/download failure -> the whole check silently skips).
CLIP_VERIFY = os.environ.get("VIDEO_CLIP_VERIFY", "1").strip() != "0"
CLIP_SWAP_MIN = float(os.environ.get("VIDEO_CLIP_SWAP_MIN", "0.18"))
CLIP_SWAP_MARGIN = float(os.environ.get("VIDEO_CLIP_SWAP_MARGIN", "0.06"))
CLIP_MAX_ENCODES = 40          # image encodings per video (pool encoded once)

# --- v4: EDL execution (V4-EDITOR-SPEC.md Laws 3/4/6/7/9) ---
VISUAL_LEAD_S = float(os.environ.get("VIDEO_VISUAL_LEAD_S", "0.30"))  # Law 9
MIN_SHOT_S = 0.35              # degenerate shots absorb into the previous one
PUNCH_HIT_SCALE = 1.17         # Law 6: snap-zoom target (r25: punchier)
PUNCH_HIT_FRAMES = 3           # snap duration in frames (~0.1s at 30fps)
PUNCH_BUILD_SCALE = 1.17       # eased 1.0->1.17 across the shot (r25: was 1.10,
                               # too gentle — motion-lite needs visible push)
EDGE_FADE_S = 0.15             # tiny fade on video START/END only (hard cuts inside)

# --- v4: sound engine (Laws 12-19) ---
SFX_DIR = os.environ.get("VIDEO_SFX_DIR", ".social/sfx")
VO_TARGET_DBFS = -16.0         # VO normalization anchor before the final pass
BED_DB_VS_VO = -18.0           # Law 13: music bed sits -18dB under the voice
DUCK_EXTRA_DB = -4.0           # 'duck' music state: extra reduction
WHOOSH_DB_VS_VO = -6.0         # Law 14: ~50-60% of VO, floor 6dB below
IMPACT_DB_VS_VO = -6.0
POP_DB_VS_VO = -8.0
RISER_DB_VS_VO = -8.0
RISER_MAX_S = 3.0              # risers keep their LAST <=3s (they peak at the end)
SILENCE_LEAD_S = 0.30          # music cut this much BEFORE a 'silence' shot
SEAM_FADE_MS = 30              # Law 19: fade at every music seam (click kill)
BED_MASTER_FADE_MS = 500       # 0.5s fade in/out on the whole bed
MIX_TARGET_DBFS = -14.0        # final loudness anchor (approx -14 LUFS)
MIX_TRUE_PEAK_DBFS = -1.5      # peak ceiling

# --- v6: face-aware phone framing (owner round-6: respect the phone screen) ---
FACE_FRAMING = os.environ.get("VIDEO_FACE_FRAMING", "1").strip() != "0"
EYELINE_FRAC = 0.40            # eyeline ~38-42% from frame top (upper third)
FACE_TOP_MIN = SAFE_TOP + 20   # face never inside the top platform-UI zone
FACE_BOTTOM_MAX = int(H * 0.55)  # face never under the caption band / bottom UI
FACE_DETECT_MAX_SIDE = 640     # detection runs on a downscaled copy (speed)

# --- r13: REAL FOOTAGE (owner-approved drama-channel fair-use posture) ---
# A story visual that is a YouTube thumbnail (i.ytimg.com/vi/<id>/...) may be
# upgraded from a still to a short MUTED clip of that exact video via yt-dlp.
# Everything here is a fair-use guardrail; every failure path keeps the
# thumbnail still (pre-r13 behaviour). Kill switch: VIDEO_REAL_FOOTAGE=0.
REAL_FOOTAGE = os.environ.get("VIDEO_REAL_FOOTAGE", "1").strip() != "0"
FOOTAGE_MAX_SCENES = 3         # max upgraded scenes per video
FOOTAGE_MAX_TOTAL_S = 8.0      # max borrowed seconds per video
FOOTAGE_SCENE_MAX_S = 3.5      # longer beats keep their thumbnail still
FOOTAGE_SECTION = "*00:00:12-00:00:26"  # fetch a 14s window, skipping intros
FOOTAGE_SUB_OFF_S = 2.0        # show the sub-segment starting 2s into it
FOOTAGE_FETCH_TIMEOUT = 25     # seconds per yt-dlp attempt
FOOTAGE_MAX_FETCHES = 6        # run-level attempt cap (bot-walled runners)

# --- r17: PLANNED CLIPS (Director-ordered footage — "clip": true shots).
# A planned clip is a DECISION, not a lucky upgrade: its video id is
# prefetched before any opportunistic fetch, it may run up to 4.5s, and when
# any planned clip exists in the video the whole footage budget rises to
# 4 scenes / 12 borrowed seconds (opportunistic upgrades fill leftovers only).
FOOTAGE_PLANNED_SCENE_MAX_S = 4.5
FOOTAGE_PLANNED_MAX_SCENES = 4
FOOTAGE_PLANNED_MAX_TOTAL_S = 12.0

# --- r24: FOOTAGE-FIRST (the cookies unlock). The workflow writes the
# YT_COOKIES secret (a logged-in secondary account's cookies.txt) to
# <WORKDIR>/yt_cookies.txt; with it yt-dlp survives YouTube's cloud-IP bot
# wall, so REAL FOOTAGE can finally carry the video and stills become the
# accents. EVERYTHING below applies ONLY when that file exists (>100 bytes);
# cookie-less runs keep the exact r13/r17 budgets above.
FOOTAGE_WINDOWS_CK = [             # up to 3 DIFFERENT sections per video id
    "*00:00:10-00:00:26",          # early (post-intro)
    "*00:00:40-00:00:56",          # middle
    "*00:01:20-00:01:36",          # late (too-short video -> window fails)
]
FOOTAGE_CK_SCENE_MAX_S = 4.5       # opportunistic per-scene cap w/ cookies
FOOTAGE_CK_PLANNED_SCENE_MAX_S = 5.0   # planned Director clips w/ cookies
# r25 FOOTAGE-FIRST DOMINANCE (owner: "the gaps between clips are dead frozen
# stills"): raised so real footage carries the majority of a footage-rich
# story instead of a still every third scene. The GAP-FILL path (below) turns
# would-be frozen/repeat stills into motion by borrowing any of the story's
# own video windows, so these ceilings are what let it actually fill the gaps.
FOOTAGE_CK_MAX_SCENES = 12         # max footage scenes per video w/ cookies
FOOTAGE_CK_MAX_TOTAL_S = 40.0      # borrowed-seconds cap w/ cookies ...
FOOTAGE_CK_MAX_TOTAL_FRAC = 0.70   # ... or 70% of runtime (smaller wins)
FOOTAGE_CK_MAX_FETCHES = 18        # yt-dlp attempts/run (burner safety)
FOOTAGE_CK_MAX_CONSEC = 4          # r25: footage may run up to 4 scenes before
                                   # a still accent (real story footage is NOT
                                   # the generic-stock 2-in-a-row cap; that cap
                                   # still bounds stock b-roll separately)
FOOTAGE_FETCH_SLEEP_S = (2.0, 4.0)  # polite sleep between spawns w/ cookies

# --- v4: house grade (Law 22 — one look over every visual) ---
GRADE_CONTRAST = 1.06
GRADE_SATURATION = 1.05
GRADE_TEAL_SHADOWS = 0.06      # +6% blue lift in darks
GRADE_WARM_HIGHLIGHTS = 0.04   # +4% red lift in highlights
VIGNETTE_EDGE = 0.85           # corner brightness multiplier

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


def _make_communicate(text, voice_name, rate_str, pitch_str=None):
    """Port of voice.create_edge_tts_communicate: only pass boundary= on
    edge_tts versions whose Communicate accepts it (7.x). r12: pitch= is
    passed the same signature-probed way (expressive hook segment only)."""
    import inspect
    import edge_tts

    kwargs = {"rate": rate_str}
    try:
        sig = inspect.signature(edge_tts.Communicate)
        if "boundary" in sig.parameters:
            kwargs["boundary"] = "WordBoundary"
        if pitch_str and "pitch" in sig.parameters:
            kwargs["pitch"] = pitch_str
    except (TypeError, ValueError):
        pass
    return edge_tts.Communicate(text, voice_name, **kwargs)


def _edge_tts_synthesize(text, voice_name, rate_str, out_mp3, pitch_str=None):
    """Compact port of voice.azure_tts_v1: stream edge-tts audio to disk and
    feed WordBoundary/SentenceBoundary events into a SubMaker (returns cues)."""
    import edge_tts

    communicate = _make_communicate(text, voice_name, rate_str, pitch_str)
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


# ============================================================================
# r12: EXPRESSIVE NARRATION — up to 3 edge-tts segments (hook faster+brighter,
# body at base rate, CTA slower) concatenated with pydub; every word timing is
# offset by the PREVIOUS segments' real audio length (frame-count accurate).
# Captions sync is sacred: ANY doubt -> None and the caller runs the proven
# single-pass synthesize().
# ============================================================================
def _expressive_plan(script, hook_rate=EXPR_HOOK_RATE, hook_pitch=EXPR_HOOK_PITCH):
    """Split the script into (text, rate_mult, pitch) segments on sentence
    boundaries: [hook sentence, body, CTA line]. Returns a list of 2-3
    segments, or None when the structure isn't clearly there.
    NOTE: the Director's mid re-hook stretch (+8%) is deliberately NOT split
    out — it would need 4-5 segments; the spec caps us at 3."""
    tokens = [w for w in script.split() if w.strip()]
    if len(tokens) < 12:
        return None
    ends = [i for i, w in enumerate(tokens) if _is_sentence_end(w)]
    if not ends:
        return None
    hook_end = ends[0]                       # first sentence = the spoken hook
    if not 2 <= hook_end <= 24 or hook_end >= len(tokens) - 6:
        return None
    segs = [(" ".join(tokens[:hook_end + 1]), hook_rate, hook_pitch)]
    body_start = hook_end + 1
    # CTA = the last sentence, only when it is the GenZHype CTA line.
    cta_start = None
    if len(ends) >= 2 and ends[-1] == len(tokens) - 1:
        cand = ends[-2] + 1
        if cand > body_start + 3:
            cta_text = " ".join(tokens[cand:])
            if "genzhype" in cta_text.lower() and len(tokens) - cand >= 3:
                cta_start = cand
    body_stop = cta_start if cta_start is not None else len(tokens)
    if body_stop > body_start:
        segs.append((" ".join(tokens[body_start:body_stop]), 1.0, None))
    if cta_start is not None:
        segs.append((" ".join(tokens[cta_start:]), EXPR_CTA_RATE, None))
    return segs if len(segs) >= 2 else None


def synthesize_expressive(script, out_mp3, grave=False):
    """Segmented synthesis. Returns (word_timings, duration) or None -> the
    caller MUST fall back to synthesize(). Verifications before accepting:
    every segment produced cues; total cue count within EXPR_CUE_TOLERANCE of
    the script token count; offsets strictly monotonic; concatenated file
    duration ~= sum of the segment durations.
    r16 GRAVITY: a grave story halves the hook's rate boost (urgency reads as
    glee on a tragedy) and drops the pitch lift."""
    if not EXPRESSIVE_TTS:
        return None
    hook_rate = 1.0 + (EXPR_HOOK_RATE - 1.0) / 2.0 if grave else EXPR_HOOK_RATE
    hook_pitch = None if grave else EXPR_HOOK_PITCH
    plan = _expressive_plan(script, hook_rate=hook_rate, hook_pitch=hook_pitch)
    if not plan:
        log.info("expressive TTS: script structure unclear; single-pass")
        return None
    try:
        from pydub import AudioSegment
        AudioSegment.converter = _ffmpeg_bin()

        pieces, timings, offset = [], [], 0.0
        for si, (text, mult, pitch) in enumerate(plan):
            rate_str = _convert_rate_to_percent(VOICE_RATE * mult)
            seg_mp3 = f"{out_mp3}.seg{si}.mp3"
            sub, last_err = None, None
            for attempt in (1, 2):           # light retry; heavy retry lives
                try:                         # in the single-pass fallback
                    sub = _edge_tts_synthesize(text, VOICE, rate_str, seg_mp3,
                                               pitch_str=pitch)
                    break
                except Exception as exc:  # noqa: BLE001
                    last_err = exc
                    time.sleep(3 * attempt)
            if sub is None:
                raise RuntimeError(f"segment {si} TTS failed: {last_err}")
            seg_t = _explode_multiword(_cues_to_word_timings(sub))
            if not seg_t:
                raise RuntimeError(f"segment {si} returned no word cues")
            audio = AudioSegment.from_file(seg_mp3)
            seg_dur = audio.frame_count() / float(audio.frame_rate)
            if seg_dur <= 0 or seg_t[-1][2] > seg_dur + 0.6:
                raise RuntimeError(f"segment {si} cues overrun its audio")
            pieces.append(audio)
            for w, s, e in seg_t:
                timings.append((w, s + offset, e + offset))
            offset += seg_dur
            log.info("expressive TTS: segment %d/%d rate=%s pitch=%s "
                     "%.2fs %d cue(s)", si + 1, len(plan), rate_str,
                     pitch or "-", seg_dur, len(seg_t))

        n_tok = len([w for w in script.split() if w.strip()])
        if abs(len(timings) - n_tok) > max(2, int(n_tok * EXPR_CUE_TOLERANCE)):
            raise RuntimeError(
                f"cue/token mismatch too large ({len(timings)} vs {n_tok})")
        for a, b in zip(timings, timings[1:]):   # sacred: monotonic starts
            if b[1] + 1e-6 < a[1]:
                raise RuntimeError("non-monotonic word timings after concat")

        full = pieces[0]
        for p in pieces[1:]:
            full += p
        full.export(out_mp3, format="mp3", bitrate="160k")
        file_dur = _audio_duration(out_mp3)
        if file_dur <= 0 or abs(file_dur - offset) > 0.25:
            raise RuntimeError(
                f"concat duration drift ({file_dur:.2f}s vs {offset:.2f}s)")
        duration = max(file_dur, timings[-1][2])
        log.info("expressive TTS ok: %d segment(s), %.2fs audio, %d word "
                 "timings", len(plan), duration, len(timings))
        return timings, duration
    except Exception as exc:  # noqa: BLE001
        log.warning("expressive TTS failed (%s); single-pass fallback "
                    "(captions sync is sacred)", exc)
        for si in range(len(plan)):
            try:
                os.remove(f"{out_mp3}.seg{si}.mp3")
            except OSError:
                pass
        return None


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
# r18 GRAFT A: FORCED ALIGNMENT
# The drift the owner sees ("narration is LATE vs what's shown") comes from
# trusting edge-tts's self-reported WordBoundary cues plus the r12 segment
# concatenation offsets. Here we MEASURE the actual rendered audio with
# whisperx and, only when the measurement passes strict sync gates, hand those
# real timings to map_tokens_to_spans / build_edl AND the captions. Torch runs
# CPU-only (the runner has no GPU): device='cpu', compute_type='int8'.
# ============================================================================
def _flatten_whisperx(result):
    """Flatten a whisperx.align() result into synthesize()'s exact shape
    [(word, start_s, end_s), ...]. Words whisperx could not time-anchor (it
    leaves start/end None) are skipped — the downstream difflib aligner
    interpolates those gaps. Never raises."""
    words = []
    if not isinstance(result, dict):
        return words

    def _harvest(items):
        for w in items or []:
            if not isinstance(w, dict):
                continue
            tok, s, e = w.get("word"), w.get("start"), w.get("end")
            if not tok or s is None or e is None:
                continue
            try:
                words.append((str(tok).strip(), float(s), float(e)))
            except (TypeError, ValueError):
                continue

    for seg in result.get("segments") or []:
        if isinstance(seg, dict):
            _harvest(seg.get("words"))
    if not words:                      # some whisperx versions flatten here
        _harvest(result.get("word_segments"))
    return words


def _whisperx_align_only(whisperx, audio, dur, script_text, device):
    """ALIGN-ONLY path: we already KNOW the transcript (== the script), so we
    hand whisperx a single segment spanning the whole clip and only the
    ~360MB wav2vec2 align model downloads — NOT the full ASR model."""
    align_model, metadata = whisperx.load_align_model(
        language_code="en", device=device)
    segments = [{"start": 0.0, "end": float(dur), "text": script_text}]
    result = whisperx.align(segments, align_model, metadata, audio, device,
                            return_char_alignments=False)
    return _flatten_whisperx(result)


def _whisperx_transcribe_align(whisperx, audio, script_text, device):
    """Heavier fallback when align-only proves unreliable in the installed
    whisperx version: transcribe with the small 'base' model (int8) then align
    the produced segments. Downloads the ASR model too (~140MB base)."""
    model = whisperx.load_model("base", device, compute_type="int8",
                                language="en")
    tr = model.transcribe(audio, batch_size=8)
    align_model, metadata = whisperx.load_align_model(
        language_code="en", device=device)
    result = whisperx.align(tr.get("segments") or [], align_model, metadata,
                            audio, device, return_char_alignments=False)
    return _flatten_whisperx(result)


def forced_align(mp3_path, script_text):
    """Measure real word timings from the rendered audio. Returns a list in
    synthesize()'s shape [(word, start_s, end_s), ...] measured from the audio,
    or None on ANY failure so the caller keeps the edge timings unchanged.
    Align-only first (light: align model only); transcribe+align fallback if
    align-only yields nothing / errors. CPU-only, int8. Never raises."""
    if not FORCED_ALIGN:
        return None
    try:
        import whisperx
    except Exception as exc:  # noqa: BLE001 — model/lib absent -> graceful
        log.info("FORCED-ALIGN unavailable; edge timings (whisperx import: %s)",
                 str(exc)[:80])
        return None
    try:
        import torch  # noqa: F401 — whisperx needs it; presence check only
    except Exception as exc:  # noqa: BLE001
        log.info("FORCED-ALIGN unavailable; edge timings (torch import: %s)",
                 str(exc)[:80])
        return None
    device = "cpu"
    try:
        audio = whisperx.load_audio(mp3_path)
    except Exception as exc:  # noqa: BLE001
        log.info("FORCED-ALIGN unavailable; edge timings (load_audio: %s)",
                 str(exc)[:80])
        return None
    try:
        dur = float(len(audio)) / 16000.0     # whisperx resamples to 16 kHz
    except Exception:  # noqa: BLE001
        dur = 0.0
    if dur <= 0:
        log.info("FORCED-ALIGN unavailable; edge timings (empty audio)")
        return None
    # (1) ALIGN-ONLY from the known transcript.
    try:
        words = _whisperx_align_only(whisperx, audio, dur, script_text, device)
        if words:
            return words
        log.info("FORCED-ALIGN: align-only yielded no words; "
                 "transcribe+align fallback")
    except Exception as exc:  # noqa: BLE001
        log.info("FORCED-ALIGN: align-only failed (%s); transcribe+align "
                 "fallback", str(exc)[:80])
    # (2) transcribe(base)+align fallback.
    try:
        words = _whisperx_transcribe_align(whisperx, audio, script_text, device)
        return words or None
    except Exception as exc:  # noqa: BLE001
        log.info("FORCED-ALIGN unavailable; edge timings "
                 "(transcribe+align: %s)", str(exc)[:100])
        return None


def _forced_align_coverage(measured, script):
    """How many script tokens the measured words cover, via the SAME r15
    difflib/_norm_word matcher map_tokens_to_spans uses. Returns
    (matched_count, n_tokens)."""
    import difflib
    tokens = [w for w in script.split() if w.strip()]
    if not tokens or not measured:
        return 0, len(tokens)
    tok_n = [_norm_word(w) for w in tokens]
    meas_n = [_norm_word(w[0]) for w in measured]
    sm = difflib.SequenceMatcher(a=tok_n, b=meas_n, autojunk=False)
    matched = sum(blk.size for blk in sm.get_matching_blocks())
    return matched, len(tokens)


def accept_forced_timings(measured, script, duration,
                          coverage_min=FORCED_ALIGN_COVERAGE,
                          dur_tol=FORCED_ALIGN_DUR_TOL):
    """CAPTION SYNC IS SACRED (r15 discipline): only replace edge timings when
    the measured ones are provably at least as trustworthy. Gates, ALL required:
      1. non-empty;
      2. starts monotonic non-decreasing, every end >= its start;
      3. difflib coverage >= coverage_min of the script tokens (unmatched are
         interpolated downstream by map_tokens_to_spans);
      4. total measured span within ~dur_tol of the real audio duration.
    Any fail -> False -> caller keeps edge timings. Never ships worse sync."""
    if not measured:
        return False
    prev_s = None                                   # gate 2: monotonic / sane
    for item in measured:
        try:
            _w, s, e = item
            s, e = float(s), float(e)
        except (TypeError, ValueError):
            return False
        if e + 1e-6 < s:
            return False
        if prev_s is not None and s + 1e-3 < prev_s:
            return False
        prev_s = s
    matched, n_tok = _forced_align_coverage(measured, script)   # gate 3
    if n_tok == 0 or matched < coverage_min * n_tok:
        log.info("FORCED-ALIGN rejected: coverage %d/%d (<%.0f%%); edge timings",
                 matched, n_tok, 100.0 * coverage_min)
        return False
    span_end = float(measured[-1][2])                # gate 4: span vs audio
    if duration > 0 and abs(span_end - duration) > dur_tol:
        log.info("FORCED-ALIGN rejected: span %.2fs vs audio %.2fs "
                 "(>%.2fs); edge timings", span_end, duration, dur_tol)
        return False
    return True


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
# ============================================================================
# v10 REAL-SOURCE SCREENSHOTS (owner round-10: evidence = original pixels)
# ============================================================================
REAL_SHOTS = os.environ.get("VIDEO_REAL_SHOTS", "1") != "0"
SHOT_TOTAL_BUDGET_S = 45.0     # wall-clock across ALL screenshots per video

# r18 GRAFT B: compact ad/tracker host blocklist — substrings matched against
# the request URL host. Network-level ABORT keeps ads/trackers/analytics from
# ever painting, so the element screenshot captures the article, not furniture.
# The article's OWN domain is never in here, so its fonts/images/scripts load
# normally. Best-effort; never fatal.
_AD_HOST_SUBSTRINGS = (
    "doubleclick", "googlesyndication", "google-analytics", "googletagmanager",
    "googletagservices", "googleadservices", "adservice", "adsystem",
    "amazon-adsystem", "adnxs", "taboola", "outbrain", "criteo",
    "scorecardresearch", "moatads", "pubmatic", "rubiconproject",
    "casalemedia", "adsafeprotected", "quantserve", "quantcount",
    "sharethrough", "teads", "connatix", "openx", "adform", "smartadserver",
    "yieldmo", "indexww", "3lift", "bidswitch", "adroll", "bluekai",
    "demdex", "krxd", "chartbeat", "parsely", "sail-horizon", "hotjar",
    "mixpanel", "segment.io", "branch.io", "onesignal", "permutive",
    "amplitude", "mparticle", "nr-data", "newrelic", "ampproject",
    "zergnet", "mgid", "revcontent", "disqus", "adsrvr", "adtech",
    "advertising", "banner",
)


def _is_ad_host(url):
    """True when the URL's host contains a blocklisted ad/tracker substring."""
    try:
        host = (urllib.parse.urlparse(url).hostname or "").lower()
    except Exception:  # noqa: BLE001
        return False
    return any(sub in host for sub in _AD_HOST_SUBSTRINGS)


def _block_ads(route):
    """Playwright route handler: abort ad/tracker requests, let the rest pass.
    Never raises — on any doubt the request is allowed to continue."""
    try:
        if _is_ad_host(route.request.url):
            route.abort()
            return
    except Exception:  # noqa: BLE001
        pass
    try:
        route.continue_()
    except Exception:  # noqa: BLE001
        pass


def _shot_is_blank(path):
    """r17 near-blank/bot-wall test reused for the element-screenshot branch:
    a near-uniform frame (std < 8) is unusable. Unreadable == unusable."""
    try:
        g = Image.open(path).convert("L").resize((64, 80))
        return float(np.asarray(g).std()) < 8.0
    except Exception:  # noqa: BLE001
        return True


def screenshot_articles(targets, page_id, topic_kw=None):
    """Screenshot REAL article pages (masthead + headline + lead image, as the
    site actually renders) — the drama-genre confidence move: FOUND evidence,
    not made evidence. ONE chromium session for all targets, hard wall-clock
    budget. r17: ad/newsletter/subscribe furniture is hidden before shooting
    and the headline block is REQUIRED — no h1, no screenshot (the raw
    top-of-page fallback is dead). r28: topic_kw = the story's keywords; the
    crop locks onto the headline that CONTAINS one (the MAIN article), so a
    'trending now' module's unrelated headline can't be shot by mistake. Every
    failure is silent; the og-photo / subject chain covers misses downstream.
    targets: {receipt_idx: url} -> returns {receipt_idx: png_path}."""
    topic_kw = topic_kw or []
    out = {}
    try:
        from playwright.sync_api import sync_playwright
    except Exception:
        log.info("playwright not installed; og-photo/subject chain only")
        return out
    deadline = time.time() + SHOT_TOTAL_BUDGET_S
    try:
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=True)
            ctx = browser.new_context(viewport={"width": 1080, "height": 1500},
                                      user_agent=_BROWSER_UA,
                                      locale="en-US")
            url_shot = {}                  # r22: SAME url -> SAME file (path-
            for i, url in targets.items():  # based scene caps finally bite)
                if url in url_shot:
                    if url_shot[url]:
                        out[i] = url_shot[url]
                    continue
                if time.time() > deadline:
                    log.info("screenshot budget spent; %d article(s) fall "
                             "back to og photos/subject",
                             len(targets) - len(out))
                    break
                path = os.path.join(WORKDIR, f"shot-{page_id}-{i}.png")
                try:
                    page = ctx.new_page()
                    # r18 GRAFT B: network ad-block — abort ad/tracker requests
                    # BEFORE navigation so no ad/analytics furniture ever paints.
                    try:
                        page.route("**/*", _block_ads)
                    except Exception:  # noqa: BLE001 — best-effort
                        pass
                    page.goto(url, wait_until="domcontentloaded", timeout=15000)
                    page.wait_for_timeout(1500)
                    # best-effort cookie-banner dismissal
                    for sel in ("#onetrust-accept-btn-handler",
                                "button[id*='accept' i]",
                                "button[class*='accept' i]",
                                "[aria-label*='accept' i]"):
                        try:
                            page.locator(sel).first.click(timeout=700)
                            page.wait_for_timeout(300)
                            break
                        except Exception:
                            pass
                    # hide sticky overlays below the masthead (keep top nav)
                    try:
                        page.evaluate("""() => {
                          for (const el of document.querySelectorAll('*')) {
                            const s = getComputedStyle(el);
                            if ((s.position === 'fixed' || s.position === 'sticky')
                                && el.getBoundingClientRect().top > 150) {
                              el.style.visibility = 'hidden';
                            }
                          }
                        }""")
                    except Exception:
                        pass
                    # r17 AD-KILL (owner: article shots grabbed ads/page
                    # furniture): hide ad/sponsor/newsletter/subscribe
                    # furniture before shooting. Best-effort per selector.
                    try:
                        page.evaluate("""() => {
                          const sels = ['iframe', '[id*="ad-" i]',
                                        '[id^="ad" i]', '[class*="advert" i]',
                                        '[class*="sponsor" i]',
                                        '[class*="promo-" i]',
                                        '[class*="newsletter" i]',
                                        '[class*="subscribe" i]',
                                        '[aria-label*="advertisement" i]'];
                          for (const sel of sels) {
                            let els = [];
                            try { els = document.querySelectorAll(sel); }
                            catch (e) { continue; }
                            for (const el of els) {
                              try { el.style.visibility = 'hidden'; }
                              catch (e) {}
                            }
                          }
                        }""")
                    except Exception:
                        pass
                    # r18 GRAFT B DOM ISOLATION: screenshot the MAIN ARTICLE
                    # NODE itself (element-level), not a fixed viewport clip.
                    # Fallback chain: element -> r15 headline crop -> None
                    # (NEVER a raw full-page top clip; NEVER beige).
                    shot_done = False
                    try:
                        art_loc, art_name = None, None
                        # r25 (owner: "I can see the ads in the screenshot, bad
                        # cutting not fitting the phone"): the whole-article-node
                        # capture over-grabbed the newsletter / subscribe /
                        # related-stories furniture that sits just BELOW the lead
                        # image. Disabled — the tight masthead+headline+lead-image
                        # crop below is the clean "shot a person takes" and now
                        # always wins (og/subject covers any article with no h1).
                        for sel in ():
                            try:
                                loc = page.locator(sel).first
                                if loc.count() > 0:
                                    bb = loc.bounding_box()
                                    if (bb and bb.get("height", 0) > 300
                                            and bb.get("width", 0) > 200):
                                        art_loc, art_name = loc, sel
                                        break
                            except Exception:  # noqa: BLE001
                                continue
                        if art_loc is not None:
                            try:
                                art_loc.scroll_into_view_if_needed(timeout=1500)
                            except Exception:  # noqa: BLE001
                                pass
                            art_loc.screenshot(path=path, timeout=8000)
                            im = Image.open(path).convert("RGB")
                            # r20 (seen with my own eyes on the filmstrip): a
                            # w*3-tall screenshot contain-fits into a TINY
                            # unreadable sliver. Evidence must be READABLE:
                            # crop to the headline block — max 1.25x width
                            # (~4:5), which fills the card frame legibly.
                            if im.height > im.width * 1.25:
                                im = im.crop((0, 0, im.width,
                                              int(im.width * 1.25)))
                            # normalize to 1080 wide: downscale wide, pad narrow
                            if im.width > 1080:
                                r = 1080.0 / im.width
                                im = im.resize((1080, max(1, int(im.height * r))),
                                               Image.Resampling.LANCZOS)
                            elif im.width < 1080:
                                pad = Image.new("RGB", (1080, im.height),
                                                (255, 255, 255))
                                pad.paste(im, ((1080 - im.width) // 2, 0))
                                im = pad
                            im.save(path)
                            im.close()
                            if not _shot_is_blank(path):
                                shot_done = True
                                log.info("article-node screenshot (%s): %s",
                                         art_name, url[:90])
                            else:
                                log.info("article-node screenshot near-blank; "
                                         "headline-crop fallback: %s", url[:80])
                    except Exception as exc:  # noqa: BLE001 -> headline crop
                        log.info("article-node screenshot failed (%s); "
                                 "headline-crop fallback: %s",
                                 str(exc)[:60], url[:80])
                        shot_done = False
                    # r15 HUMAN CROP, r17 HARDENED (fallback): the headline block
                    # is REQUIRED. A screenshot happens ONLY tight around
                    # masthead + h1 + lead image — the shot a person would
                    # take. NO h1 -> NO screenshot for this URL (the raw
                    # top-of-page fallback that grabbed ads/nav is DEAD; the
                    # og-photo/subject chain covers it downstream).
                    if not shot_done:
                        h1 = None
                        try:
                            # r28: collect EVERY headline on the page, then lock
                            # onto the one whose TEXT matches the story topic —
                            # so a 'trending now/related' module's headline (an
                            # off-topic Eminem story slipped in this exact way)
                            # can never be the one we shoot.
                            cands = []
                            loc = page.locator("h1")
                            for k in range(min(10, loc.count())):
                                el = loc.nth(k)
                                bb = el.bounding_box()
                                if not (bb and bb.get("width", 0) > 200):
                                    continue
                                try:
                                    txt = (el.text_content() or "").strip().lower()
                                except Exception:  # noqa: BLE001
                                    txt = ""
                                cands.append((bb, txt))
                            if topic_kw:
                                for bb, txt in cands:
                                    if any(kw in txt for kw in topic_kw):
                                        h1 = bb
                                        break
                                if h1 is None and cands:
                                    log.info("screenshot: no ON-TOPIC headline "
                                             "(%s) on %s; skipping", topic_kw[:3],
                                             url[:70])
                                    page.close()
                                    continue
                            elif cands:
                                h1 = cands[0][0]
                        except Exception:  # noqa: BLE001
                            h1 = None
                        if not h1:
                            log.info("screenshot: no headline block found; "
                                     "skipping (no raw-page fallback): %s",
                                     url[:90])
                            page.close()
                            continue
                        img_bb = None
                        try:
                            for sel in ("article img", "main img", "img"):
                                for k in range(min(4, page.locator(sel).count())):
                                    bb = page.locator(sel).nth(k).bounding_box()
                                    if (bb and bb.get("width", 0) > 400
                                            and bb["y"] > h1["y"]
                                            and bb["y"] < h1["y"] + 1200):
                                        img_bb = bb
                                        break
                                if img_bb:
                                    break
                        except Exception:  # noqa: BLE001
                            img_bb = None
                        x = max(0.0, h1["x"] - 24)
                        # r27 (owner: "dexerto is our COMPETITOR, why are we
                        # giving them views/brand on our back"): crop from just
                        # above the HEADLINE, not the masthead — so the publisher
                        # logo + top nav (the competitor's brand) never show. The
                        # headline + lead image is the proof; the brand is not.
                        y = max(0.0, h1["y"] - 16)
                        if img_bb:
                            bottom = img_bb["y"] + img_bb["height"] + 40
                        else:
                            bottom = h1["y"] + 700
                        height = max(600.0, min(1350.0, bottom - y))
                        # r27 (owner: balleralert's "Get Your Baller Alerts"
                        # signup box showed BESIDE the headline): crop to the
                        # ARTICLE COLUMN, not the full page width, so a right-rail
                        # sidebar/signup/ad never enters the shot. The lead image
                        # spans the column, so its right edge is the column edge;
                        # fall back to the headline's own width when there's no
                        # image. Always keep the left edge at the headline.
                        if img_bb and img_bb.get("width", 0) > 300:
                            right = max(h1["x"] + h1.get("width", 0),
                                        img_bb["x"] + img_bb["width"])
                        else:
                            right = h1["x"] + max(h1.get("width", 0), 300)
                        width = min(1032.0, max(360.0, right + 24 - x))
                        clip = {"x": x, "y": y, "width": width, "height": height}
                        page.screenshot(path=path, clip=clip)
                        # upscale narrow crops to full card width
                        try:
                            im = Image.open(path)
                            if im.width < 1080:
                                r = 1080.0 / im.width
                                im = im.resize((1080, int(im.height * r)),
                                               Image.Resampling.LANCZOS)
                                im.save(path)
                            im.close()
                        except Exception:  # noqa: BLE001
                            pass
                        page.close()
                    else:
                        page.close()
                except Exception as exc:  # noqa: BLE001
                    log.info("screenshot failed (%s): %s",
                             str(exc)[:80], url[:90])
                    continue
                # sanity: reject blank / bot-wall shots (near-uniform frames)
                try:
                    g = Image.open(path).convert("L").resize((64, 80))
                    if float(np.asarray(g).std()) < 8.0:
                        log.info("screenshot near-blank; og/subject "
                                 "fallback: %s", url[:90])
                        url_shot[url] = None
                        continue
                except Exception:
                    url_shot[url] = None
                    continue
                log.info("REAL source screenshot: %s", url[:100])
                out[i] = path
                url_shot[url] = path
            browser.close()
    except Exception as exc:  # noqa: BLE001
        log.info("screenshot engine unavailable (%s); article receipts fall "
                 "back to og photos / subject", str(exc)[:100])
    return out


def resolve_event_receipts(meta, receipt_paths, shooter, og_fetch):
    """r17 evidence chain for kind='event' receipt entries (BEIGE RETIRED —
    the server ships them as metadata only, url=''). Chain per event index:
      (a) clean article screenshot (headline-anchored, ads hidden) — textish
          contain render;
      (b) else the article's real og:image photo — stored as
          {"path":.., "photo": True} so the planner renders it as a NORMAL
          cover-crop face-aware photo scene (it IS the moment's photo);
      (c) else nothing — the planner's subject-photo fallback covers it.
    Any stale event CARD that still arrived from an old feed is dropped
    first: a beige card can never be chosen. Post/promo entries untouched.
    Pure orchestration (shooter/og_fetch injected) — unit-testable offline.
    Returns (receipt_paths, n_screenshots, n_og_photos)."""
    meta = meta if isinstance(meta, list) else []
    ev_idx = [i for i, m in enumerate(meta[:20])
              if isinstance(m, dict) and m.get("kind") == "event"]
    for i in ev_idx:
        receipt_paths.pop(i, None)         # beige never survives
    targets = {}
    for i in ev_idx:
        su = str(meta[i].get("source_url") or "")
        if su.startswith("http"):
            targets[i] = su
    shots = shooter(targets) if targets else {}
    shots = shots or {}
    # r20 VARIETY LAW (filmstrip verdict: ONE article screenshot appeared in
    # 6 of 15 scenes — a single-source story floods the video with the same
    # image). The SAME evidence image may back at most 2 receipt indexes;
    # further indexes fall through to the og photo / subject chain instead.
    use_count = {}
    for i, sp in shots.items():
        if not sp:
            continue
        key = sp if isinstance(sp, str) else str(sp)
        if use_count.get(key, 0) >= 2:
            continue                       # variety over repetition
        use_count[key] = use_count.get(key, 0) + 1
        receipt_paths[i] = sp              # (a) clean screenshot (textish)
    og_n = 0
    og_used = {}
    for i in ev_idx:
        if i in receipt_paths:
            continue
        og = str(meta[i].get("og_image") or "")
        if not og.startswith("http"):
            continue
        if og_used.get(og, 0) >= 2:
            continue                       # same photo also capped at 2
        p = og_fetch(i, og)
        if p:
            og_used[og] = og_used.get(og, 0) + 1
            receipt_paths[i] = {"path": p, "photo": True}   # (b) real moment photo
            og_n += 1
    return receipt_paths, len(set(k for k in use_count)), og_n


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


def fetch_visual(url, dest, trim=True):
    """Download + validate one visual. Corrupt/tiny/unreadable -> None (dropped
    from the pool), never a crash. Letterbox bars are trimmed on arrival
    (trim=False for receipt cards: their dark paper background sits near the
    bar-detector threshold and must never be shaved)."""
    data = _download_bytes(url)
    if not data or len(data) < 2000:
        return None
    try:
        with open(dest, "wb") as f:
            f.write(data)
        img = Image.open(dest)
        img.load()                           # force full decode: catches truncation
        img = img.convert("RGB")
        trimmed = _trim_letterbox(img) if trim else img
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
        # v8 guard (live-caught bug: "John Davis" resolved to a historical
        # SAILOR on a death story): a non-creatorish description that reads
        # historical/military/other-era is never our story's subject.
        if not creatorish:
            historicalish = any(w in desc for w in (
                "sailor", "soldier", "navy", "military", "explorer",
                "navigator", "bishop", "saint", "monarch", "missionary",
                "colonel"))
            if not historicalish:
                for tok in (desc.replace("(", " ").replace(")", " ")
                            .replace(",", " ").replace("-", " ").split()):
                    if tok.isdigit() and len(tok) == 4 and int(tok) < 1950:
                        historicalish = True
                        break
            if historicalish:
                log.info("wikidata: '%s' resolves to a historical/other-era "
                         "namesake (%s); skipped", name, desc[:60])
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


def sight_flags_by_url(post):
    """r14: map url -> sight verdict dict from the feed's visual_flags[]
    (aligned with visuals[]; entries are {"text_heavy","faces"} or null).
    Missing/malformed feed field -> {} (heuristics as before)."""
    vis = post.get("visuals")
    flags = post.get("visual_flags")
    out = {}
    if isinstance(vis, list) and isinstance(flags, list):
        for i, u in enumerate(vis):
            if (isinstance(u, str) and i < len(flags)
                    and isinstance(flags[i], dict)):
                out[u] = flags[i]
    return out


def build_visual_pool(post, page_id):
    """Assemble the scene visual pool: feed visuals (hero first) + resolved
    person photos, deduped, downloaded, validated. Returns (pool, person_map):
    pool is a list of {"path", "textish", "url", "person"} dicts (v3: textish
    photos get the contain renderer); person_map (r11) maps lowercased person
    name -> a LIST of that person's pool entries (avatar first, then their
    recent channel thumbnails), so Director shots carrying "person" can show
    THAT person's real imagery with variety across consecutive shots."""
    urls = []
    vis = post.get("visuals")
    if isinstance(vis, list):
        urls = [u for u in vis if isinstance(u, str) and u.startswith("http")]
    if not urls and post.get("image"):
        urls = [post["image"]]

    # People -> real photos (more real faces = more scenes). Never fatal.
    # v8: the feed may send people as [{"name":..., "photo": url|None}] — the
    # server resolved the face through the SITE's full arsenal (stored entity
    # QIDs, verified Wikidata creator photos, YouTube channel avatars). A
    # feed-provided photo is the FIRST choice for that person; Wikidata here
    # stays the fallback for people without one. Plain-string people (old
    # feed shape) keep the exact previous behaviour.
    # r11: the feed may also send "photos": [urls] PLURAL per person (avatar
    # first, then recent real channel thumbnails of the same verified person);
    # every one joins the pool under that person's name.
    person_urls, url2name = [], {}
    people = post.get("people") or []
    if isinstance(people, list) and people:
        context = f"{post.get('title', '')} {(post.get('script') or '')[:200]}"
        t0 = time.time()
        for entry in people[:4]:
            if isinstance(entry, dict):
                name = str(entry.get("name") or "").strip()
                photos = entry.get("photos")
                if not (isinstance(photos, list) and photos):
                    photos = [entry.get("photo")]
            else:
                name, photos = str(entry).strip(), [None]
            if not name:
                continue
            got = 0
            for ph in photos[:4]:
                if isinstance(ph, str) and ph.startswith("http"):
                    person_urls.append(ph)
                    url2name.setdefault(ph, name)
                    got += 1
            if got:
                log.info("person photos from feed (site-resolved): %s x%d",
                         name, got)
                continue                      # feed photos cost no budget
            if time.time() - t0 > PEOPLE_BUDGET_S:
                log.info("people budget exhausted; skipping remaining names")
                continue                      # later feed photos must still land
            u = wikidata_person_photo_url(name, context)
            if u:
                log.info("person photo resolved via wikidata: %s", name)
                person_urls.append(u)
                url2name.setdefault(u, name)

    # v9 (owner round-9): the story COVER is a DESIGNED COMPOSITE from the site's
    # image engine (VS split, AI-art half, text) — a poster, not footage. Crop-
    # zooming it rendered garbage. So: real faces first, then real event images,
    # and the cover joins only as LAST RESORT — and always contain-rendered.
    titles = post.get("visual_titles") or []
    url_title = {}
    for _i, _u in enumerate(urls):
        if _i < len(titles):
            url_title[_u] = str(titles[_i]).lower()

    def _designed(u):
        t = url_title.get(u, "")
        return ("cover" in t) or ("render" in t) or ("card" in t)

    # r14 SIGHT FLAGS: the server's seeing pass LOOKED at these images; its
    # per-url text_heavy verdict overrides the filename/aspect heuristic
    # (sight beats filename guessing). _designed stays an OR on top: a
    # designed composite cover is a poster regardless of what sight says.
    url_flag = sight_flags_by_url(post)

    def _textish(local_path, u):
        fl = url_flag.get(u)
        if fl is not None:
            return bool(fl.get("text_heavy")) or _designed(u)
        return is_text_heavy(local_path, src_url=u) or _designed(u)

    # r25 motion-lite (owner: "clean the off-topic stills off"): when footage
    # is DISABLED a YouTube thumbnail is NOT a clip source — it is just an
    # unpredictable video frame. For a musician the "recent video" thumbnails
    # are MUSIC-VIDEO imagery (a desert set, a money shot, a video vixen) that
    # lands as an off-topic still over a drama story. Drop every i.ytimg.com
    # thumbnail from the still pool; real report photos, news/Wikidata portraits
    # and the article-screenshot cards carry the video. (Footage ON keeps them —
    # they become real muted clips there.)
    if os.environ.get("VIDEO_FOOTAGE_FETCH", "1") == "0":
        _before = len(person_urls) + len(urls)
        person_urls = [u for u in person_urls if "ytimg.com/vi" not in u]
        urls = [u for u in urls if "ytimg.com/vi" not in u]
        _dropped = _before - (len(person_urls) + len(urls))
        if _dropped:
            log.info("motion-lite: dropped %d yt-thumbnail(s) from the still "
                     "pool (footage off) — real photos/portraits/cards only",
                     _dropped)

    ordered, seen = [], set()
    for u in (person_urls + urls[1:] + urls[:1] if urls else person_urls):
        if u not in seen:
            seen.add(u)
            ordered.append(u)

    pool, person_map = [], {}
    for i, u in enumerate(ordered[:MAX_POOL]):
        p = fetch_visual(u, os.path.join(WORKDIR, f"vis-{page_id}-{i}"))
        if p:
            textish = _textish(p, u)
            if textish:
                log.info("text-heavy visual (%s) -> contain mode (no "
                         "crop/zoom): %s",
                         "sight" if url_flag.get(u) is not None
                         else "heuristic", u[:120])
            entry = {"path": p, "textish": textish, "url": u,
                     "person": url2name.get(u),
                     "designed": _designed(u)}   # r21: cover ban in fallback
            pool.append(entry)
            if entry["person"]:
                # r11: LIST per person — avatar + recent thumbnails, in feed
                # order, so consecutive shots of one person can cycle them.
                person_map.setdefault(entry["person"].lower(), []).append(entry)
    log.info("visual pool: %d usable of %d candidates (%d from people, "
             "%d name(s) mapped: %s)", len(pool), len(ordered),
             len(person_urls), len(person_map),
             {k: len(v) for k, v in person_map.items()})
    return pool, person_map


def build_visual_map(post, page_id, pool, shotlist):
    """v6: resolve the shotlist's visual_i references to local images.
    visual_i indexes the feed's visuals[] (server and Director share the
    extraction, so index n is the same image on both sides). Pool entries are
    reused by URL; indexes outside the pool cap are fetched on demand. Any
    failure just leaves a hole -> the planner falls back to the pool."""
    vis = post.get("visuals")
    urls = [u for u in vis if isinstance(u, str) and u.startswith("http")] \
        if isinstance(vis, list) else []
    titles = post.get("visual_titles") if isinstance(
        post.get("visual_titles"), list) else []
    url_flag = sight_flags_by_url(post)       # r14 sight flags
    needed = set()
    if isinstance(shotlist, dict):
        for s in shotlist.get("shots") or []:
            if not isinstance(s, dict):
                continue
            vi = s.get("visual_i")
            if isinstance(vi, (int, float)) and 0 <= int(vi) < len(urls):
                needed.add(int(vi))
    if not needed:
        return {}
    by_url = {e.get("url"): e for e in pool}
    footage_off = os.environ.get("VIDEO_FOOTAGE_FETCH", "1") == "0"
    vmap = {}
    for i in sorted(needed):
        u = urls[i]
        # r25 motion-lite: a Director pin to a YouTube thumbnail is a clip order
        # with no clip (footage off) — it would resolve to an off-topic music-
        # video frame. Skip it so the shot falls back to a real pool photo/card.
        if footage_off and "ytimg.com/vi" in u:
            log.info("motion-lite: visual_i %d is a yt-thumbnail (footage off) "
                     "-> pool fallback", i)
            continue
        entry = by_url.get(u)
        if entry is None:
            p = fetch_visual(u, os.path.join(WORKDIR, f"visidx-{page_id}-{i}"))
            if p:
                fl = url_flag.get(u)          # r14: sight beats the heuristic
                textish = (bool(fl.get("text_heavy")) if fl is not None
                           else is_text_heavy(p, src_url=u))
                entry = {"path": p, "textish": textish,
                         "url": u, "person": None}
        if entry:
            vmap[i] = entry
            t = titles[i] if i < len(titles) else "?"
            log.info("visual_i %d ready (%s)", i, str(t)[:80])
        else:
            log.warning("visual_i %d unavailable; pool fallback", i)
    return vmap


# ============================================================================
# r13: REAL FOOTAGE — upgrade YouTube-thumbnail stills to short MUTED clips
# of the actual story videos (yt-dlp section download; drama-channel fair-use
# posture: tiny excerpts, muted, transformed under commentary/captions).
# STRICTLY non-fatal: any miss keeps the thumbnail still.
# ============================================================================
_YTIMG_RE = re.compile(
    r"https?://i\.ytimg\.com/vi(?:_webp)?/([A-Za-z0-9_-]{6,20})/")
_FOOTAGE_CACHE = {}            # (video_id, window) -> local path or None
_FOOTAGE_FETCHES = 0           # run-level yt-dlp attempt counter
_YT_COOKIES_LOGGED = [False]   # r24: "footage: cookies active" logged once
_RENDER_REPORT = {}            # r25: what the planner did (posted back w/ video)


def yt_cookies_file():
    """r24: path of a usable logged-in cookies.txt, or None. The workflow
    writes secrets.YT_COOKIES to <WORKDIR>/yt_cookies.txt before the render;
    with it yt-dlp survives YouTube's cloud-IP bot wall and the footage
    budgets flip to footage-first. Only a real file >100 bytes counts (an
    empty/garbage write must NOT flip the budgets). Never raises."""
    p = os.environ.get("YT_COOKIES_FILE",
                       os.path.join(WORKDIR, "yt_cookies.txt"))
    try:
        if p and os.path.isfile(p) and os.path.getsize(p) > 100:
            if not _YT_COOKIES_LOGGED[0]:
                log.info("footage: cookies active")
                _YT_COOKIES_LOGGED[0] = True
            return p
    except OSError:
        pass
    return None


def ytimg_video_id(url):
    """The YouTube video id if `url` is an i.ytimg.com thumbnail, else None
    (an i.ytimg.com/vi/<id>/ thumbnail IS a frame of that exact video)."""
    m = _YTIMG_RE.match(url or "")
    return m.group(1) if m else None


def footage_budget_ok(need_s, n_scenes, used_s, consec_broll, prev_footage,
                      enabled=None, planned=False, has_planned=False,
                      reserve_n=0, reserve_s=0.0, cookies=False,
                      runtime_s=0.0, consec_footage=0):
    """Pure r13/r17/r24 gate (unit-testable offline): may THIS beat become
    real footage? WITHOUT cookies (the fair-use, bot-walled posture): 3.5s
    opportunistic / 4.5s planned per scene, 3 scenes / ~8s (4 / 12s when the
    Director planned clips), never two footage scenes consecutive. WITH
    cookies (r24 footage-first): 4.5s opportunistic / 5.0s planned, up to 8
    scenes, total borrowed capped at min(30s, 60% of runtime), and
    consecutive footage IS allowed — window rotation (never the same
    (id, window) twice in a row) guards variety instead. Both modes: footage
    counts as b-roll, so the max-2-videos-in-a-row rule still forces a still
    accent after two moving scenes. r17 PRIORITY: an opportunistic upgrade
    must additionally leave room — reserve_n scenes / reserve_s seconds —
    for every still-upcoming planned clip; planned shots themselves never
    yield to opportunistic ones."""
    if not (REAL_FOOTAGE if enabled is None else enabled):
        return False
    if cookies:
        scene_max = (FOOTAGE_CK_PLANNED_SCENE_MAX_S if planned
                     else FOOTAGE_CK_SCENE_MAX_S)
        max_n = FOOTAGE_CK_MAX_SCENES
        max_s = FOOTAGE_CK_MAX_TOTAL_S
        if runtime_s and runtime_s > 0:
            max_s = min(max_s, FOOTAGE_CK_MAX_TOTAL_FRAC * runtime_s)
    else:
        scene_max = (FOOTAGE_PLANNED_SCENE_MAX_S if planned
                     else FOOTAGE_SCENE_MAX_S)
        max_n = (FOOTAGE_PLANNED_MAX_SCENES if has_planned
                 else FOOTAGE_MAX_SCENES)
        max_s = (FOOTAGE_PLANNED_MAX_TOTAL_S if has_planned
                 else FOOTAGE_MAX_TOTAL_S)
    if need_s > scene_max + 1e-6:
        return False
    if cookies:
        # r25: real story footage may run up to FOOTAGE_CK_MAX_CONSEC scenes in
        # a row before a still accent — it is NOT the generic-stock stream the
        # 2-in-a-row cap was built to bound. (consec_broll here counts stock +
        # footage together, so gating footage on it forced a still every 3rd
        # scene — the exact "too many dead stills" the owner flagged.)
        if consec_footage >= FOOTAGE_CK_MAX_CONSEC:
            return False
    else:
        if consec_broll >= 2:
            return False
        if prev_footage:
            return False
    if planned:
        return n_scenes < max_n and used_s + need_s <= max_s + 1e-6
    if n_scenes + 1 + reserve_n > max_n:
        return False
    if used_s + need_s + reserve_s > max_s + 1e-6:
        return False
    return True


def pick_footage_window(vid, n_windows, use_counts, prev_vid=None,
                        prev_win=None, failed=()):
    """r24 pure chooser (unit-testable offline): which section window should
    the next footage scene of `vid` download/play? Least-used windows first,
    so one id yields DIFFERENT moving sections across its scenes; windows
    already known-failed this run are skipped; and the same (id, window)
    file NEVER plays twice in a row — when the previous footage scene was
    this same vid, its window is banned outright. Ties prefer a window index
    different from the previous footage scene's (variety even across ids),
    then the lower index. Returns a window index, or None when no spare
    window exists (caller keeps the still)."""
    cands = [k for k in range(int(n_windows)) if k not in set(failed)]
    if prev_vid == vid and prev_win is not None:
        cands = [k for k in cands if k != prev_win]
    if not cands:
        return None
    return min(cands, key=lambda k: (use_counts.get((vid, k), 0),
                                     1 if k == prev_win else 0, k))


def still_hold_ok(prev_paths, path):
    """r24 pure gate (unit-testable offline): may `path` carry this scene as
    a STILL? False only when the SAME image already carried BOTH previous
    scenes — a 3rd consecutive hold is exactly the frozen "5s single-visual
    opener" the owner keeps flagging. prev_paths = the last (up to 2) scene
    paths, oldest first."""
    return not (path is not None and len(prev_paths) >= 2
                and prev_paths[-1] == path and prev_paths[-2] == path)


def fetch_story_footage(video_id, window=0):
    """Download a short section of the story's own YouTube video via yt-dlp.
    Returns a local video path or None. Cached per (id, window) per run
    (misses too, so a bot-walled/too-short window is never retried within a
    run). r24 MULTI-WINDOW: with a cookies file each id serves up to
    len(FOOTAGE_WINDOWS_CK) DIFFERENT sections (window k -> its own attempt
    and file foot-<id>-w<k>.mp4), so one story video yields several distinct
    moving scenes; cookie-less runs keep the single r13 window and filename.
    Burner-account safety: attempts capped per run (12 with cookies / 6
    without) and a 2-4s sleep before every yt-dlp spawn when cookies are
    active. The caller ALWAYS has the thumbnail still as fallback. Never
    raises."""
    global _FOOTAGE_FETCHES
    ck = yt_cookies_file()
    windows = FOOTAGE_WINDOWS_CK if ck else [FOOTAGE_SECTION]
    window = max(0, min(int(window or 0), len(windows) - 1))  # clamp
    stem = f"foot-{video_id}-w{window}" if ck else f"foot-{video_id}"
    key = (video_id, window)
    if key in _FOOTAGE_CACHE:
        return _FOOTAGE_CACHE[key]
    # r25: YouTube hard bot-walls video DOWNLOADS from cloud/CI IPs (verified:
    # every player_client returns "Sign in to confirm you're not a bot", even
    # with valid cookies). So actually spawning yt-dlp just burns ~1-2 min per
    # render for guaranteed failure. VIDEO_FOOTAGE_FETCH=0 skips the spawn (fail
    # fast) while KEEPING ck_mode on — so the footage-first stock-kill + budgets
    # still apply and the video rides motion-strong real stills. Flip back to 1
    # the day a residential proxy (or unblocked source) makes downloads work.
    if os.environ.get("VIDEO_FOOTAGE_FETCH", "1") == "0":
        _FOOTAGE_CACHE[key] = None
        return None
    path = None
    try:
        import shutil
        max_fetches = FOOTAGE_CK_MAX_FETCHES if ck else FOOTAGE_MAX_FETCHES
        if _FOOTAGE_FETCHES >= max_fetches:
            log.info("FOOTAGE fetch cap (%d) reached; thumbnail stills from "
                     "here", max_fetches)
        elif not shutil.which("yt-dlp"):
            log.info("FOOTAGE: yt-dlp not on PATH; thumbnail stills only")
        else:
            _FOOTAGE_FETCHES += 1
            outtmpl = os.path.join(WORKDIR, f"{stem}.%(ext)s")
            base = ["yt-dlp", "--no-playlist", "--quiet", "--no-warnings",
                    "-f", "bv*[height<=720][ext=mp4]/b[height<=720]",
                    "--download-sections", windows[window],
                    "-o", outtmpl,
                    f"https://www.youtube.com/watch?v={video_id}"]
            if ck:
                # r24: the logged-in cookies are the whole unlock
                base[1:1] = ["--cookies", ck]
            # Attempt 2 = android player_client (the usual cure when the
            # web client gets the "confirm you're not a bot" wall).
            retry = base[:1] + ["--extractor-args",
                                "youtube:player_client=android"] + base[1:]
            for cmd in (base, retry):
                if ck:
                    # r24 burner safety: never hammer YouTube from the
                    # logged-in account — a human-ish pause between spawns.
                    time.sleep(random.uniform(*FOOTAGE_FETCH_SLEEP_S))
                try:
                    subprocess.run(cmd, timeout=FOOTAGE_FETCH_TIMEOUT,
                                   stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL, check=False)
                except Exception as exc:  # noqa: BLE001 (timeout included)
                    log.info("FOOTAGE fetch attempt failed for %s w%d (%s)",
                             video_id, window, type(exc).__name__)
                hits = [p for p in glob.glob(
                    os.path.join(WORKDIR, f"{stem}.*"))
                    if p.rsplit(".", 1)[-1] in ("mp4", "webm", "mkv", "mov")
                    and os.path.getsize(p) > 30000]
                if hits:
                    path = sorted(hits)[0]
                    break
        if path:
            # Probe like BrollFetcher: must open and be long enough to show
            # the 2s-in sub-segment. Broken partials -> thumbnail still.
            from moviepy import VideoFileClip
            v = VideoFileClip(path)
            d = float(v.duration or 0)
            v.close()
            if d < FOOTAGE_SUB_OFF_S + 1.0:
                log.info("FOOTAGE %s w%d too short (%.1fs); thumbnail "
                         "fallback", video_id, window, d)
                path = None
    except Exception as exc:  # noqa: BLE001
        log.info("FOOTAGE %s w%d unusable (%s); thumbnail fallback",
                 video_id, window, exc)
        path = None
    _FOOTAGE_CACHE[key] = path
    return path


# ============================================================================
# r28 MULTI-PLATFORM FOOTAGE — the platform-check proved Twitch/TikTok/Kick
# clips download from a runner with NOTHING but curl_cffi TLS impersonation
# (--impersonate chrome), no cookies, no WARP; YouTube needs cookies (+WARP at
# the yml level); X needs cookies. Streamer drama's REAL moments live on
# Twitch/Kick, reactions on TikTok — so these are prime evidence. This fetches
# a whole SHORT clip (they are already short) which the scene layer trims+mutes.
# ============================================================================
_PLATFORM_CLIP_CACHE = {}
_STORY_CLIPS = []          # r28: this story's harvested platform clip URLs
                           # (Twitch/TikTok/Kick/YouTube), consumed as footage.
_FOOTAGE_REL_CACHE = {}    # r28 smart gate: clip path -> is-it-on-topic
_FOOTAGE_REL_CALLS = [0]
FOOTAGE_REL_MAX_CALLS = 8  # cap Gemini relevance checks per render


def footage_is_relevant(clip_path, topic):
    """r28 SMART FOOTAGE GATE (owner: "be smart enough to know what topic we
    want and what exact clips we're looking for"). Grab a frame from a fetched
    clip and ask Gemini yes/no: does it relate to THIS story? Rejects a
    musician's music-video frame on a feud story, an unrelated performance, a
    wrong clip. No key / over the call cap / any error -> True (never blocks
    footage on infra problems). Cached per clip."""
    if not (GEMINI_API_KEY and clip_path and topic):
        return True
    if clip_path in _FOOTAGE_REL_CACHE:
        return _FOOTAGE_REL_CACHE[clip_path]
    if _FOOTAGE_REL_CALLS[0] >= FOOTAGE_REL_MAX_CALLS:
        return True
    ok = True
    try:
        import io
        from moviepy import VideoFileClip
        v = VideoFileClip(clip_path)
        t = min(1.0, float(v.duration or 2.0) / 2.0)
        arr = v.get_frame(t)
        v.close()
        im = Image.fromarray(arr.astype("uint8"))
        im.thumbnail((512, 512))
        buf = io.BytesIO()
        im.save(buf, "JPEG", quality=80)
        _FOOTAGE_REL_CALLS[0] += 1
        prompt = (
            "This is a frame from a short video clip that may be used as "
            f"evidence in a news video about: \"{topic}\". Does this clip "
            "plausibly relate to THAT story — the people involved, the event, "
            "an interview or stream about it, the setting? A generic music "
            "video, an unrelated performance, an ad, or a totally different "
            'topic is NOT related. Respond ONLY JSON: {"related": true|false}.')
        body = {"contents": [{"parts": [
                    {"text": prompt},
                    {"inline_data": {"mime_type": "image/jpeg",
                        "data": base64.b64encode(buf.getvalue()).decode("ascii")}}]}],
                "generationConfig": {"temperature": 0.0,
                    "response_mime_type": "application/json"}}
        url = ("https://generativelanguage.googleapis.com/v1beta/models/"
               f"{GEMINI_MODEL}:generateContent?key={GEMINI_API_KEY}")
        r = requests.post(url, json=body, timeout=40)
        if r.status_code == 200:
            txt = (r.json()["candidates"][0]["content"]["parts"][0]["text"]
                   or "").strip()
            if txt.startswith("```"):
                txt = txt.strip("`").strip()
                if txt.lower().startswith("json"):
                    txt = txt[4:].strip()
            ok = bool(json.loads(txt).get("related", True))
            if not ok:
                log.info("FOOTAGE GATE: off-topic clip rejected (%s)",
                         os.path.basename(clip_path))
    except Exception as e:  # noqa: BLE001
        ok = True
    _FOOTAGE_REL_CACHE[clip_path] = ok
    return ok


def platform_of(url):
    """Which platform a clip URL belongs to (or None)."""
    u = (url or "").lower()
    if "kick.com" in u:                         return "kick"
    if "twitch.tv" in u:                        return "twitch"
    if "tiktok.com" in u:                       return "tiktok"
    if "youtube.com" in u or "youtu.be" in u:   return "youtube"
    if "x.com" in u or "twitter.com" in u:      return "x"
    return None


def fetch_platform_clip(url):
    """r28: download a short clip from ANY supported platform with the RIGHT
    method (proven by platform-check). Returns a local video path or None.
    Cached per URL per run; counts toward the run fetch cap; never raises."""
    global _FOOTAGE_FETCHES
    if url in _PLATFORM_CLIP_CACHE:
        return _PLATFORM_CLIP_CACHE[url]
    plat = platform_of(url)
    if plat is None:
        _PLATFORM_CLIP_CACHE[url] = None
        return None
    path = None
    try:
        import shutil
        ck = yt_cookies_file()
        max_fetches = FOOTAGE_CK_MAX_FETCHES if ck else FOOTAGE_MAX_FETCHES
        if _FOOTAGE_FETCHES >= max_fetches or not shutil.which("yt-dlp"):
            _PLATFORM_CLIP_CACHE[url] = None
            return None
        _FOOTAGE_FETCHES += 1
        stem = f"clip-{plat}-{hashlib.md5(url.encode()).hexdigest()[:12]}"
        outtmpl = os.path.join(WORKDIR, f"{stem}.%(ext)s")
        cmd = ["yt-dlp", "--no-playlist", "--quiet", "--no-warnings",
               "-f", "b[height<=720]/b", "--max-filesize", "45M",
               "-o", outtmpl, url]
        if plat in ("kick", "twitch", "tiktok"):
            # TLS-fingerprint bypass — the whole trick for these three.
            cmd[1:1] = ["--impersonate", "chrome"]
        elif plat == "youtube":
            if ck:
                cmd[1:1] = ["--cookies", ck]
                time.sleep(random.uniform(*FOOTAGE_FETCH_SLEEP_S))
        elif plat == "x":
            cmd[1:1] = ["--impersonate", "chrome"]
            if ck:
                cmd[1:1] = ["--cookies", ck]
        try:
            subprocess.run(cmd, timeout=FOOTAGE_FETCH_TIMEOUT + 15,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           check=False)
        except Exception as exc:  # noqa: BLE001 (timeout included)
            log.info("CLIP fetch failed (%s: %s)", plat, type(exc).__name__)
        hits = [p for p in glob.glob(os.path.join(WORKDIR, f"{stem}.*"))
                if p.rsplit(".", 1)[-1] in ("mp4", "webm", "mkv", "mov")
                and os.path.getsize(p) > 30000]
        if hits:
            path = sorted(hits)[0]
            from moviepy import VideoFileClip
            v = VideoFileClip(path)
            d = float(v.duration or 0)
            v.close()
            if d < 1.5:
                path = None
        if path:
            log.info("CLIP %s -> %s (%s)", plat, os.path.basename(path), url[:60])
    except Exception as exc:  # noqa: BLE001
        log.info("CLIP fetch error (%s): %s", plat, exc)
        path = None
    _PLATFORM_CLIP_CACHE[url] = path
    return path


# ============================================================================
# v6: FACE-AWARE PHONE FRAMING — haarcascade frontal-face detection (cached),
# eyeline-anchored cover crop, face-anchored zoom motions. Owner round-6:
# "framing must respect the phone screen". cv2 missing / no face found ->
# the exact v5 center-crop behaviour. STRICTLY non-fatal everywhere.
# ============================================================================
_FACE_CACHE = {}
_FACE_CASCADE = None


def _face_cascade():
    global _FACE_CASCADE
    if _FACE_CASCADE is None:
        import cv2
        _FACE_CASCADE = cv2.CascadeClassifier(
            cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
    return _FACE_CASCADE


def detect_face_box(path):
    """Largest frontal face as (x, y, w, h) in ORIGINAL image pixels, or None.
    Detection runs on a <=640px copy for speed; results cached per path."""
    if not FACE_FRAMING:
        return None
    if path in _FACE_CACHE:
        return _FACE_CACHE[path]
    box = None
    try:
        import cv2
        img = cv2.imread(path)
        if img is not None and img.size:
            h, w = img.shape[:2]
            scale = 1.0
            if max(w, h) > FACE_DETECT_MAX_SIDE:
                scale = FACE_DETECT_MAX_SIDE / float(max(w, h))
                img = cv2.resize(img, (max(1, int(w * scale)),
                                       max(1, int(h * scale))))
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            gray = cv2.equalizeHist(gray)
            faces = _face_cascade().detectMultiScale(
                gray, scaleFactor=1.1, minNeighbors=5, minSize=(36, 36))
            if len(faces):
                x, y, fw, fh = max(faces, key=lambda f: int(f[2]) * int(f[3]))
                box = (x / scale, y / scale, fw / scale, fh / scale)
                log.info("face detected in %s: %dx%d at (%d,%d)",
                         os.path.basename(path), int(box[2]), int(box[3]),
                         int(box[0]), int(box[1]))
    except Exception as exc:  # noqa: BLE001
        log.warning("face detection unavailable (%s); center framing", exc)
    _FACE_CACHE[path] = box
    return box


def cover_fit_face(pil_img, tw, th, box):
    """Cover-crop like cover_fit, but the crop window is chosen so the face's
    EYELINE sits ~EYELINE_FRAC from the frame top and the face stays inside
    the phone-safe band (below the top-220px UI zone, above the caption band
    at 55% height / bottom-320px UI). Horizontal: face centered, clamped.
    Returns (cropped PIL, (face_cx, eyeline_y) in frame coordinates)."""
    pil_img = pil_img.convert("RGB")
    w, h = pil_img.size
    scale = max(tw / w, th / h)
    nw, nh = max(tw, int(round(w * scale))), max(th, int(round(h * scale)))
    img = pil_img.resize((nw, nh), Image.Resampling.LANCZOS)
    sx, sy = nw / float(w), nh / float(h)
    fx = box[0] * sx
    fy = box[1] * sy
    fw = box[2] * sx
    fh = box[3] * sy
    cx = fx + fw / 2.0
    eye = fy + 0.40 * fh                    # eyes sit ~40% down a haar box
    top = eye - EYELINE_FRAC * th           # eyeline at the upper-third mark
    # face bottom above the caption band; face top below the top UI zone.
    # When the face is taller than the whole safe band (extreme close-up)
    # the TOP rule wins — a sliced forehead is the judge-failing crop.
    top = max(top, (fy + fh) - FACE_BOTTOM_MAX)
    top = min(top, fy - FACE_TOP_MIN)
    top = max(0.0, min(top, nh - th))
    left = max(0.0, min(cx - tw / 2.0, nw - tw))
    img = img.crop((int(left), int(top), int(left) + tw, int(top) + th))
    return img, (cx - left, eye - top)


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
                          "provider": "pexels",
                          # v5: preview frame for the vision re-rank (Pexels
                          # serves a real still of the video — no download)
                          "image": str(v.get("image") or "")})
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
        thumb = ""
        for variant in ("large", "medium", "small"):
            f = files.get(variant) or {}
            w, h = int(f.get("width") or 0), int(f.get("height") or 0)
            url = f.get("url")
            if not url or not w or not h:
                continue
            if h > w and h >= 1080:      # portrait first
                best = url
                thumb = str(f.get("thumbnail") or "")
                break
            if best is None and max(w, h) >= 1080:
                best = url
                thumb = str(f.get("thumbnail") or "")
        if best and dur > 0:
            items.append({"url": best, "duration": dur,
                          "provider": "pixabay",
                          "image": thumb})   # v5: preview for vision re-rank
    return items


class BrollFetcher:
    """Per-run b-roll manager: walks the feed's `broll` terms IN ORDER (cursor
    cycles), caches searches and downloads, dedups URLs, and enforces a hard
    wall-clock + byte budget. clip_for() returns a validated local .mp4 at
    least `need_s` long, or None -> the caller falls back to a photo scene."""

    def __init__(self, terms):
        self.terms = [str(t).strip() for t in (terms or []) if str(t).strip()]
        self.have_keys = bool(PEXELS_API_KEY or PIXABAY_API_KEY)
        self.enabled = bool(self.terms) and self.have_keys
        if self.terms and not self.enabled:
            log.info("broll terms present but no PEXELS/PIXABAY key; "
                     "photos-only (v2 behaviour)")
        elif not self.terms:
            log.info("no broll terms in feed; cursor mode off "
                     "(v4 per-shot queries may still fetch)")
        self.searches = {}     # term -> [items]
        self.downloads = {}    # url-hash -> local path or None (failed)
        self.used = set()      # urls already placed in a scene
        self.cursor = 0
        self.t0 = time.time()
        self.bytes = 0
        self.budget_dead = False
        # v5 vision re-rank state: verdicts cached PER QUERY (shots sharing a
        # query share one Gemini call — the quota batching the spec demands).
        # verdict = {"best": url|None, "reject": set(urls), "veto": bool}
        # veto=True -> Gemini said NO candidate is acceptable for this query.
        self.rerank = {}
        self.vision_calls = 0

    def _budget_ok(self):
        if self.budget_dead:
            return False
        if time.time() - self.t0 > BROLL_TIME_BUDGET_S:
            log.info("broll time budget (%.0fs) exhausted; photos from here",
                     BROLL_TIME_BUDGET_S)
            self.budget_dead = True
        elif self.bytes > BROLL_BYTES_BUDGET:
            log.info("broll byte budget (%.0f MB) exhausted; photos from here",
                     BROLL_BYTES_BUDGET / 1024 / 1024)
            self.budget_dead = True
        return not self.budget_dead

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

    # ------------------------------------------------------------------
    # v5: vision re-rank (Law 24 / the Kapwing move). Keyword search has no
    # story understanding — the round-4 failure was a BLM-protest clip under
    # "fans demanding accountability" on an unrelated story. Gemini LOOKS at
    # the candidate preview frames against the narration phrase and picks /
    # vetoes. Every failure path returns None = v4.5 first-candidate order.
    # ------------------------------------------------------------------
    def _fetch_thumb(self, url):
        """Small preview jpeg bytes, or None. Never raises."""
        if not url:
            return None
        try:
            r = requests.get(url, timeout=VISION_THUMB_TIMEOUT,
                             headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and 1000 < len(r.content) < 3_000_000:
                return r.content
        except Exception as e:  # noqa: BLE001
            log.debug("thumb fetch failed (%s): %s", e, url[:100])
        return None

    def _vision_rerank(self, query, phrase, title, cands):
        """ONE gemini-2.5-flash call: candidate thumbnails + the narration
        phrase -> {"best": <idx|-1>, "reject": [idx...]}. Returns a verdict
        dict {"best": url|None, "reject": set(url), "veto": bool} or None
        when the re-rank is unavailable (no key, disabled, call cap reached,
        <2 usable thumbnails, API/JSON failure) -> caller keeps v4.5 order."""
        if not (GEMINI_API_KEY and VISION_RERANK):
            return None
        if self.vision_calls >= VISION_MAX_CALLS:
            log.info("vision re-rank call cap (%d) reached; keyword order",
                     VISION_MAX_CALLS)
            return None
        import base64
        thumbs = []                      # (candidate_index, jpeg bytes)
        for i, item in enumerate(cands[:VISION_CANDIDATES]):
            blob = self._fetch_thumb(item.get("image"))
            if blob:
                thumbs.append((i, blob))
        if len(thumbs) < 2:              # nothing to compare — not worth a call
            return None
        self.vision_calls += 1
        prompt = (
            "You are matching stock b-roll to one narration moment of a short "
            "drama-recap video.\n"
            f'Narration at this moment: "{(phrase or "")[:200]}"\n'
            f'Story: "{(title or "")[:150]}"\n'
            f"You get {len(thumbs)} candidate preview frames, in order; "
            "candidate numbers are " + ", ".join(str(i) for i, _ in thumbs) + ".\n"
            "Pick the ONE candidate that best matches WHAT IS BEING SAID "
            "right now. REJECT any candidate that is unsafe or could be "
            "misread against this story: protests, rallies, marches, flags, "
            "religious imagery, political imagery, children, medical "
            "settings, or any human context that could look like a different "
            "real event. If NO candidate is acceptable, best is -1.\n"
            'Respond ONLY with JSON: {"best": <candidate number or -1>, '
            '"reject": [<candidate numbers>]}')
        parts = [{"text": prompt}]
        for _, blob in thumbs:
            parts.append({"inline_data": {
                "mime_type": "image/jpeg",
                "data": base64.b64encode(blob).decode("ascii")}})
        try:
            body = {"contents": [{"parts": parts}],
                    "generationConfig": {
                        "temperature": 0.0,
                        "response_mime_type": "application/json"}}
            url = ("https://generativelanguage.googleapis.com/v1beta/models/"
                   f"{GEMINI_MODEL}:generateContent?key={GEMINI_API_KEY}")
            r = requests.post(url, json=body, timeout=60)
            if r.status_code != 200:
                log.warning("vision re-rank HTTP %d; keyword order",
                            r.status_code)
                return None
            text = (r.json()["candidates"][0]["content"]["parts"][0]["text"]
                    or "").strip()
            if text.startswith("```"):
                text = text.strip("`").strip()
                if text.lower().startswith("json"):
                    text = text[4:].strip()
            j = json.loads(text)
            best = int(j.get("best", -1))
            reject = {int(x) for x in (j.get("reject") or [])
                      if isinstance(x, (int, float, str))
                      and str(x).lstrip("-").isdigit()}
        except Exception as e:  # noqa: BLE001
            log.warning("vision re-rank failed (%s); keyword order", e)
            return None
        sent = {i for i, _ in thumbs}
        verdict = {"best": None, "reject": set(), "veto": False}
        for i in reject & sent:
            verdict["reject"].add(cands[i]["url"])
        if best == -1:
            verdict["veto"] = True
            log.info("vision re-rank '%s': NO candidate acceptable -> "
                     "subject photo", query)
        elif best in sent and best not in reject:
            verdict["best"] = cands[best]["url"]
            log.info("vision re-rank '%s': picked candidate %d (rejected %s)",
                     query, best, sorted(reject & sent) or "none")
        else:
            log.info("vision re-rank '%s': unusable best=%s; keyword order "
                     "minus %d rejected", query, best, len(verdict["reject"]))
        return verdict

    def clip_for_query(self, query, need_s, phrase="", title=""):
        """v4 EDL mode: local mp4 >= need_s long for THIS shot's Director
        query, or None -> the caller falls back to a subject photo. Shares
        the run's search/download caches, URL dedup and budgets.
        v5: candidates are vision re-ranked against the shot's exact
        narration phrase before anything is downloaded (see _vision_rerank);
        a veto returns None (subject photo — never a wrong story clip)."""
        query = str(query or "").strip()
        if not query or not self.have_keys or not self._budget_ok():
            return None
        cands = [it for it in self._search(query)
                 if it["duration"] >= need_s + 0.25
                 and it["url"] not in self.used]
        if not cands:
            log.info("broll shot query '%s' yielded nothing usable; subject "
                     "photo fallback", query)
            return None
        if query not in self.rerank:
            self.rerank[query] = self._vision_rerank(query, phrase, title,
                                                     cands)
        verdict = self.rerank[query]
        if verdict is not None:
            if verdict["veto"]:
                return None                       # Gemini: none acceptable
            ordered = []
            if verdict["best"]:
                ordered = [it for it in cands if it["url"] == verdict["best"]]
            ordered += [it for it in cands
                        if it["url"] != verdict["best"]
                        and it["url"] not in verdict["reject"]]
            cands = ordered
        for item in cands:
            if not self._budget_ok():
                return None
            path = self._download(item["url"])
            if path:
                self.used.add(item["url"])
                log.info("broll shot query '%s' -> %.1fs clip for %.1fs shot "
                         "(%s%s)", query, item["duration"], need_s,
                         item["provider"],
                         ", vision-ranked" if verdict is not None else "")
                return path
        log.info("broll shot query '%s' yielded nothing usable; subject photo "
                 "fallback", query)
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
# v4: HOUSE GRADE (Law 22) — one consistent look over photos AND b-roll so
# mixed sources feel like one shoot. Cheap vectorized numpy only: applied ONCE
# per photo array (zero per-frame cost) and per-frame on b-roll video. The
# vignette is a single cached static overlay layer (radial darken to ~0.85 at
# the corners), NOT per-frame math.
# ============================================================================
def grade_frame(arr):
    """Teal-shadow/warm-highlight shift + gentle contrast + saturation.
    uint8 HxWx3 in -> uint8 HxWx3 out. Any failure returns the input."""
    try:
        f = arr.astype(np.float32) * (1.0 / 255.0)
        luma = f[..., 0] * 0.299 + f[..., 1] * 0.587 + f[..., 2] * 0.114
        # teal shadows: lift blue where it's dark (and not already blue-maxed)
        f[..., 2] += GRADE_TEAL_SHADOWS * (1.0 - luma) * (1.0 - f[..., 2])
        # warm highlights: lift red where it's bright
        f[..., 0] += GRADE_WARM_HIGHLIGHTS * luma * (1.0 - f[..., 0])
        # contrast around mid-grey (gentle S)
        f -= 0.5
        f *= GRADE_CONTRAST
        f += 0.5
        # saturation
        l2 = (f[..., 0] * 0.299 + f[..., 1] * 0.587
              + f[..., 2] * 0.114)[..., None]
        f = l2 + (f - l2) * GRADE_SATURATION
        np.clip(f, 0.0, 1.0, out=f)
        return (f * 255.0).astype(np.uint8)
    except Exception:  # noqa: BLE001
        return arr


_VIGNETTE_RGBA = None


def make_vignette(duration):
    """Static full-duration overlay: transparent center, black corners at
    alpha ~(1-0.85)*255 — visually a radial multiply to ~0.85. Mask cached."""
    from moviepy import ImageClip

    global _VIGNETTE_RGBA
    if _VIGNETTE_RGBA is None:
        y, x = np.ogrid[:H, :W]
        cx, cy = W / 2.0, H / 2.0
        r = np.sqrt(((x - cx) / cx) ** 2 + ((y - cy) / cy) ** 2) / math.sqrt(2)
        mult = 1.0 - (1.0 - VIGNETTE_EDGE) * np.clip(r, 0.0, 1.0) ** 2
        rgba = np.zeros((H, W, 4), dtype=np.uint8)
        rgba[..., 3] = ((1.0 - mult) * 255.0).astype(np.uint8)
        _VIGNETTE_RGBA = rgba
    return ImageClip(_VIGNETTE_RGBA, transparent=True).with_duration(duration)


# ============================================================================
# v4: EDL EXECUTION — the Director's word-indexed shot list becomes an
# absolute-time edit decision list (vertical editing: every shot glued to its
# words). Null/malformed shotlist -> None -> the whole v3 path runs.
# ============================================================================
_V4_MOTIONS = ("punch_hit", "punch_build", "zoom_out", "pan_left", "pan_right")
# Never-identical-back-to-back guard (Law 10); Director promises, we enforce.
_MOTION_ALTERNATE = {
    "punch_hit": "punch_build",
    "punch_build": "zoom_out",
    "zoom_out": "punch_build",
    "pan_left": "pan_right",
    "pan_right": "pan_left",
}


def _norm_word(w):
    """Normalize a token for alignment: lowercase, strip punctuation."""
    return re.sub(r"[^a-z0-9']+", "", w.lower())


def map_tokens_to_spans(script, timings):
    """Per-whitespace-token (start_s, end_s) from the TTS word timings — the
    word-index -> ms bridge the Director schema is anchored on. 1:1 when the
    token count matches the cue count. r15: on mismatch, REAL fuzzy alignment
    (difflib on normalized word lists) instead of proportional guessing — the
    proportional path accumulated drift, so mid/late-video shots landed AFTER
    their words (the owner's 'image comes after they stopped talking about
    it'). Matched tokens take their cue's exact times; unmatched tokens
    interpolate between the nearest matched anchors. Monotonicity enforced."""
    import difflib
    tokens = [w for w in script.split() if w.strip()]
    n_tok, n = len(tokens), len(timings)
    if n_tok == 0 or n == 0:
        return []
    spans = []
    if n == n_tok:
        spans = [(t[1], t[2]) for t in timings]
    else:
        tok_n = [_norm_word(w) for w in tokens]
        cue_n = [_norm_word(t[0]) for t in timings]
        anchor = {}                              # token idx -> cue idx
        sm = difflib.SequenceMatcher(a=tok_n, b=cue_n, autojunk=False)
        for blk in sm.get_matching_blocks():
            for k in range(blk.size):
                anchor[blk.a + k] = blk.b + k
        matched = len(anchor)
        if matched < max(3, n_tok // 4):
            # hopeless alignment -> old proportional behaviour
            log.info("ALIGNMENT: only %d/%d tokens matched; proportional "
                     "fallback", matched, n_tok)
            for k in range(n_tok):
                a = min(n - 1, (k * n) // n_tok)
                b = min(n - 1, max(a, ((k + 1) * n) // n_tok - 1))
                spans.append((timings[a][1], timings[b][2]))
        else:
            # interpolate unmatched tokens between nearest matched anchors
            idxs = sorted(anchor.keys())
            max_gap = 0
            for k in range(n_tok):
                if k in anchor:
                    c = timings[anchor[k]]
                    spans.append((c[1], c[2]))
                    continue
                lo = max((i for i in idxs if i < k), default=None)
                hi = min((i for i in idxs if i > k), default=None)
                if lo is None and hi is None:
                    spans.append((0.0, 0.0))
                elif lo is None:
                    c = timings[anchor[hi]]
                    spans.append((c[1], c[1]))
                elif hi is None:
                    c = timings[anchor[lo]]
                    spans.append((c[2], c[2]))
                else:
                    t0 = timings[anchor[lo]][2]
                    t1 = timings[anchor[hi]][1]
                    frac0 = (k - lo) / (hi - lo)
                    frac1 = (k + 1 - lo) / (hi - lo)
                    spans.append((t0 + (t1 - t0) * frac0,
                                  t0 + (t1 - t0) * frac1))
                    max_gap = max(max_gap, hi - lo)
            log.info("ALIGNMENT: %d/%d tokens cue-matched (%.0f%%); largest "
                     "interpolated gap %d words", matched, n_tok,
                     100.0 * matched / n_tok, max_gap)
    fixed, prev_s = [], 0.0
    for s, e in spans:
        s = max(s, prev_s)
        e = max(e, s)
        fixed.append((s, e))
        prev_s = s
    return fixed


def build_edl(shotlist, script, timings, total):
    """Director shot list -> absolute-time EDL. Each shot runs from
    word[w_in].start - 300ms (Law 9 visual lead; clamped monotonic; first
    shot at 0) to the NEXT shot's t_in (hard-cut boundary = cut ON the word,
    early, never late — Laws 3/4); the last shot rides to `total`.
    Degenerate (<0.35s) shots are absorbed into their predecessor.
    Returns a list of shot dicts, or None when the shotlist is unusable."""
    try:
        if not isinstance(shotlist, dict):
            return None
        raw = shotlist.get("shots")
        if not isinstance(raw, list) or not raw:
            return None
        spans = map_tokens_to_spans(script, timings)
        if not spans:
            return None
        tokens = [w for w in script.split() if w.strip()]   # v5: phrase text
        n_tok = len(spans)
        declared = int(shotlist.get("words") or 0)
        if declared and declared != n_tok:
            log.warning("shotlist declares %d words, script tokenizes to %d; "
                        "indexes clamped", declared, n_tok)

        shots = []
        for s in raw:
            if not isinstance(s, dict):
                continue
            try:
                w_in = int(s.get("w_in", 0))
                w_out = int(s.get("w_out", w_in))
            except (TypeError, ValueError):
                continue
            w_in = max(0, min(n_tok - 1, w_in))
            w_out = max(w_in, min(n_tok - 1, w_out))
            motion = str(s.get("motion") or "").strip()
            if motion not in _V4_MOTIONS:
                motion = "punch_build"
            sfx = str(s.get("sfx") or "none").strip()
            if sfx not in ("none", "whoosh", "riser", "impact", "pop"):
                sfx = "none"
            music = str(s.get("music") or "bed").strip()
            if music not in ("bed", "silence", "duck"):
                music = "bed"
            emph = s.get("emphasis_w")
            try:
                emph = int(emph)
            except (TypeError, ValueError):
                emph = None
            if emph is not None:
                emph = max(w_in, min(w_out, emph))
            shot_class = s.get("shot_class")
            if shot_class not in ("broll", "receipt"):
                shot_class = "subject"
            ri = s.get("receipt_i")            # v4.5: evidence-card index
            try:
                ri = int(ri)
            except (TypeError, ValueError):
                ri = None
            if shot_class == "receipt" and ri is None:
                shot_class = "subject"
            person = str(s.get("person") or "").strip() or None   # v6
            vi = s.get("visual_i")                                # v6
            try:
                vi = int(vi)
            except (TypeError, ValueError):
                vi = None
            # r17 PLANNED CLIP: the Director's explicit real-footage order.
            # Server-validated already; belt here — only meaningful with a
            # pinned visual on a subject shot.
            clip = bool(s.get("clip")) and vi is not None \
                and shot_class == "subject"
            shots.append({
                "w_in": w_in, "w_out": w_out,
                "shot_class": shot_class, "receipt_i": ri,
                "person": person, "visual_i": vi, "clip": clip,
                "query": str(s.get("query") or "").strip(),
                "motion": motion, "sfx": sfx, "music": music,
                "emph_t": spans[emph][0] if emph is not None else None,
                # v5: the exact spoken phrase under this shot — the vision
                # re-rank judges stock candidates against THESE words.
                "phrase": " ".join(tokens[w_in:w_out + 1]),
            })
        if not shots:
            return None
        shots.sort(key=lambda x: x["w_in"])

        # Hard-cut boundaries with the 300ms visual lead, clamped monotonic.
        bounds = [0.0]
        for sh in shots[1:]:
            b = spans[sh["w_in"]][0] - VISUAL_LEAD_S
            bounds.append(max(b, bounds[-1] + 0.05))
        bounds.append(max(total, bounds[-1] + 0.05))
        for i, sh in enumerate(shots):
            sh["start"] = bounds[i]
            sh["end"] = bounds[i + 1]

        # Absorb degenerate slivers into the previous shot.
        merged = []
        for sh in shots:
            if merged and (sh["end"] - sh["start"]) < MIN_SHOT_S:
                merged[-1]["end"] = sh["end"]
                if merged[-1]["sfx"] == "none" and sh["sfx"] != "none":
                    merged[-1]["sfx"] = sh["sfx"]
                continue
            merged.append(sh)
        if merged and merged[0]["start"] > 0:
            merged[0]["start"] = 0.0
        log.info("EDL: %d shot(s) from %d directed (words=%d)",
                 len(merged), len(raw), n_tok)
        return merged
    except Exception as exc:  # noqa: BLE001
        log.warning("shotlist unusable (%s); falling back to v3 scene "
                    "planner", exc)
        return None


def motion_scale_fn(motion, dur, emph_rel):
    """Zoom curve per V4 spec Law 6. Returns f(t)->scale for .resized()."""
    if motion == "punch_hit":
        te = emph_rel if emph_rel is not None else dur * 0.4
        te = min(max(te, 0.0), max(dur - 0.05, 0.0))
        snap = max(PUNCH_HIT_FRAMES / float(FPS), 1e-3)

        def _s(t, te=te, snap=snap):
            if t < te:
                return 1.0
            k = min(1.0, (t - te) / snap)
            return 1.0 + (PUNCH_HIT_SCALE - 1.0) * k   # snap, then HOLD
        return _s
    if motion == "zoom_out":
        def _s(t, d=dur):
            return max(1.0, PUNCH_HIT_SCALE
                       - (PUNCH_HIT_SCALE - 1.0) * (t / d))
        return _s

    def _s(t, d=dur):                                   # punch_build (eased)
        u = min(1.0, max(0.0, t / d))
        u = u * u * (3.0 - 2.0 * u)                     # smoothstep
        return 1.0 + (PUNCH_BUILD_SCALE - 1.0) * u
    return _s


def plan_scenes_edl(edl, pool, fetcher, receipts=None, title="",
                    person_map=None, visual_map=None):
    """v4/v4.5 planner: the Director decided WHAT; this resolves each shot to
    a concrete asset. receipt -> the downloaded evidence card, rendered via
    the text-heavy CONTAIN path (whole card readable, no crop/zoom) — the v6
    branded promo card arrives as the last receipt index and takes this same
    path; a default 'pop' at t_in when the Director left sfx 'none' (the
    receipt slam — budget-exempt genre signature); subject -> (v6) the named
    PERSON's real imagery when the shot carries "person" (r11: cycling that
    person's photo LIST for variety), else the shot's visual_i REAL story
    image, else (r11) the LEAST-RECENTLY-USED pool photo outside a 3-scene
    no-repeat window — blind round-robin is gone (owner round-11: "it keeps
    showing the same image again and again"); broll -> stock clip for the
    shot's query.
    Receipts and photos count as A-ROLL and reset the consecutive-b-roll
    counter (defensive cap: max 2 stock clips in a row). Every miss falls
    back down the ladder (receipt -> photo; person/visual -> pool photo;
    broll -> photo); never black. Identical motion never repeats
    back-to-back.
    r13 REAL FOOTAGE: a resolved photo whose source URL is a YouTube
    thumbnail (i.ytimg.com/vi/<id>/) — a pinned visual_i OR a pool-served
    still — is upgraded to a short MUTED clip of that exact video when the
    fair-use budget allows (footage_budget_ok); any fetch miss keeps the
    thumbnail still.
    r17 PLANNED CLIPS: shots the Director marked clip=true are the PLAN —
    their video ids are prefetched before anything opportunistic, they may
    run 4.5s, the whole budget rises to 4 scenes / 12s when they exist, and
    opportunistic upgrades must leave room for every upcoming planned one.
    r17 RECEIPT CHAIN: a receipts[] value may be {"path":..,"photo":True} —
    the article's real og:image report photo. It renders as a NORMAL photo
    scene (cover-crop, face-aware), never the contain/card path; a plain
    string value stays the textish contain path (screenshot / post / promo).
    Beige event cards no longer exist anywhere in this chain.
    r24 FOOTAGE-FIRST: when the cookies file exists, yt-dlp actually works
    from cloud IPs, so footage carries the video: budgets flip to 8 scenes /
    min(30s, 60% of runtime), each id serves up to 3 DIFFERENT windows,
    consecutive footage scenes are allowed (never the same (id, window)
    twice in a row; window/id rotation), and opportunistic upgrades also
    fill pool-fallback yt-thumbnail stills whenever a spare window exists.
    r24 STILL-HOLD LIMIT (always on): the SAME still never carries a 3rd
    consecutive scene — LRU alternative, else a footage window, else kept
    with a loud log."""
    receipts = receipts or {}
    person_map = person_map or {}
    visual_map = visual_map or {}
    scenes, prev_motion, consec_broll = [], None, 0
    consec_footage = 0             # r25: footage scenes in a row (own cap)
    foot_n, foot_s = 0, 0.0        # r13: footage scenes / borrowed seconds
    last_used = {}                 # r11 LRU: pool path -> last scene index
    evidence_scene_uses = {}       # r21: evidence image -> scenes it backs (cap 2)
    person_rot = {}                # r11: per-person rotation cursor

    # r24: cookies flip the whole posture (see module header). Everything is
    # computed ONCE here so the budget math is deterministic per run.
    ck_mode = REAL_FOOTAGE and yt_cookies_file() is not None
    # r27: motion-lite (footage fetching disabled) — with the demon scraper the
    # video now has many REAL proofs, so generic Pexels stock (a magnifying
    # glass, a hand holding a phone) is never needed and reads as filler. Skip
    # it entirely; real stills/cards/portraits carry every beat.
    footage_off = os.environ.get("VIDEO_FOOTAGE_FETCH", "1") == "0"
    n_windows = len(FOOTAGE_WINDOWS_CK) if ck_mode else 1
    runtime_s = float(edl[-1]["end"]) if edl else 0.0
    win_uses = {}                  # (vid, window) -> scenes it has served
    last_foot = (None, None)       # (vid, window) of previous scene if footage
    planned_scene_max = (FOOTAGE_CK_PLANNED_SCENE_MAX_S if ck_mode
                         else FOOTAGE_PLANNED_SCENE_MAX_S)

    # r25 GAP-FILL SOURCE: every distinct story video id reachable from the
    # pool or the visual map. When a beat would otherwise FREEZE on a still
    # (3rd consecutive) or repeat one, _gap_footage() borrows a fresh window
    # from THIS set — footage is no longer limited to a scene whose own visual
    # happens to be a yt-thumbnail, so the dead gaps between clips become
    # motion. Empty set (no story videos) => behaves exactly as before.
    story_vids = []
    if ck_mode:
        for _e in list(pool) + list(visual_map.values()):
            _v = ytimg_video_id(_e.get("url"))
            if _v and _v not in story_vids:
                story_vids.append(_v)

    # r28 MULTI-PLATFORM CLIPS: relevant Twitch/TikTok/Kick/YouTube clips the
    # demon scraper harvested from this story's articles. Twitch/TikTok/Kick
    # need no cookies (curl_cffi impersonation), so these work whenever footage
    # fetching is enabled — even without the YouTube cookie/WARP. They are the
    # MOST relevant footage (embedded by the reporters), so a still scene is
    # upgraded to the next unused clip before falling back to a plain still.
    footage_enabled = (REAL_FOOTAGE
                       and os.environ.get("VIDEO_FOOTAGE_FETCH", "1") != "0")
    clip_pool = list(_STORY_CLIPS) if footage_enabled else []

    # r17: planned-clip census + PRIORITY PREFETCH — the run-level yt-dlp
    # attempt cap (FOOTAGE_MAX_FETCHES) is spent on the Director's PLAN
    # before any opportunistic upgrade can burn it.
    planned_flags = [bool(sh.get("clip")) and sh.get("visual_i") in visual_map
                     for sh in edl]
    has_planned = any(planned_flags)
    if has_planned and REAL_FOOTAGE:
        pids = []
        for pi, sh in enumerate(edl):
            if planned_flags[pi]:
                v = ytimg_video_id(visual_map[sh["visual_i"]].get("url"))
                if v and v not in pids:
                    pids.append(v)
        for v in pids:
            got = fetch_story_footage(v)
            log.info("PLANNED CLIP prefetch: %s -> %s", v,
                     "ok" if got else "unavailable (moment photo fallback)")

    def _planned_reserve(after_i):
        """Scenes/seconds the still-upcoming planned clips are entitled to
        (an opportunistic upgrade may only take what these won't need)."""
        n, s = 0, 0.0
        for j in range(after_i + 1, len(edl)):
            if planned_flags[j]:
                n += 1
                s += min(edl[j]["end"] - edl[j]["start"], planned_scene_max)
        return n, s

    def _recent_paths(k=POOL_NO_REPEAT_WINDOW):
        """Image paths of the last k scenes (any type) — the no-repeat window."""
        return {sc.get("path") for sc in scenes[-k:]}

    def _lru_pick(si):
        """Least-recently-used pool entry outside the no-repeat window.
        Never-used entries win first (in pool order: real faces, then story
        images); if the pool is smaller than the window, fall back to plain
        LRU but still never repeat the immediately previous scene when any
        alternative exists."""
        if not pool:
            return None
        # r21 (filmstrip verdict: the romance-pendant COVER polluted a gaming
        # story 3x via fallback): the designed cover may serve only when NO
        # real alternative exists at all.
        base = pool
        non_cover = [e for e in pool if not e.get("designed")]
        if len(non_cover) >= 2:
            base = non_cover
        recent = _recent_paths()
        cands = [e for e in base if e["path"] not in recent]
        if not cands:
            prev = scenes[-1].get("path") if scenes else None
            cands = [e for e in base if e["path"] != prev] or base
        entry = min(cands, key=lambda e: last_used.get(e["path"], -1))
        last_used[entry["path"]] = si
        return entry

    def _gap_footage(si, need_s):
        """r25 (owner: "the gaps between clips are dead frozen stills"): turn a
        would-be frozen/repeat still into MOTION by borrowing a fresh window
        from ANY of the story's own videos (story_vids) — not only the scene's
        own visual. ck_mode only; honours the footage budget + the run fetch
        cap; never the same (id, window) as the previous scene; never a clip
        already inside the no-repeat window. Mutates the footage counters and
        returns the clip path, or None when no spare moving window exists (the
        caller then keeps the still). A cheap reuse of an already-cached window
        costs no fetch; a new window spends one against the run cap."""
        nonlocal foot_n, foot_s, last_foot
        if not (ck_mode and story_vids):
            return None
        prev_foot = bool(scenes and scenes[-1].get("footage"))
        res_n, res_s = _planned_reserve(si)
        if not footage_budget_ok(need_s, foot_n, foot_s, consec_broll,
                                 prev_foot, planned=False,
                                 has_planned=has_planned,
                                 reserve_n=res_n, reserve_s=res_s,
                                 cookies=True, runtime_s=runtime_s,
                                 consec_footage=consec_footage):
            return None
        # least-borrowed video first, so gap-fill spreads across all the
        # story's sources instead of hammering one.
        order = sorted(story_vids, key=lambda v: sum(
            win_uses.get((v, k), 0) for k in range(n_windows)))
        for vid in order:
            tried_failed = {k for k in range(n_windows)
                            if (vid, k) in _FOOTAGE_CACHE
                            and _FOOTAGE_CACHE[(vid, k)] is None}
            win = pick_footage_window(vid, n_windows, win_uses,
                                      prev_vid=last_foot[0],
                                      prev_win=last_foot[1],
                                      failed=tried_failed)
            if win is None:
                continue
            fpath = fetch_story_footage(vid, window=win)
            if fpath and fpath not in _recent_paths():
                foot_n += 1
                foot_s += need_s
                win_uses[(vid, win)] = win_uses.get((vid, win), 0) + 1
                last_foot = (vid, win)
                log.info("GAP-FILL: scene %d would freeze/repeat a still -> "
                         "footage %s (w%d, %.2fs, %d/%d scenes)", si + 1,
                         os.path.basename(fpath), win, need_s, foot_n,
                         FOOTAGE_CK_MAX_SCENES)
                return fpath
        return None

    for si, sh in enumerate(edl):
        need_s = sh["end"] - sh["start"]
        motion = sh["motion"]
        if motion == prev_motion:
            motion = _MOTION_ALTERNATE.get(motion, "punch_build")

        path, typ, textish, src_url = None, None, False, None
        planned_here = False           # r17: this scene is a PLANNED clip shot
        footage = False                # r25: init early — GAP-FILL may set it
                                       # before the opportunistic upgrade block
        gapfill = False                # r25: this scene came from GAP-FILL
        sfx, emph_t = sh["sfx"], sh["emph_t"]
        if sh["shot_class"] == "receipt":
            rv = receipts.get(sh.get("receipt_i"))
            r_photo = isinstance(rv, dict)     # r17: og report photo entry
            path = rv.get("path") if r_photo else rv
            # r21 SCENE-LEVEL EVIDENCE CAP (filmstrip verdict: the same
            # article screenshot still carried 3 scenes via receipt_i reuse —
            # the per-index cap has an index-reuse loophole). Any single
            # evidence image backs at most 2 SCENES, full stop.
            if path and evidence_scene_uses.get(path, 0) >= 2:
                log.info("receipt image already in 2 scenes; subject photo "
                         "for variety")
                path = None
            elif path and path in _recent_paths():
                # r12 selfcheck law: the SAME card twice inside the no-repeat
                # window reads as a frozen frame — subject photo instead.
                log.info("receipt %s repeats within %d scenes; subject photo "
                         "fallback", sh.get("receipt_i"),
                         POOL_NO_REPEAT_WINDOW)
                path = None
            if path:
                # r21 fix: count WITHOUT consuming the branch (the elif version
                # swallowed the receipt-typing below -> type=None crash, run #92)
                evidence_scene_uses[path] = evidence_scene_uses.get(path, 0) + 1
            if path and r_photo:
                # r17: the article's real og:image — it IS the moment's
                # photo, so it renders as a NORMAL photo scene (cover-crop,
                # face-aware), never the contain/card path.
                typ, textish = "photo", False
                log.info("receipt %s -> og report photo (photo scene)",
                         sh.get("receipt_i"))
            elif path:
                typ, textish = "receipt", True
                if sfx == "none":            # v4.5: receipt slam default
                    sfx = "pop"
                    emph_t = sh["start"]     # slam lands AT t_in
            else:
                log.info("receipt %s missing/unresolved; subject photo "
                         "fallback", sh.get("receipt_i"))
        # v6 TASTE: a subject shot that names a person shows THAT person's
        # imagery (r11: cycling their photo LIST — avatar, recent thumbnails —
        # so consecutive shots of one person don't freeze on a single image);
        # a shot pinned to a real story image (visual_i) shows it.
        # Adjacent-duplicate guard: the SAME image on two consecutive scenes
        # reads as a frozen frame (a judge-fail) — the second one falls back
        # to the LRU pool pick instead.
        if path is None and sh["shot_class"] == "subject":
            entry = None
            pname = (sh.get("person") or "").strip().lower()
            p_entries = person_map.get(pname) if pname else None
            if p_entries:
                if isinstance(p_entries, dict):        # defensive: old shape
                    p_entries = [p_entries]
                recent = _recent_paths()
                start = person_rot.get(pname, 0)
                entry = None
                for k in range(len(p_entries)):        # first of theirs not recent
                    cand = p_entries[(start + k) % len(p_entries)]
                    if cand["path"] not in recent:
                        entry = cand
                        person_rot[pname] = (start + k + 1) % len(p_entries)
                        break
                if entry is None:                      # all recent (tiny list)
                    # r12: repeating inside the window is the one hard-fail
                    # weirdness — LRU pool pick instead of freezing on them.
                    log.info("person '%s': all %d photo(s) inside the "
                             "no-repeat window; LRU pool pick instead",
                             sh["person"], len(p_entries))
                else:
                    log.info("person shot -> %s's photo %d/%d (%s)",
                             sh["person"], p_entries.index(entry) + 1,
                             len(p_entries),
                             os.path.basename(entry["path"]))
            elif pname:
                log.info("person '%s' has no resolved photo; pool fallback",
                         sh["person"])
            if entry is None and sh.get("visual_i") is not None \
                    and sh["visual_i"] in visual_map:
                entry = visual_map[sh["visual_i"]]
                planned_here = planned_flags[si]       # r17: Director's clip order
                log.info("visual_i %d -> real story image (%s)%s",
                         sh["visual_i"], os.path.basename(entry["path"]),
                         " [PLANNED CLIP shot]" if planned_here else "")
            # r12: widened from back-to-back to the FULL no-repeat window —
            # a pinned image inside the window is exactly the "same image
            # again and again" defect the selfcheck now hard-fails on.
            if entry is not None and entry["path"] in _recent_paths():
                log.info("pinned image would repeat within %d scenes; LRU "
                         "pool pick instead", POOL_NO_REPEAT_WINDOW)
                entry = None
                planned_here = False       # r17: pin lost -> clip order lost
            if entry is not None:
                last_used[entry["path"]] = si          # r11: LRU sees pins too
                path, typ, textish = entry["path"], "photo", entry["textish"]
                src_url = entry.get("url")             # r13: footage upgrade
        if path is None and sh["shot_class"] == "broll":
            # r20 FACT GATE (filmstrip verdict: storm clouds over "on Jun 26",
            # ink-in-water over the backlash fact): generic stock may NEVER
            # play over a phrase carrying a specific fact — a digit, a date,
            # a month. Those words deserve evidence or a real face.
            _ph = sh.get("phrase", "") or ""
            if (ck_mode and story_vids) or footage_off:
                # r25/r27: generic stock is OFF — footage-first fills with a real
                # story clip; motion-lite fills with a real proof/still (the demon
                # scraper gives plenty). Generic Pexels filler is never used.
                log.info("generic stock skipped for broll scene %d "
                         "(real proof/still instead)", si + 1)
            elif re.search(r"\d|january|february|march|april|may\b|june|july|"
                           r"august|september|october|november|december",
                           _ph, re.I):
                log.info("FACT GATE: stock denied over fact phrase (%s...); "
                         "subject photo instead", _ph[:40])
            elif consec_broll >= 2:
                log.info("broll consecutive cap hit; subject photo instead")
            else:
                # v5: pass the exact narration phrase + story title so the
                # vision re-rank can judge candidates against the words.
                path = fetcher.clip_for_query(sh["query"], need_s,
                                              phrase=sh.get("phrase", ""),
                                              title=title)
                if path and path in _recent_paths():
                    # r12 belt-and-suspenders: the used-set already dedups
                    # per URL, but never let ANY path repeat in the window.
                    log.info("broll clip repeats within %d scenes; subject "
                             "photo fallback", POOL_NO_REPEAT_WINDOW)
                    path = None
                if path:
                    typ = "broll"
        if path is None:
            if pool:
                # r11 SMART FALLBACK: least-recently-used + a 3-scene
                # no-repeat window (replaces blind round-robin).
                # r15: TINY-POOL RELIEF — when the story has too few distinct
                # photos to honor the window (the selfcheck tripwire case),
                # borrow a stock b-roll scene for variety BEFORE accepting a
                # repeat; the repeat remains the true last resort.
                entry = _lru_pick(si)
                pool_variety = len({e["path"] for e in pool})
                recent_now = _recent_paths()
                # r25: in footage-first mode the tiny-pool relief is real story
                # footage (GAP-FILL below), NOT generic stock — so this stock
                # borrow is cookie-less-only now.
                if (entry["path"] in recent_now and pool_variety <= POOL_NO_REPEAT_WINDOW
                        and consec_broll < 2
                        and not ((ck_mode and story_vids) or footage_off)):
                    bp = fetcher.clip_for(need_s)
                    if bp:
                        log.info("tiny pool (%d distinct): stock variety "
                                 "instead of a repeat (scene %d)",
                                 pool_variety, si + 1)
                        path, typ = bp, "broll"
                if path is None:
                    path, typ, textish = entry["path"], "photo", entry["textish"]
                    src_url = entry.get("url")         # r13: footage upgrade
            else:
                path = fetcher.clip_for(need_s)   # last resort: cursor mode
                if path:
                    typ = "broll"
                else:
                    raise ValueError("no photos and no b-roll for a shot")
        # --- r24/r25 STILL-HOLD + GAP-FILL (owner: "the gaps between the clips
        # are dead frozen stills, the same pic keeps looking with no response").
        # The SAME still may carry at most 2 CONSECUTIVE scenes (pins included).
        # A would-be 3rd-consecutive freeze — and, in footage-first mode, ANY
        # still that repeats within the no-repeat window — is first offered a
        # DIFFERENT real still; if that alternative would itself freeze/repeat,
        # GAP-FILL turns the beat into real story footage (motion) borrowed from
        # any of the story's videos; only when no moving window exists does a
        # still hold (then with forced motion below, never a dead freeze).
        hold_capped = False
        if typ == "photo":
            _prev2 = [sc.get("path") for sc in scenes[-2:]]
            _freeze = not still_hold_ok(_prev2, path)
            if _freeze or (ck_mode and path in _recent_paths()):
                alt = _lru_pick(si) if pool else None
                if (alt is not None and alt["path"] != path
                        and still_hold_ok(_prev2, alt["path"])
                        and alt["path"] not in _recent_paths()):
                    log.info("STILL-HOLD: %s would freeze/repeat; real-still "
                             "swap -> %s", os.path.basename(path),
                             os.path.basename(alt["path"]))
                    path, textish = alt["path"], alt["textish"]
                    src_url = alt.get("url")
                    _freeze = not still_hold_ok(_prev2, path)
                if _freeze:
                    gf = _gap_footage(si, need_s)
                    if gf:
                        path, typ, textish, src_url = gf, "broll", False, None
                        motion, footage, gapfill = "punch_build", True, True
                    else:
                        hold_capped = True
        # --- r13/r17 REAL FOOTAGE: a photo scene showing a YouTube thumbnail
        # of one of the story's own videos becomes a short MUTED clip of that
        # exact video. r17: shots the Director marked clip=true are PLANNED
        # CLIPS — first claim on the budget (which rises to 4 scenes / 12s
        # when they exist; planned scenes may run 4.5s); everything else is
        # an opportunistic upgrade that must leave room for the plan. Counts
        # as b-roll, thumbnail kept on any miss. r24: with cookies the
        # budgets flip (8 scenes / min(30s, 60% of runtime); 4.5s/5.0s per
        # scene), consecutive footage is allowed, and the window rotation
        # (pick_footage_window) guarantees the same (id, window) file never
        # plays twice in a row.
        # r25: `footage` is initialised at the top of the loop — GAP-FILL above
        # may already have set it; do NOT reset it here (that would drop a
        # gap-fill clip back to a still). typ is "broll" then, so vid is None
        # and this opportunistic block is correctly skipped.
        vid = ytimg_video_id(src_url) if typ == "photo" else None
        if vid:
            prev_foot = bool(scenes and scenes[-1].get("footage"))
            res_n, res_s = (0, 0.0) if planned_here else _planned_reserve(si)
            if footage_budget_ok(need_s, foot_n, foot_s, consec_broll,
                                 prev_foot, planned=planned_here,
                                 has_planned=has_planned,
                                 reserve_n=res_n, reserve_s=res_s,
                                 cookies=ck_mode, runtime_s=runtime_s,
                                 consec_footage=consec_footage):
                tried_failed = {k for k in range(n_windows)
                                if (vid, k) in _FOOTAGE_CACHE
                                and _FOOTAGE_CACHE[(vid, k)] is None}
                win = pick_footage_window(vid, n_windows, win_uses,
                                          prev_vid=last_foot[0],
                                          prev_win=last_foot[1],
                                          failed=tried_failed)
                fpath = (fetch_story_footage(vid, window=win)
                         if win is not None else None)
                if win is None:
                    log.info("FOOTAGE %s: no spare window for a consecutive "
                             "scene; still kept", vid)
                elif fpath and fpath in _recent_paths():
                    # selfcheck law: no path twice inside the window — the
                    # same window file served twice keeps its thumbnail.
                    log.info("FOOTAGE %s w%d repeats within %d scenes; "
                             "thumbnail kept", vid, win,
                             POOL_NO_REPEAT_WINDOW)
                elif fpath and not footage_is_relevant(fpath, title):
                    # r28 SMART GATE: this yt-thumbnail's clip is off-topic (a
                    # musician's music video on a feud story) — keep the still.
                    _FOOTAGE_CACHE[(vid, win)] = None
                    log.info("FOOTAGE %s w%d off-topic (vision gate); still "
                             "kept", vid, win)
                elif fpath:
                    path, typ, textish = fpath, "broll", False
                    motion, footage = "punch_build", True
                    foot_n += 1
                    foot_s += need_s
                    win_uses[(vid, win)] = win_uses.get((vid, win), 0) + 1
                    last_foot = (vid, win)
                    if ck_mode:
                        eff_n = FOOTAGE_CK_MAX_SCENES
                        eff_s = FOOTAGE_CK_MAX_TOTAL_S
                        if runtime_s > 0:
                            eff_s = min(eff_s, FOOTAGE_CK_MAX_TOTAL_FRAC
                                        * runtime_s)
                    else:
                        eff_n = (FOOTAGE_PLANNED_MAX_SCENES if has_planned
                                 else FOOTAGE_MAX_SCENES)
                        eff_s = (FOOTAGE_PLANNED_MAX_TOTAL_S if has_planned
                                 else FOOTAGE_MAX_TOTAL_S)
                    log.info("FOOTAGE %s: scene %d -> %s (w%d, %.2fs shown, "
                             "%d/%d scenes, %.1f/%.1fs borrowed)",
                             "PLANNED CLIP" if planned_here
                             else "opportunistic upgrade", si + 1,
                             os.path.basename(fpath), win, need_s, foot_n,
                             eff_n, foot_s, eff_s)
                elif planned_here:
                    log.info("PLANNED CLIP unavailable for %s (bot-wall/"
                             "miss); the moment's photo stands", vid)
                else:
                    log.info("FOOTAGE unavailable for %s; thumbnail "
                             "fallback", vid)
        # r28 MULTI-PLATFORM CLIP FOOTAGE: a still beat becomes a REAL clip from
        # the story's harvested Twitch/TikTok/Kick/YouTube URLs (article-embedded
        # = highly relevant). Twitch/TikTok/Kick need no cookies. Priority over a
        # plain still; obeys the footage budget + the consecutive-footage cap.
        if (not footage and typ == "photo" and clip_pool
                and consec_footage < FOOTAGE_CK_MAX_CONSEC):
            prev_foot = bool(scenes and scenes[-1].get("footage"))
            res_n, res_s = _planned_reserve(si)
            if footage_budget_ok(need_s, foot_n, foot_s, consec_broll,
                                 prev_foot, planned=False,
                                 has_planned=has_planned,
                                 reserve_n=res_n, reserve_s=res_s,
                                 cookies=True, runtime_s=runtime_s,
                                 consec_footage=consec_footage):
                cpath = None
                while clip_pool and cpath is None:
                    cand = fetch_platform_clip(clip_pool.pop(0))
                    if cand and cand not in _recent_paths():
                        cpath = cand
                if cpath:
                    path, typ, textish = cpath, "broll", False
                    motion, footage = "punch_build", True
                    foot_n += 1
                    foot_s += need_s
                    last_foot = (None, None)
                    log.info("CLIP FOOTAGE: scene %d -> %s", si + 1,
                             os.path.basename(cpath))
        if not footage:
            last_foot = (None, None)   # r24: rotation rule is per-run-in-a-row
            if hold_capped:
                # r25: it MUST hold (one-image pool, no moving window). Never a
                # dead contain freeze — force a Ken Burns push so even the held
                # still keeps moving (unless it is a text card that must stay
                # readable). This is the true last resort, logged loudly.
                if not textish and motion in (None, "contain"):
                    motion = "punch_build"
                log.info("STILL-FROZEN (last resort): no alt + no footage "
                         "window for %s; held with motion=%s",
                         os.path.basename(path), motion)
        # r25: real footage runs on its OWN consecutive counter (see
        # footage_budget_ok); stock/other b-roll still uses consec_broll.
        consec_footage = consec_footage + 1 if footage else 0
        consec_broll = consec_broll + 1 if typ == "broll" else 0

        emph_rel = None
        if sh["emph_t"] is not None:
            emph_rel = sh["emph_t"] - sh["start"]
        scenes.append({
            "start": sh["start"], "end": sh["end"], "type": typ,
            "path": path, "motion": "contain" if textish else motion,
            "textish": textish, "emph_rel": emph_rel,
            "sfx": sfx, "music": sh["music"], "emph_t": emph_t,
            "footage": footage, "gapfill": gapfill, "frozen": hold_capped,
            "src_off": FOOTAGE_SUB_OFF_S if footage else None,
        })
        prev_motion = motion

    # r25 RENDER REPORT (owner: "your watch and still no progress" — stop
    # guessing from filmstrips): a compact record of what the planner actually
    # did, posted back with the video so decisions are visible without GitHub
    # Actions log access. ck_mode off here is the single biggest tell (footage
    # -first dormant); frozen>0 means a still still had to hold.
    _ck_path = yt_cookies_file()
    n_foot = sum(1 for s in scenes if s.get("footage"))
    n_gap = sum(1 for s in scenes if s.get("gapfill"))
    n_frozen = sum(1 for s in scenes if s.get("frozen"))
    n_card = sum(1 for s in scenes if s.get("textish"))
    n_still = sum(1 for s in scenes
                  if s.get("type") == "photo" and not s.get("footage"))
    _RENDER_REPORT.clear()
    _RENDER_REPORT.update({
        "ck_mode": bool(ck_mode),
        "cookie_bytes": (os.path.getsize(_ck_path) if _ck_path else 0),
        "story_vids": len(story_vids),
        "scenes": len(scenes),
        "runtime_s": round(runtime_s, 1),
        "footage_scenes": n_foot,
        "gap_fill_scenes": n_gap,
        "opportunistic_footage": max(0, n_foot - n_gap),
        "still_photo_scenes": n_still,
        "card_scenes": n_card,
        "frozen_stills": n_frozen,
        "footage_fetches": _FOOTAGE_FETCHES,
        "seq": "".join(("F" if s.get("gapfill") else
                        "f" if s.get("footage") else
                        "c" if s.get("textish") else "s") for s in scenes),
    })
    log.info("RENDER REPORT: %s", json.dumps(_RENDER_REPORT))
    return scenes


# ============================================================================
# r14: CLIP VERIFYING EYE — quota-free render-time check that each photo
# scene's image actually matches the words spoken over it (the runner-side
# half of "build sight at both ends"; the server half is visual_sight.php).
# sentence-transformers CLIP ViT-B-32 on the runner CPU (~0.2-0.5s/image,
# see videorepos/DIRECTOR-UPGRADE-RESEARCH.md §3.6). NEVER fatal: missing
# install, failed model download, any exception -> scenes unchanged.
# ============================================================================
_CLIP_MODEL = None             # None = untried, False = unavailable


def _clip_model():
    """Lazy-load CLIP ViT-B-32; tolerate missing install/download (None)."""
    global _CLIP_MODEL
    if _CLIP_MODEL is False:
        return None
    if _CLIP_MODEL is None:
        try:
            from sentence_transformers import SentenceTransformer
            t0 = time.time()
            _CLIP_MODEL = SentenceTransformer("clip-ViT-B-32")
            log.info("CLIP verify: clip-ViT-B-32 loaded in %.1fs",
                     time.time() - t0)
        except Exception as exc:  # noqa: BLE001
            log.info("CLIP verify unavailable (%s); skipped", exc)
            _CLIP_MODEL = False
            return None
    return _CLIP_MODEL


def clip_swap_decisions(paths, checkable, pool_paths, score_fn,
                        window=POOL_NO_REPEAT_WINDOW,
                        min_score=CLIP_SWAP_MIN, margin=CLIP_SWAP_MARGIN):
    """Pure r14 swap chooser (unit-testable offline, no model needed).
    paths: current image path per scene (every scene, any type);
    checkable[i]: True when scene i is a plain photo scene eligible for
    verification; pool_paths: candidate replacement paths (non-textish pool
    entries with embeddings); score_fn(i, path) -> cosine of that image vs
    scene i's narration phrase, or None when unknown.
    A scene swaps only when its own score < min_score AND some candidate —
    not the same image, not within the no-repeat window on either side —
    scores >= score + margin; the best such candidate wins. Swaps apply
    sequentially so later windows see earlier swaps.
    Returns [(scene_i, old_path, new_path, old_score, new_score), ...]."""
    paths = list(paths)
    out = []
    for i, cur in enumerate(paths):
        if i >= len(checkable) or not checkable[i]:
            continue
        s = score_fn(i, cur)
        if s is None or s >= min_score:
            continue
        lo, hi = max(0, i - window), min(len(paths), i + window + 1)
        nearby = {paths[k] for k in range(lo, hi) if k != i}
        best, best_s = None, None
        for p in pool_paths:
            if p == cur or p in nearby:
                continue
            ps = score_fn(i, p)
            if ps is None or ps < s + margin:
                continue
            if best_s is None or ps > best_s:
                best, best_s = p, ps
        if best is not None:
            out.append((i, cur, best, s, best_s))
            paths[i] = best
    return out


def clip_verify_scenes(scenes, edl, pool):
    """r14: verify photo scenes against their narration phrases and swap the
    clear mismatches (mutates scenes in place). Guardrails: person-pinned
    shots are never touched (the person law outranks CLIP), text-heavy /
    receipt / broll / footage scenes are skipped, swapped-in candidates are
    non-textish pool photos, the no-repeat window is respected, and at most
    CLIP_MAX_ENCODES images are encoded (pool once, embeddings reused).
    Never fatal; logs a summary line either way."""
    if not CLIP_VERIFY:
        log.info("CLIP verify disabled (VIDEO_CLIP_VERIFY=0)")
        return
    try:
        if not scenes or not edl or len(scenes) != len(edl):
            return
        checkable = []
        for i, sc in enumerate(scenes):
            sh = edl[i]
            checkable.append(
                sc.get("type") == "photo" and not sc.get("textish")
                and not sc.get("footage") and not sh.get("person")
                # r17: og report photos ride receipt shots as photo scenes —
                # they are pinned EVIDENCE, CLIP must never swap them out.
                and sh.get("shot_class") != "receipt"
                and bool((sh.get("phrase") or "").strip()))
        if not any(checkable):
            log.info("CLIP verify: no checkable photo scenes; skipped")
            return
        model = _clip_model()
        if model is None:
            return
        # Encode set: checked scene images first, then pool candidates —
        # capped so one video never costs more than ~CLIP_MAX_ENCODES.
        cand_pool = [e["path"] for e in pool if not e.get("textish")]
        img_order = []
        for i, sc in enumerate(scenes):
            if checkable[i] and sc["path"] not in img_order:
                img_order.append(sc["path"])
        for p in cand_pool:
            if p not in img_order:
                img_order.append(p)
        img_order = img_order[:CLIP_MAX_ENCODES]
        imgs, keys = [], []
        for p in img_order:
            try:
                with Image.open(p) as im:
                    imgs.append(im.convert("RGB").copy())
                keys.append(p)
            except Exception:  # noqa: BLE001
                pass
        if not imgs:
            return
        iv = model.encode(imgs, batch_size=8, convert_to_numpy=True,
                          normalize_embeddings=True, show_progress_bar=False)
        embs = dict(zip(keys, iv))
        idxs = [i for i in range(len(scenes)) if checkable[i]]
        tv = model.encode([str(edl[i]["phrase"])[:200] for i in idxs],
                          convert_to_numpy=True, normalize_embeddings=True,
                          show_progress_bar=False)
        temb = dict(zip(idxs, tv))

        def score_fn(i, path):
            e, t = embs.get(path), temb.get(i)
            if e is None or t is None:
                return None
            return float(np.dot(e, t))

        pool_paths = [p for p in cand_pool if p in embs]
        swaps = clip_swap_decisions([sc["path"] for sc in scenes], checkable,
                                    pool_paths, score_fn)
        for i, old, new, s_old, s_new in swaps:
            scenes[i]["path"] = new       # candidates are non-textish photos
            log.info("CLIP swap: scene %d (%.3f -> %.3f) %s -> %s",
                     i + 1, s_old, s_new, os.path.basename(old),
                     os.path.basename(new))
        log.info("CLIP verify: %d scene(s) checked, %d swap(s), %d image(s) "
                 "encoded", len(idxs), len(swaps), len(embs))
    except Exception as exc:  # noqa: BLE001
        log.warning("CLIP verify failed (%s); scenes unchanged", exc)


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


def scene_clip(image_path, start, end, motion, emph_rel=None, xfade=None,
               face=None):
    """One full-frame photo scene with its own motion. v3 motions ('in',
    'out', 'panl', 'panr') keep their behaviour; v4 EDL motions ('punch_hit',
    'punch_build', 'zoom_out', 'pan_left', 'pan_right') run the Law-6 curves
    (snap zoom AT the emphasis word, eased build, settle-out). Pans on
    portrait sources become vertical pans. The HOUSE GRADE is baked into the
    source array once (zero per-frame cost). `xfade=0` -> hard cut (v4);
    default keeps the v3 crossfade.
    v6 `face`: an (x, y, w, h) face box in source pixels. The crop is then
    eyeline-framed (cover_fit_face) and every zoom is ANCHORED on the face
    point — the image scales around the eyeline, so motion drift can never
    push the face out of the phone-safe zone. Pans on face photos become
    face-anchored zooms (a pan is exactly the motion that walks a face off
    frame). face=None -> the exact pre-v6 behaviour."""
    from moviepy import CompositeVideoClip, ImageClip, vfx

    if xfade is None:
        xfade = XFADE
    motion = {"pan_left": "panl", "pan_right": "panr"}.get(motion, motion)
    if face is not None and motion in ("panl", "panr"):
        motion = "in" if motion == "panl" else "out"
    dur = max(end - start, 0.2)
    pil = Image.open(image_path)
    src_w, src_h = pil.size
    portrait = src_h > src_w

    if motion in ("panl", "panr") and not portrait:
        bw = int(W * PAN_SCALE)
        base = ImageClip(grade_frame(np.array(cover_fit(pil, bw, H)))
                         ).with_duration(dur)
        travel = float(bw - W)
        x0, x1 = (0.0, -travel) if motion == "panl" else (-travel, 0.0)

        def _pos(t, x0=x0, x1=x1, d=dur):
            return (x0 + (x1 - x0) * (t / d), 0)

        moving = base.with_position(_pos)
    elif motion in ("panl", "panr"):
        bh = int(H * PAN_SCALE)
        base = ImageClip(grade_frame(np.array(cover_fit(pil, W, bh)))
                         ).with_duration(dur)
        travel = float(bh - H)
        y0, y1 = (0.0, -travel) if motion == "panl" else (-travel, 0.0)

        def _pos(t, y0=y0, y1=y1, d=dur):
            return (0, y0 + (y1 - y0) * (t / d))

        moving = base.with_position(_pos)
    else:
        face_pt = None
        if face is not None:
            try:
                fitted, face_pt = cover_fit_face(pil, W, H, face)
            except Exception as exc:  # noqa: BLE001
                log.warning("face framing failed (%s); center crop", exc)
                fitted, face_pt = cover_fit(pil, W, H), None
        else:
            fitted = cover_fit(pil, W, H)
        base = ImageClip(grade_frame(np.array(fitted))).with_duration(dur)
        if motion in ("punch_hit", "punch_build", "zoom_out"):
            _scale = motion_scale_fn(motion, dur, emph_rel)
        elif motion == "out":
            def _scale(t, d=dur):
                return max(1.001, 1.0 + SCENE_ZOOM - SCENE_ZOOM * (t / d))
        else:
            def _scale(t, d=dur):
                return max(1.001, 1.0 + SCENE_ZOOM * (t / d))
        if face_pt is not None:
            # anchor the zoom ON the face: position so the eyeline point
            # stays fixed at its framed coordinate for every scale s>=1
            # (px = fx*(1-s) <= 0 and the frame stays fully covered).
            fxp, fyp = face_pt

            def _pos(t, fxp=fxp, fyp=fyp, sc=_scale):
                s = sc(t)
                return (fxp * (1.0 - s), fyp * (1.0 - s))

            moving = base.resized(_scale).with_position(_pos)
        else:
            moving = base.resized(_scale).with_position("center")
    pil.close()

    clip = CompositeVideoClip([moving], size=(W, H)).with_duration(dur)
    clip = clip.with_start(start)
    if xfade > 0 and start > 0:
        try:
            clip = clip.with_effects([vfx.CrossFadeIn(min(xfade, dur / 2))])
        except Exception as exc:  # noqa: BLE001
            log.warning("crossfade unavailable (%s); hard cut", exc)
    return clip


def contain_scene_clip(image_path, start, end, xfade=None, card=False):
    """v3 text-heavy renderer: the WHOLE image fits inside the frame
    ('contain') over a blurred darkened fill of itself — no cover-crop, no
    Ken-Burns zoom, only a gentle <=2% horizontal drift so the scene is not
    dead-static. This is what posters/cards/receipts/screenshots get.
    v4: house grade baked into the composed canvas; xfade=0 -> hard cut."""
    from moviepy import CompositeVideoClip, ImageClip, vfx
    from PIL import ImageEnhance, ImageFilter

    if xfade is None:
        xfade = XFADE
    dur = max(end - start, 0.2)
    drift = max(2, int(W * TEXTISH_DRIFT))
    pil = Image.open(image_path).convert("RGB")

    canvas_w = W + drift                       # oversize -> room to drift
    bg = cover_fit(pil, canvas_w, H).filter(ImageFilter.GaussianBlur(32))
    bg = ImageEnhance.Brightness(bg).enhance(0.45)

    w, h = pil.size
    if card:
        # v9/r25: card scenes anchor toward the top so the caption band below
        # stays clear — top at CARD_TOP_Y, bottom capped at CARD_MAX_BOTTOM.
        # r25: 0.80 -> 0.94 width so a real proof (screenshot / X post) fills
        # the phone instead of sitting small and letterboxed.
        scale = min((W * 0.94) / w, float(CARD_MAX_BOTTOM - CARD_TOP_Y) / h)
    else:
        scale = min((W * 0.94) / w, (H * 0.90) / h)
    fg = pil.resize((max(1, int(w * scale)), max(1, int(h * scale))),
                    Image.Resampling.LANCZOS)
    canvas = bg.copy()
    fg_y = CARD_TOP_Y if card else (H - fg.height) // 2
    canvas.paste(fg, ((canvas_w - fg.width) // 2, fg_y))
    pil.close()

    base = ImageClip(grade_frame(np.array(canvas))).with_duration(dur)

    # r25 motion-lite: cards were nearly frozen (2% drift, no zoom) — the owner
    # paused on exactly these and saw dead frames. Give them a gentle push-in
    # (still fully readable — the whole card stays in frame, just grows) plus a
    # slow horizontal drift. Centered while zooming so no bars/edges show.
    cw, ch = float(canvas_w), float(H)

    def _cscale(t, d=dur):
        return 1.0 + CARD_ZOOM * (t / d)

    def _pos(t, d=dur, cw=cw, ch=ch, px=float(drift)):
        s = 1.0 + CARD_ZOOM * (t / d)
        x = (W - cw * s) / 2.0 + px * (0.5 - t / d)   # center + slow drift
        y = (H - ch * s) / 2.0
        return (x, y)

    clip = CompositeVideoClip([base.resized(_cscale).with_position(_pos)],
                              size=(W, H)).with_duration(dur)
    clip = clip.with_start(start)
    if xfade > 0 and start > 0:
        try:
            clip = clip.with_effects([vfx.CrossFadeIn(min(xfade, dur / 2))])
        except Exception as exc:  # noqa: BLE001
            log.warning("crossfade unavailable (%s); hard cut", exc)
    return clip


def broll_scene_clip(video_path, start, end, motion=None, emph_rel=None,
                     xfade=None, t_off=None):
    """One full-frame B-ROLL scene — trim to the beat length, cover-crop to
    1080x1920 (MoviePy 2.x .subclipped/.resized/.cropped), darken slightly so
    the captions pop over busy footage. v4: the house grade runs per-frame
    (vectorized numpy via image_transform) and EDL zoom motions (punch_hit /
    punch_build / zoom_out) are applied on top of the cover-crop — pans on
    video sources map to punch_build. xfade=0 -> hard cut.
    r13: t_off (real-footage scenes) trims the sub-segment starting that
    many seconds into the source instead of the default slate-skip; audio is
    ALWAYS stripped (.without_audio) — footage is muted by construction.
    Returns (clip, source): the VideoFileClip must stay OPEN until after
    write_videofile — the caller closes it."""
    from moviepy import CompositeVideoClip, VideoFileClip, vfx

    if xfade is None:
        xfade = XFADE
    dur = max(end - start, 0.2)
    src = VideoFileClip(video_path)
    clip = src.without_audio()
    if clip.duration and clip.duration > dur + 0.05:
        if t_off is not None:                        # r13 real footage:
            off = max(0.0, min(float(t_off),         # start 2s into the
                               clip.duration - dur - 0.05))  # fetched window
        else:
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
    try:
        clip = clip.image_transform(grade_frame)      # v4 house grade
    except Exception as exc:  # noqa: BLE001
        log.warning("broll grade unavailable (%s)", exc)

    if motion in ("punch_hit", "punch_build", "zoom_out", "pan_left",
                  "pan_right"):
        if motion in ("pan_left", "pan_right"):       # video pans -> build
            motion = "punch_build"
        try:
            clip = clip.resized(motion_scale_fn(motion, dur, emph_rel))
        except Exception as exc:  # noqa: BLE001
            log.warning("broll motion unavailable (%s)", exc)

    out = CompositeVideoClip([clip.with_position("center")],
                             size=(W, H)).with_duration(dur)
    out = out.with_start(start)
    if xfade > 0 and start > 0:
        try:
            out = out.with_effects([vfx.CrossFadeIn(min(xfade, dur / 2))])
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


def chunk_caption_clips(beats, hook_end, duration, font_path, card_windows=None):
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
            mid = (st + en) / 2.0
            y_center = CAPTION_CENTER_Y
            for cw_s, cw_e in (card_windows or []):
                if cw_s <= mid < cw_e:      # v9: this word plays over a card
                    y_center = CARD_CAPTION_Y
                    break
            ic = ImageClip(arr, transparent=True)
            ic = ic.with_start(st).with_end(en).with_position(
                ((W - arr.shape[1]) / 2.0,
                 y_center - arr.shape[0] / 2.0))
            clips.append(ic)
    return clips


# ============================================================================
# Background music (optional, deterministic, non-fatal)
# ============================================================================
def pick_bgm(page_id, grave=False):
    """Deterministically pick a track from BGM_DIR by page_id hash. The folder
    must contain ONLY CC0/royalty-free .mp3 files. Missing/empty -> None.
    r16 GRAVITY: a grave story never gets a tension/trap bed — it takes the
    lowest-energy ambient track (filename containing 'ambient' or 'echoes'),
    else the first file (our kit sorts bgm_1.mp3 = 'Echoes', dark ambient)."""
    try:
        files = sorted(glob.glob(os.path.join(BGM_DIR, "*.mp3")))
        if not files:
            return None
        if grave:
            calm = [f for f in files
                    if "ambient" in os.path.basename(f).lower()
                    or "echoes" in os.path.basename(f).lower()]
            track = calm[0] if calm else files[0]
            log.info("bgm (GRAVE story -> ambient bed): %s", track)
            return track
        idx = int(hashlib.md5(str(page_id).encode()).hexdigest(), 16) % len(files)
        log.info("bgm: %s (%d candidate(s))", files[idx], len(files))
        return files[idx]
    except Exception as exc:  # noqa: BLE001
        log.warning("bgm selection failed (%s); staying silent", exc)
        return None


# ============================================================================
# v4: SOUND ENGINE (Laws 12-19) — pydub mix built BEFORE the video encode.
# Assets: BGM_DIR/*.mp3 beds + SFX_DIR/{whoosh,riser,impact,pop}_*.mp3.
# Missing folders/files -> that layer silently skipped; ANY failure -> None
# and the caller runs the v3 voice+bgm path instead. NEVER fatal.
#
# LOUDNESS ROUTE (documented design decision): after mixing, the track is
# gain-normalized with pydub toward -14 dBFS average (approx -14 LUFS; dBFS
# is an RMS proxy, close enough for speech-led shorts) and capped so the
# sample peak stays <= -1.5 dBFS. This runs on the audio BEFORE it is
# attached to the video, so no second video encode is needed — an ffmpeg
# `loudnorm` filter at the remux step would have forced re-encoding the
# audio inside an existing mux (or a 2nd pass); this is the simpler,
# equally effective route at our scale.
# ============================================================================
def _sfx_files(category):
    """All kit files for one category by filename prefix, sorted (stable
    rotation). Missing folder/empty category -> []."""
    try:
        return sorted(glob.glob(os.path.join(SFX_DIR, category + "_*.mp3")))
    except Exception:  # noqa: BLE001
        return []


def _pick_variant(files, salt):
    """Deterministic variant rotation (Law 16: rotate 3-5 variants so a
    repeated sound never becomes a habit)."""
    if not files:
        return None
    idx = int(hashlib.md5(str(salt).encode()).hexdigest(), 16) % len(files)
    return files[idx]


def _load_seg(path):
    """AudioSegment or None; silent-file (-inf dBFS) and decode failures are
    both treated as missing."""
    try:
        from pydub import AudioSegment
        seg = AudioSegment.from_file(path)
        if len(seg) == 0 or seg.dBFS == float("-inf"):
            return None
        return seg
    except Exception as exc:  # noqa: BLE001
        log.warning("sfx/bgm decode failed (%s): %s", exc, path)
        return None


def _music_intervals(scenes, total_ms):
    """Per-shot music states -> merged [start_ms, end_ms, extra_db] intervals
    of AUDIBLE bed. 'silence' shots produce a gap that OPENS 300ms before the
    shot (Law 17: music out just before the reveal) and CLOSES at the next
    shot's start (the slam-back). 'duck' carries an extra -4dB."""
    spans = []
    for i, sc in enumerate(scenes):
        a = 0 if i == 0 else int(sc["start"] * 1000)
        b = total_ms if i == len(scenes) - 1 else int(scenes[i + 1]["start"]
                                                      * 1000)
        spans.append([a, max(a, b), sc.get("music") or "bed"])
    lead = int(SILENCE_LEAD_S * 1000)
    for i, sp in enumerate(spans):
        if sp[2] == "silence" and i > 0:
            sp[0] = max(spans[i - 1][0], sp[0] - lead)
            spans[i - 1][1] = sp[0]
    out = []
    for a, b, state in spans:
        if state == "silence" or b - a <= 0:
            continue
        db = DUCK_EXTRA_DB if state == "duck" else 0.0
        if out and out[-1][1] >= a and out[-1][2] == db:
            out[-1][1] = b
        else:
            out.append([a, b, db])
    return out


def build_sound_mix(mp3_path, scenes, total, page_id, out_wav,
                    extra_sfx=None):
    """The full v4 mix: normalized VO + stateful music bed + placed SFX +
    final loudness pass. Returns out_wav, or None -> v3 audio fallback.
    r12 extra_sfx: [(category, t_seconds)] one-off cues outside the shotlist
    (the pattern-interrupt impact); missing kit files are silently skipped."""
    try:
        from pydub import AudioSegment
        AudioSegment.converter = _ffmpeg_bin()

        total_ms = int(total * 1000)
        vo = AudioSegment.from_file(mp3_path)
        if vo.dBFS == float("-inf"):
            raise RuntimeError("voice track is silent")
        vo = vo.apply_gain(VO_TARGET_DBFS - vo.dBFS)   # Law 12 anchor
        vo_db = vo.dBFS
        mix = AudioSegment.silent(duration=total_ms, frame_rate=44100)
        mix = mix.overlay(vo)

        # ---- music bed (deterministic pick, loop, -18dB vs VO, states) ----
        bed_file = pick_bgm(page_id)
        bed = _load_seg(bed_file) if bed_file else None
        if bed is not None:
            while len(bed) < total_ms:
                bed = bed + bed
            bed = bed[:total_ms]
            bed = bed.apply_gain((vo_db + BED_DB_VS_VO) - bed.dBFS)
            intervals = _music_intervals(scenes, total_ms)
            for k, (a, b, extra_db) in enumerate(intervals):
                piece = bed[a:b]
                if extra_db:
                    piece = piece.apply_gain(extra_db)
                fi = BED_MASTER_FADE_MS if k == 0 else SEAM_FADE_MS
                fo = (BED_MASTER_FADE_MS if k == len(intervals) - 1
                      else SEAM_FADE_MS)
                half = max(1, len(piece) // 2)
                piece = piece.fade_in(min(fi, half)).fade_out(min(fo, half))
                mix = mix.overlay(piece, position=a)
            log.info("sound: bed %s over %d interval(s)",
                     os.path.basename(bed_file), len(intervals))
        else:
            log.info("sound: no music bed (folder empty/undecodable)")

        # ---- SFX placement (Law 15; budget respected upstream by the
        #      Director — we place exactly what the shotlist asked for) ----
        kits = {c: _sfx_files(c) for c in ("whoosh", "riser", "impact",
                                           "pop")}
        placed = 0
        for i, sc in enumerate(scenes):
            cue = sc.get("sfx") or "none"
            if cue == "none":
                continue
            files = kits.get(cue) or []
            f = _pick_variant(files, f"{page_id}-{i}-{cue}")
            if not f:
                continue
            seg = _load_seg(f)
            if seg is None:
                continue
            if cue == "whoosh":
                seg = seg.apply_gain((vo_db + WHOOSH_DB_VS_VO) - seg.dBFS)
                pos = int(sc["start"] * 1000)
            elif cue == "impact":
                seg = seg.apply_gain((vo_db + IMPACT_DB_VS_VO) - seg.dBFS)
                pos = int(sc["start"] * 1000)
            elif cue == "pop":
                seg = seg.apply_gain((vo_db + POP_DB_VS_VO) - seg.dBFS)
                t = sc.get("emph_t")
                pos = int((t if t is not None else sc["start"]) * 1000)
            else:                                      # riser
                if i + 1 >= len(scenes):
                    continue                           # nothing to rise INTO
                if len(seg) > int(RISER_MAX_S * 1000):
                    seg = seg[-int(RISER_MAX_S * 1000):]   # keep the peak end
                seg = seg.apply_gain((vo_db + RISER_DB_VS_VO) - seg.dBFS)
                seg = seg.fade_in(SEAM_FADE_MS)
                pos = int(scenes[i + 1]["start"] * 1000) - len(seg)
            seg = seg.fade_out(SEAM_FADE_MS)           # Law 19 at SFX tails
            mix = mix.overlay(seg, position=max(0, min(pos, total_ms - 1)))
            placed += 1
        # r12: one-off cues outside the shotlist (pattern-interrupt impact)
        for cue, t in (extra_sfx or []):
            files = kits.get(cue) or []
            f = _pick_variant(files, f"{page_id}-extra-{cue}-{t}")
            seg = _load_seg(f) if f else None
            if seg is None:
                continue
            seg = seg.apply_gain((vo_db + IMPACT_DB_VS_VO) - seg.dBFS)
            seg = seg.fade_out(SEAM_FADE_MS)
            pos = int(float(t) * 1000)
            mix = mix.overlay(seg, position=max(0, min(pos, total_ms - 1)))
            placed += 1
        log.info("sound: %d SFX placed", placed)

        # ---- final loudness (see route note above) ----
        gain = MIX_TARGET_DBFS - mix.dBFS
        gain = min(gain, MIX_TRUE_PEAK_DBFS - mix.max_dBFS)
        mix = mix.apply_gain(gain)
        log.info("sound: final %.1f dBFS avg / %.1f dBFS peak",
                 mix.dBFS, mix.max_dBFS)
        mix.export(out_wav, format="wav")
        if not os.path.exists(out_wav) or os.path.getsize(out_wav) < 1000:
            raise RuntimeError("mix export produced no file")
        return out_wav
    except Exception as exc:  # noqa: BLE001
        log.warning("v4 sound engine failed (%s); v3 voice+bgm fallback", exc)
        return None


# ============================================================================
# r12: PRE-ENCODE SELFCHECK — cheap, deterministic, no AI. Runs on the planned
# scene list BEFORE any frame is rendered. Only the image-repeat assertion is
# fatal (SelfCheckFailed -> no delivery, no done-mark, retried next run);
# short scenes and thin caption coverage are logged warnings.
# Pure function (stdlib only) so it unit-tests offline without moviepy/numpy.
# ============================================================================
class SelfCheckFailed(RuntimeError):
    """Pre-encode selfcheck failed hard: do NOT encode/deliver/mark done."""


def selfcheck_scenes(scenes, avail_assets, speech_span=0.0, caption_gap=0.0,
                     window=3, min_shot_s=0.8, min_caption_cov=0.8):
    """Inspect a planned scene list. Returns a result dict:
      eff_window        the applied no-repeat window (relaxed when the total
                        distinct asset count is smaller than window+1 — you
                        cannot demand 4-way variety from 2 images)
      repeats           [(earlier_i, later_i, path)] image-path reuses inside
                        eff_window (the HARD-fail set)
      short_scenes      [(i, dur)] scenes shorter than min_shot_s (warn only)
      caption_coverage  fraction of the speech span covered by hook+captions
      coverage_ok       caption_coverage >= min_caption_cov (warn only)"""
    # r15 fix: the window must relax against the PHOTO variety actually
    # rotating (receipts/cards inflate avail_assets — the run-56 tripwire
    # fired on a 3-photo story because assets counted 14).
    photo_paths = {sc.get("path") for sc in scenes
                   if sc.get("type") == "photo" and sc.get("path")}
    variety = len(photo_paths) if photo_paths else int(avail_assets)
    eff_window = max(0, min(int(window), min(int(avail_assets), variety) - 1))
    # r25 motion-lite (footage is bot-walled, so small real-photo pools are
    # normal and a relevant photo MUST sometimes reappear): a repeat with a
    # DIFFERENT camera move is a normal edit, not a defect — hard-fail only on a
    # truly FROZEN frame (the SAME image on 3 consecutive scenes, which the
    # still-hold gate already prevents; this is the safety net). Nearer repeats
    # are reported as `soft` (warn only), so a 6-photo/10-scene story still
    # delivers instead of retry-looping into the job timeout.
    repeats = []            # HARD: 3+ consecutive identical path (frozen)
    soft_repeats = []       # reappears within window but not frozen (warn)
    for i, sc in enumerate(scenes):
        p = sc.get("path")
        if not p:
            continue
        if (i >= 2 and scenes[i - 1].get("path") == p
                and scenes[i - 2].get("path") == p):
            repeats.append((i - 2, i, p))
            continue
        for j in range(max(0, i - eff_window), i):
            if scenes[j].get("path") == p:
                soft_repeats.append((j, i, p))
                break
    short_scenes = []
    for i, sc in enumerate(scenes):
        try:
            dur = float(sc["end"]) - float(sc["start"])
        except (KeyError, TypeError, ValueError):
            continue
        if dur < min_shot_s:
            short_scenes.append((i, round(dur, 3)))
    coverage = 1.0
    if speech_span and speech_span > 0:
        coverage = max(0.0, min(1.0, 1.0 - (max(0.0, caption_gap)
                                            / float(speech_span))))
    return {"eff_window": eff_window, "repeats": repeats,
            "soft_repeats": soft_repeats,
            "short_scenes": short_scenes, "caption_coverage": coverage,
            "coverage_ok": coverage >= min_caption_cov}


# ============================================================================
# r12: PRODUCED TRANSITIONS — at story-beat changes ONLY (shots the Director
# marked sfx='whoosh'), the hard cut is dressed with a short overlay built
# from the outgoing shot's last frame and the incoming shot's first frame:
#   whip  = 3-frame horizontal whip-blur slide (the whip-pan idea)
#   zoom  = fast cross-zoom punch (the gl_CrossZoom idea, ported visually)
# Pure numpy/PIL, no new deps; variants rotate; max TRANSITION_MAX per video;
# ANY failure -> the hard cut we already had. Captions stay above (the
# overlay is inserted below vignette/scrim/hook/caption layers).
# ============================================================================
def _hbox_blur(arr, k):
    """Horizontal box blur, radius k px, via cumsum (cheap, pure numpy)."""
    if k < 2:
        return arr
    f = arr.astype(np.float32)
    pad = np.pad(f, ((0, 0), (k, k), (0, 0)), mode="edge")
    c = np.cumsum(pad, axis=1)
    out = (c[:, 2 * k:, :] - c[:, :-2 * k, :]) / float(2 * k)
    return np.clip(out, 0.0, 255.0).astype(np.uint8)


def _zoom_frame(arr, s):
    """Center-zoom a full frame by scale s>=1 (PIL resize + center crop)."""
    if s <= 1.001:
        return arr
    img = Image.fromarray(arr)
    nw, nh = int(round(W * s)), int(round(H * s))
    img = img.resize((nw, nh), Image.Resampling.BILINEAR)
    left, top = (nw - W) // 2, (nh - H) // 2
    return np.asarray(img.crop((left, top, left + W, top + H)))


def _whip_frames(f_out, f_in):
    """3-frame horizontal whip-blur slide from f_out to f_in."""
    pano = np.concatenate([f_out, f_in], axis=1)      # (H, 2W, 3)
    frames = []
    n = TRANSITION_WHIP_FRAMES
    for i in range(1, n + 1):
        p = i / float(n + 1)
        x = int(round(p * W))
        win = pano[:, x:x + W]
        k = int(90 * math.sin(math.pi * p))           # blur peaks mid-whip
        frames.append(_hbox_blur(win, k))
    return frames


def _crosszoom_frames(f_out, f_in):
    """Fast cross-zoom punch: out zooms IN hard, snaps to in zooming home."""
    frames = []
    n = TRANSITION_ZOOM_FRAMES
    for i in range(1, n + 1):
        # p reaches 1.0 on the last frame -> scale lands exactly at 1.0 on
        # the incoming image (no settle pop when the real scene takes over).
        p = i / float(n)
        if p < 0.5:
            src, s = f_out, 1.0 + 0.6 * (p / 0.5)
        else:
            src, s = f_in, 1.0 + 0.6 * ((1.0 - p) / 0.5)
        k = int(36 * math.sin(math.pi * p))           # radial-ish rush
        frames.append(_hbox_blur(_zoom_frame(src, s), k))
    return frames


def build_transitions(scenes, scene_clips):
    """Overlay clips for up to TRANSITION_MAX whoosh boundaries. Never
    raises; every failure is just the hard cut that was there anyway."""
    from moviepy import ImageSequenceClip

    out, used, variant = [], 0, 0
    for i in range(1, min(len(scenes), len(scene_clips))):
        if used >= TRANSITION_MAX:
            break
        if scenes[i].get("sfx") != "whoosh":
            continue
        try:
            prev_dur = scenes[i - 1]["end"] - scenes[i - 1]["start"]
            f_out = np.asarray(
                scene_clips[i - 1].get_frame(max(0.0, prev_dur - 1.0 / FPS))
            ).astype(np.uint8)[:, :, :3]
            f_in = np.asarray(scene_clips[i].get_frame(0.0)
                              ).astype(np.uint8)[:, :, :3]
            if f_out.shape != (H, W, 3) or f_in.shape != (H, W, 3):
                raise ValueError(f"unexpected frame shape {f_out.shape}")
            kind = "whip" if variant % 2 == 0 else "crosszoom"
            frames = (_whip_frames if kind == "whip"
                      else _crosszoom_frames)(f_out, f_in)
            dur = len(frames) / float(FPS)
            t0 = scenes[i]["start"] - dur / 2.0
            t0 = max(t0, scenes[i - 1]["start"] + 0.05)
            if t0 + dur > scenes[i]["end"] - 0.05:
                continue                          # boundary too tight
            clip = (ImageSequenceClip(frames, fps=FPS)
                    .with_start(t0).with_duration(dur))
            out.append(clip)
            used += 1
            variant += 1
            log.info("transition %d/%d: %s at %.2fs (beat change)",
                     used, TRANSITION_MAX, kind, scenes[i]["start"])
        except Exception as exc:  # noqa: BLE001
            log.warning("transition at scene %d failed (%s); hard cut", i, exc)
    return out


# ============================================================================
# r12: PATTERN INTERRUPT — the transitionalhooks.com technique, legal version
# (LICENSED clips we curated into .social/hooks/ — see ADAPTATION.md). ONE
# 0.7-1.2s cover-cropped splice at the Director's riser-shot start (the
# mid-video re-hook trap), impact SFX, per-page rotation. Folder empty or
# missing -> dormant. EDL/caption timing untouched (pure overlay).
# ============================================================================
def build_pattern_interrupt(scenes, page_id):
    """Returns (overlay_clip, open_source, t0) or None. Never raises."""
    try:
        files = sorted(glob.glob(os.path.join(HOOKS_DIR, "*.mp4")))
        if not files:
            return None
        ri = next((i for i, sc in enumerate(scenes)
                   if sc.get("sfx") == "riser"), None)
        if ri is None or ri == 0:
            log.info("pattern interrupt: no riser shot in this EDL; skipped")
            return None
        from moviepy import CompositeVideoClip, VideoFileClip

        f = files[int(hashlib.md5(f"hooks-{page_id}".encode()).hexdigest(),
                      16) % len(files)]
        src = VideoFileClip(f)
        src_dur = float(src.duration or 0)
        scene_dur = scenes[ri]["end"] - scenes[ri]["start"]
        dur = min(INTERRUPT_MAX_S, src_dur, max(0.0, scene_dur - 0.2))
        if dur < INTERRUPT_MIN_S:
            log.info("pattern interrupt: clip/shot too short (%.2fs); "
                     "skipped", dur)
            src.close()
            return None
        clip = src.without_audio().subclipped(0, dur)
        w, h = clip.size
        clip = clip.resized(max(W / float(w), H / float(h)) * 1.002)
        clip = clip.cropped(width=W, height=H,
                            x_center=clip.w / 2.0, y_center=clip.h / 2.0)
        t0 = scenes[ri]["start"]
        out = (CompositeVideoClip([clip.with_position("center")],
                                  size=(W, H))
               .with_duration(dur).with_start(t0))
        log.info("pattern interrupt: %s (%.2fs) spliced at %.2fs "
                 "(riser shot %d) + impact SFX", os.path.basename(f),
                 dur, t0, ri)
        return out, src, t0
    except Exception as exc:  # noqa: BLE001
        log.warning("pattern interrupt failed (%s); skipped", exc)
        return None


# ============================================================================
# Main composition
# ============================================================================
def compose_video(pool, broll_terms, mp3_path, hook, script, word_timings,
                  duration, font_path, out_path, bgm_path=None,
                  shotlist=None, page_id=0, receipts=None, title="",
                  person_map=None, visual_map=None, gravity="standard"):
    from moviepy import AudioFileClip, CompositeVideoClip, afx, vfx
    global LAST_EDL

    grave = str(gravity).strip().lower() == "grave"   # r16 GRAVITY register
    total = duration + TAIL_SECONDS

    # Beats always computed: the loved word-pop captions ride on them in BOTH
    # modes; in v3 fallback mode they also drive the scene plan.
    beats = split_beats(script, word_timings)
    fetcher = BrollFetcher(broll_terms)

    # --- v4 EDL mode when the Director sent a usable shotlist ---
    edl = build_edl(shotlist, script, word_timings, total) \
        if shotlist else None
    v4_mode = edl is not None
    LAST_EDL = edl if v4_mode else None   # r16: the judge pairs frames<->phrases from this
    if v4_mode:
        scenes = plan_scenes_edl(edl, pool, fetcher, receipts=receipts,
                                 title=title, person_map=person_map,
                                 visual_map=visual_map)
        # r14 VERIFYING EYE: quota-free CLIP check that each photo scene's
        # image matches the words spoken over it; clear mismatches swap to
        # a better pool image (in-place, never fatal, logs a summary).
        clip_verify_scenes(scenes, edl, pool)
    else:
        if shotlist:
            log.info("shotlist present but unusable; v3 scene planner")
        scenes = plan_scenes(beats, pool, fetcher, total)
    # r16 GRAVITY: a grave story is cut like a measured news piece — whoosh
    # hits (and the whip/zoom transitions they drive) are dropped; riser/
    # impact survive only if the Director placed them (grave direction already
    # restricts those to legal-reveal moments).
    if grave:
        n_strip = 0
        for sc in scenes:
            if sc.get("sfx") == "whoosh":
                sc["sfx"] = "none"
                n_strip += 1
        if n_strip:
            log.info("GRAVE story: stripped %d whoosh hit(s)", n_strip)
    n_broll = sum(1 for sc in scenes if sc["type"] == "broll")
    n_receipt = sum(1 for sc in scenes if sc["type"] == "receipt")
    log.info("scene plan (%s): %d scene(s) (%d receipt, %d b-roll), pool=%d",
             "v4 EDL" if v4_mode else "v3 beats", len(scenes), n_receipt,
             n_broll, len(pool))
    for i, sc in enumerate(scenes):
        log.info("  scene %d: %.2f-%.2fs type=%s motion=%s sfx=%s music=%s "
                 "visual=%s", i + 1, sc["start"], sc["end"],
                 sc["type"] + ("(FOOTAGE)" if sc.get("footage") else ""),
                 sc["motion"], sc.get("sfx", "-"), sc.get("music", "-"),
                 os.path.basename(sc["path"]))

    # --- hook window (v1 logic kept; computed early, the selfcheck needs it)
    hook_words = [w for w in hook.split() if w.strip()]
    n_hook = len(hook_words)
    if word_timings and len(word_timings) >= n_hook >= 1:
        hook_end = word_timings[n_hook - 1][2]
    else:
        hook_end = min(2.4, duration * 0.16)
    hook_end = max(1.2, min(hook_end, 3.2))

    # --- r12 PRE-ENCODE SELFCHECK (cheap, no AI; runs before any rendering).
    # Coverage model: the hook card covers [0, hook_end]; the word-pop chunk
    # states cover [first body word, end] gap-free (each state holds until
    # the next chunk starts) — so the only possible caption hole is between
    # hook_end and the first body word.
    body_starts = [wt[1] for beat in beats for wt in beat
                   if wt[1] >= hook_end - 1e-3]
    first_body = min(body_starts) if body_starts else None
    speech_span = caption_gap = 0.0
    if word_timings:
        w0, w_end = word_timings[0][1], word_timings[-1][2]
        speech_span = max(0.0, w_end - w0)
        cap_from = first_body if first_body is not None else w_end
        caption_gap = max(0.0, min(cap_from, w_end) - max(hook_end, w0))
    avail_assets = (len(pool)
                    # r17: receipt values may be {"path","photo"} dicts (og
                    # report photos) — count unique underlying paths.
                    + len({(v.get("path") if isinstance(v, dict) else v)
                           for v in (receipts or {}).values()})
                    + len({sc["path"] for sc in scenes
                           if sc["type"] == "broll"}))
    chk = selfcheck_scenes(scenes, avail_assets, speech_span, caption_gap,
                           window=POOL_NO_REPEAT_WINDOW,
                           min_shot_s=SELFCHECK_MIN_SHOT_S,
                           min_caption_cov=CAPTION_COVERAGE_MIN)
    log.info("SELFCHECK: repeats=%d short_scenes=%s caption_cov=%.0f%% "
             "(window=%d, assets=%d)", len(chk["repeats"]),
             chk["short_scenes"] or "none", chk["caption_coverage"] * 100,
             chk["eff_window"], avail_assets)
    if chk["repeats"]:
        raise SelfCheckFailed(
            "same image FROZEN across 3 consecutive scenes: "
            + "; ".join(f"scene {a + 1}->{b + 1} ({os.path.basename(p)})"
                        for a, b, p in chk["repeats"][:4]))
    if chk.get("soft_repeats"):
        # r25: acceptable in a small real-photo pool — the image reappears with
        # a fresh camera move (motion-lite), not a frozen frame. Warn only.
        log.warning("SELFCHECK: %d near-repeat(s) within the %d-scene window "
                    "(small pool; each reappears with different motion) — "
                    "non-fatal", len(chk["soft_repeats"]), chk["eff_window"])
    if chk["short_scenes"]:
        log.warning("SELFCHECK: %d scene(s) under %.1fs: %s (non-fatal)",
                    len(chk["short_scenes"]), SELFCHECK_MIN_SHOT_S,
                    chk["short_scenes"])
    if not chk["coverage_ok"]:
        log.warning("SELFCHECK: caption coverage %.0f%% < %.0f%% of speech "
                    "(non-fatal)", chk["caption_coverage"] * 100,
                    CAPTION_COVERAGE_MIN * 100)

    xfade = 0.0 if v4_mode else XFADE        # Law 7: hard cuts inside v4
    layers, open_sources, scene_clips = [], [], []
    for sc in scenes:
        if sc["type"] == "broll":
            clip, src = broll_scene_clip(
                sc["path"], sc["start"], sc["end"],
                motion=sc["motion"] if v4_mode else None,
                emph_rel=sc.get("emph_rel"), xfade=xfade,
                t_off=sc.get("src_off"))   # r13: footage starts 2s in
            open_sources.append(src)     # must stay open until after encode
            layers.append(clip)
            scene_clips.append(clip)
        elif sc["textish"]:
            clip = contain_scene_clip(sc["path"], sc["start"],
                                      sc["end"], xfade=xfade,
                                      card=(sc["type"] == "receipt"))
            layers.append(clip)
            scene_clips.append(clip)
        else:
            # v6: face-aware phone framing on every photo scene (cached
            # detection; None -> the pre-v6 center crop, never a crash)
            clip = scene_clip(sc["path"], sc["start"], sc["end"],
                              sc["motion"],
                              emph_rel=sc.get("emph_rel"),
                              xfade=xfade,
                              face=detect_face_box(sc["path"]))
            layers.append(clip)
            scene_clips.append(clip)

    # --- r12 produced energy: whoosh-boundary transitions + the pattern
    # interrupt overlay. Both sit BELOW vignette/scrim/hook/captions so the
    # caption sync and safe areas are untouched. v4 EDL mode only.
    interrupt_t = None
    if v4_mode and grave:
        log.info("GRAVE story: transitions + pattern interrupt disabled")
    if v4_mode and not grave:
        if TRANSITIONS_ON:
            layers.extend(build_transitions(scenes, scene_clips))
        pi = build_pattern_interrupt(scenes, page_id)
        if pi:
            i_clip, i_src, interrupt_t = pi
            layers.append(i_clip)
            open_sources.append(i_src)   # reader stays open until post-encode

    layers.append(make_vignette(total))      # v4 house look, both modes
    layers.append(make_scrim(total))

    hc = hook_clip(hook.upper(), 0.0, hook_end, font_path)
    if hc is not None:
        layers.append(hc)

    # --- word-pop chunk captions after the hook ---
    # v9: on card scenes the captions drop below the card (never on its text)
    card_windows = [(sc["start"], sc["end"]) for sc in scenes
                    if sc.get("type") == "receipt"]
    layers.extend(chunk_caption_clips(beats, hook_end, duration, font_path,
                                      card_windows=card_windows))

    video = CompositeVideoClip(layers, size=(W, H)).with_duration(total)
    if v4_mode and EDGE_FADE_S > 0:
        # Law 7: hard cuts everywhere INSIDE; only the video's own first and
        # last frames get a tiny fade so platform players don't pop.
        try:
            video = video.with_effects([vfx.FadeIn(EDGE_FADE_S),
                                        vfx.FadeOut(EDGE_FADE_S)])
        except Exception as exc:  # noqa: BLE001
            log.warning("edge fade unavailable (%s)", exc)

    # --- audio ---
    # v4: the full pydub sound mix (VO + stateful bed + SFX + loudness pass)
    # replaces the moviepy composite. Any mix failure -> v3 recipe below.
    mix_wav = None
    if v4_mode:
        mix_wav = build_sound_mix(
            mp3_path, scenes, total, page_id,
            os.path.join(WORKDIR, f"mix-{page_id}.wav"),
            extra_sfx=([("impact", interrupt_t)]
                       if interrupt_t is not None else None))
    if mix_wav:
        audio = AudioFileClip(mix_wav)
    else:
        # v3: voice + optional quiet BGM (Turbo's generate_video recipe)
        audio = AudioFileClip(mp3_path).with_effects(
            [afx.MultiplyVolume(VOICE_VOLUME)])
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


_JUDGE_PROMPT = """You are the NORMALITY JUDGE for 9:16 vertical short-form social videos. Your one job: guarantee the video looks NORMAL for its entire runtime — nothing weird may ever appear, no matter the topic.
You are given {n} evenly spaced frames from ONE rendered video, in playback order (frame 1 = earliest).
The video's hook/title is: "{hook}"

WEIRDNESS CHECKLIST — FAIL the video if ANY sampled frame shows ANY of:
a. CUT/UNREADABLE TEXT: on-screen text (hook, captions, or text inside an image/screenshot) cut off mid-word or mid-letter, cropped by the frame edge, or zoomed/mangled into unreadable fragments. WHITELISTED and fine: the big styled 1-3 word ALL-CAPS captions.
b. SLICED FACE: a human face cut by the frame edge (eyes or forehead sliced, face half outside the frame).
c. REPETITION: the SAME underlying image, screenshot or document appears in 3 or more of the sampled frames - THIS INCLUDES evidence screenshots and article captures; evidence repeated 3+ times is a FAIL, never acceptable (ignore the changing captions; judge the background visual). c2. UNREADABLE EVIDENCE: a screenshot/document rendered so small it floats as a narrow unreadable strip in a dark frame - if you cannot read its headline at this resolution, the viewer cannot either: FAIL.
d. DEAD FRAME: a near-black, blank, solid-color, corrupted or garbage frame.
e. CONTEXT MISMATCH: an image that obviously does not belong in an internet-drama recap — corporate stock cliches (handshakes, boardrooms, generic office people), random nature/travel filler, or imagery clearly unrelated to the story the hook implies.
f. CAPTION COLLISION: caption text sitting on top of the text of a screenshot/receipt/news card so that either becomes hard to read.
g. AD-CLUTTERED PROOF: a proof/screenshot/article frame cluttered with website ads, cookie banners, subscribe/newsletter boxes, or unrelated page furniture (nav menus, related-story grids, comment widgets) instead of the headline/photo/text it is supposed to prove.

SAID-VS-SEEN CHECK (r16 closed loop) — each frame below is paired with the exact narration WORDS being spoken at that moment. For every frame whose words are non-empty, judge: do the visuals BELONG to these exact words? A named person -> that person (or their post/evidence) must be on screen; a described event (the arrest, the courtroom, the party, the post) -> its image or screenshot; generic filler imagery shown during a specific fact = MISMATCH. Frames with empty words (pre-hook, tail padding) are exempt. Only flag CLEAR mismatches — a plausible related visual (the story's cover photo, the person's other photo, a receipt card of that fact) is fine.

FRAME WORDS (frame number: the words spoken during that frame):
{pairs}

Acceptable and NEVER a fail: minor blur, film grain, compression artifacts, darkened or blurred backgrounds, one intentional motion-blur transition frame, the styled captions themselves.
Judge ONLY the checklist above. Be strict: one weird frame fails the whole video; two or more clear said-vs-seen mismatches also fail it.

Respond with ONLY this JSON object, no markdown fences, no extra text:
{{"pass": true, "weird": [], "mismatches": [], "issues": [], "scores": {{"readability": 0, "framing": 0, "variety": 0, "edit_variety": 0}}}}
where pass is true/false (false whenever weird is non-empty OR mismatches has 2+ entries); weird is a list of {{"frame": <1-based frame number>, "issue": "<which checklist letter + short description>"}} covering EVERY checklist hit; mismatches is a list of {{"frame": <1-based frame number>, "words": "<the paired words>", "what_shown": "<short description of what the frame actually shows>"}} covering every CLEAR said-vs-seen mismatch (empty when none); issues is a list of short overall problem descriptions (empty when passing); each score is an integer 0-10."""


def _scene_midpoints(edl, total_s, cap=None):
    """r19: one timestamp per EDL scene (its midpoint) — a frame per CUT covers
    100% of the editing decisions (adjacent raw frames are near-duplicates;
    the picture only changes at cuts). Falls back to even spacing without EDL."""
    if edl:
        ts = [min(total_s - 0.05, max(0.0, (s["start"] + s["end"]) / 2.0))
              for s in edl if s.get("end", 0) > s.get("start", 0)]
        if cap and len(ts) > cap:
            step = len(ts) / float(cap)
            ts = [ts[int(i * step)] for i in range(cap)]
        return ts
    n = cap or JUDGE_FRAMES
    return [max(0.0, total_s * (2 * i + 1) / (2.0 * n)) for i in range(n)]


def _extract_frames_at(mp4_path, times, prefix="judge", width=540):
    """Extract one 540px jpeg per timestamp; returns [(path, timestamp_s)]."""
    ff = _ffmpeg_bin()
    frames = []
    for i, t in enumerate(times):
        p = os.path.join(WORKDIR, f"{prefix}-{i}.jpg")
        cmd = [ff, "-y", "-ss", f"{t:.2f}", "-i", mp4_path,
               "-frames:v", "1", "-q:v", "4", "-vf", f"scale={width}:-2", p]
        r = subprocess.run(cmd, capture_output=True, text=True)
        if r.returncode == 0 and os.path.exists(p) and os.path.getsize(p) > 1000:
            frames.append((p, t))
        else:
            log.warning("%s frame %d extraction failed", prefix, i)
    return frames


def _extract_judge_frames(mp4_path, total_s, n=JUDGE_FRAMES):
    """r19: the judge now inspects ONE FRAME PER SCENE (every cut judged;
    capped at 16 to keep the single vision call sane). Pre-EDL/v3 mode keeps
    the old even spacing. Returns [(path, timestamp_s)]."""
    times = _scene_midpoints(LAST_EDL, total_s, cap=max(n, 16))
    return _extract_frames_at(mp4_path, times, prefix="judge")


def _phrase_at(edl, t):
    """The EDL shot phrase spoken at time t; '' when no scene contains t
    (pre-hook lead, tail padding, or v3 mode with no EDL at all)."""
    if not edl:
        return ""
    for sh in edl:
        try:
            if float(sh.get("start", 0)) <= t < float(sh.get("end", 0)):
                return str(sh.get("phrase") or "").strip()
        except (TypeError, ValueError):
            continue
    return ""


def vision_judge(mp4_path, hook, title, total_s, edl=None):
    """One gemini-2.5-flash generateContent call (native REST, inline_data
    jpegs, response_mime_type=application/json). Returns the verdict dict or
    None when the judge is unavailable — the caller only blocks delivery on
    an explicit pass=false.
    r16 CLOSED LOOP: each sampled frame is paired with the exact narration
    words under it (from the EDL) so the judge can enforce said-vs-seen; the
    verdict gains "mismatches":[{frame,words,what_shown}] and >=2 clear
    mismatches fail the video even if the weirdness checklist passes."""
    if not GEMINI_API_KEY:
        log.info("GEMINI_API_KEY not set; skipping vision judge")
        return None
    import base64
    try:
        framepairs = _extract_judge_frames(mp4_path, total_s)
        if len(framepairs) < 2:
            log.warning("too few judge frames (%d); skipping judge",
                        len(framepairs))
            return None
        frames = [p for p, _t in framepairs]
        pairs_txt = "\n".join(
            f'frame {i + 1}: "{_phrase_at(edl, t)[:160]}"'
            for i, (_p, t) in enumerate(framepairs))
        prompt = _JUDGE_PROMPT.format(
            n=len(frames), hook=(hook or title or "").replace('"', "'")[:200],
            pairs=pairs_txt)
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
        # r16: normalize + enforce the mismatch fail rule deterministically —
        # >=2 clear mismatches fail regardless of what the model set pass to.
        mm = verdict.get("mismatches")
        mm = [m for m in mm if isinstance(m, dict)] if isinstance(mm, list) \
            else []
        verdict["mismatches"] = mm
        if len(mm) >= 2 and verdict.get("pass") is True:
            verdict["pass"] = False
        log.info("vision judge: pass=%s weird=%s mismatches=%s scores=%s "
                 "issues=%s", verdict.get("pass"), verdict.get("weird"),
                 mm, verdict.get("scores"), verdict.get("issues"))
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


# --- r16 CLOSED LOOP: replan bookkeeping ('page_id count' lines) -----------
def read_replans():
    """{page_id_str: count} from REPLAN_FILE; malformed lines are skipped and
    a missing/unreadable file is simply an empty book."""
    counts = {}
    try:
        if os.path.exists(REPLAN_FILE):
            with open(REPLAN_FILE, "r", encoding="utf-8") as f:
                for line in f:
                    parts = line.split()
                    if (len(parts) == 2 and parts[0].isdigit()
                            and parts[1].isdigit()):
                        counts[parts[0]] = int(parts[1])
    except Exception as exc:  # noqa: BLE001
        log.warning("replan state unreadable (%s); treating as empty", exc)
    return counts


def replan_count(page_id):
    return read_replans().get(str(page_id), 0)


def bump_replan(page_id):
    """Increment this page's replan count and rewrite the state file. Returns
    the new count."""
    counts = read_replans()
    counts[str(page_id)] = counts.get(str(page_id), 0) + 1
    os.makedirs(os.path.dirname(REPLAN_FILE) or ".", exist_ok=True)
    with open(REPLAN_FILE, "w", encoding="utf-8") as f:
        for k in sorted(counts, key=int):
            f.write(f"{k} {counts[k]}\n")
    return counts[str(page_id)]


def request_replan(page_id, reasons):
    """Ask the server to send this page back to the Director: POST
    {token, action:'replan', page_id, reasons} to video_receive.php (the
    server NULLs the pending row's shotlist; the cron re-directs it). A failed
    request is non-fatal — the count still advances so the cap stays finite
    and the next run simply re-renders the old plan once more."""
    body = {"token": INGEST_TOKEN, "action": "replan",
            "page_id": int(page_id),
            "reasons": [str(r)[:300] for r in (reasons or [])][:8]}
    last = None
    for attempt in (1, 2, 3):
        try:
            from curl_cffi import requests as cffi
            r = cffi.post(RECEIVE_URL, json=body, impersonate="firefox",
                          timeout=60, headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.json().get("ok"):
                log.info("replan requested for page %s", page_id)
                return True
            last = f"curl_cffi HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        try:
            r = requests.post(RECEIVE_URL, json=body, timeout=60,
                              headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.json().get("ok"):
                log.info("replan requested for page %s", page_id)
                return True
            last = f"requests HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        time.sleep(5 * attempt)
    log.warning("replan request failed for page %s (%s); count still "
                "advances so the cap stays finite", page_id, last)
    return False


def _get_json(url, params):
    """Fetch the job JSON. PRIMARY = JSON POST via curl_cffi — the exact channel that
    delivers the finished video every day, so it passes Hostinger's WAF where GETs
    intermittently 403 (proven: same IP, same server, POSTs never blocked). Falls
    back to GET with rotating browser fingerprints. Never gives up quietly."""
    qs = "?" + "&".join(f"{k}={requests.utils.quote(str(v))}" for k, v in params.items())
    # rotate real browser TLS fingerprints so a profile-specific block can't pin us
    profiles = ["chrome124", "firefox", "safari", "chrome120", "edge101"]
    hdrs = {"User-Agent": _BROWSER_UA, "Accept": "application/json, text/plain, */*",
            "Accept-Language": "en-US,en;q=0.9"}
    last = None
    for attempt in range(1, 7):
        prof = profiles[(attempt - 1) % len(profiles)]
        # engine 0 (PRIMARY): JSON POST — the daily-working delivery channel
        try:
            from curl_cffi import requests as cffi
            r = cffi.post(url, json=params, impersonate=prof, timeout=45, headers=hdrs)
            if r.status_code == 200:
                return r.json()
            last = f"POST/{prof} HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"POST/{prof}: {e}"
        # engine 1: curl_cffi GET, rotating fingerprint
        try:
            from curl_cffi import requests as cffi
            r = cffi.get(url + qs, impersonate=prof, timeout=45, headers=hdrs)
            if r.status_code == 200:
                return r.json()
            last = f"GET/{prof} HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"GET/{prof}: {e}"
        # engine 2: plain requests GET (last resort)
        try:
            r = requests.get(url, params=params, timeout=45, headers=hdrs)
            if r.status_code == 200:
                return r.json()
            last = f"requests HTTP {r.status_code}"
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        log.warning("fetch attempt %d/6 failed (%s); retrying", attempt, last)
        time.sleep(6 * attempt)
    raise RuntimeError(f"fetch_next failed after retries: {last}")


def fetch_next(done_ids):
    """PRIMARY: the static /media/ job feed — a plain JSON asset, indistinguishable
    from the media files the WAF lets this runner download every day (the /api/
    endpoint URL itself is what accumulates 403 blocks, GET or POST alike). The
    done-filter runs client-side. Fallback: the old PHP endpoint."""
    # WAF evidence: JSON/api-looking URLs get 403'd from runner IPs; PNG media
    # downloads have NEVER been blocked in any run. The feed therefore ships as
    # a VALID 1x1 PNG with the job JSON appended after a 'GZJSON:' marker (the
    # server content-checks .png files, so the image part must be real). The
    # plain .txt/.json twins are fallbacks.
    static_urls = [
        os.environ.get("VIDEO_FEED_URL", f"{BASE}/media/vfeed-{INGEST_TOKEN}.txt"),
        f"{BASE}/media/vfeed-{INGEST_TOKEN}.png",   # PNG-wrapped twin (marker-extracted)
        f"{BASE}/media/vfeed-{INGEST_TOKEN}.json",
    ]
    done_set = {str(d) for d in done_ids}
    try:
        data = None
        for su in static_urls:
            feed = _download_bytes(su)
            if not feed:
                continue
            marker = feed.find(b"GZJSON:")
            if marker >= 0:
                feed = feed[marker + 7:]
            try:
                data = json.loads(feed.decode("utf-8", "replace"))
                break                      # this candidate parsed — use it
            except Exception:              # stripped/re-encoded/partial -> next
                continue
        if data is None:
            raise RuntimeError("no static feed candidate parsed")
        for post in data.get("posts") or []:
            # r19: force=true = the SERVER requeued this story for a re-render —
            # the local done-list must not veto it (no more diary editing).
            if post.get("force") or str(post.get("page_id")) not in done_set:
                if post.get("force"):
                    log.info("FORCED re-render job for page %s", post.get("page_id"))
                log.info("job from static feed (generated %s)", data.get("generated"))
                return post
        log.info("static feed: all %d jobs already done", len(data.get("posts") or []))
        return None
    except Exception as e:  # noqa: BLE001
        log.warning("static feed failed (%s); falling back to api endpoint", e)
    data = _get_json(NEXT_URL, {"token": INGEST_TOKEN, "done": ",".join(done_ids)})
    return data.get("post")


def build_filmstrip(mp4_path, total_s, out_path):
    """r19 THE OWNER'S WINDOW: a 3x4 contact sheet of 12 frames with the words
    spoken at each moment printed underneath — delivered WITH the video so the
    operator's AI can literally LOOK at what every render shows vs says.
    Requires ffmpeg + PIL (runner-only); returns out_path or None."""
    try:
        from PIL import Image as PImage, ImageDraw, ImageFont
        # r20 DENSE VISION (owner: "make the frames enough to see the FULL
        # video"): one frame every 0.8s across the whole runtime — motion,
        # transitions and caption pops become visible as frame-to-frame
        # change. (Sound remains the owner's ear.) Cap 120 frames.
        step = 0.8
        times = []
        t = 0.4
        while t < total_s and len(times) < 120:
            times.append(t)
            t += step
        frames = _extract_frames_at(mp4_path, times, prefix="strip", width=360)
        if not frames:
            return None
        tw, th, cap_h = 270, 480, 46
        cols = 4
        rows = max(1, (len(frames) + cols - 1) // cols)
        sheet = PImage.new("RGB", (cols * tw, rows * (th + cap_h)), (12, 12, 12))
        draw = ImageDraw.Draw(sheet)
        try:
            font = ImageFont.truetype(resolve_font(), 15)
        except Exception:  # noqa: BLE001
            font = ImageFont.load_default()
        for i, item in enumerate(frames):
            fp, ts = (item if isinstance(item, tuple) else (item, 0.0))
            x, y = (i % cols) * tw, (i // cols) * (th + cap_h)
            try:
                im = PImage.open(fp).convert("RGB")
                im.thumbnail((tw, th))
                sheet.paste(im, (x + (tw - im.width) // 2, y))
            except Exception:  # noqa: BLE001
                continue
            words = ""
            try:
                words = _phrase_at(LAST_EDL, ts) if LAST_EDL else ""
            except Exception:  # noqa: BLE001
                pass
            label = f"{ts:.1f}s: {words[:52]}" if words else f"{ts:.1f}s"
            draw.rectangle([x, y + th, x + tw, y + th + cap_h], fill=(12, 12, 12))
            draw.text((x + 4, y + th + 4), label, font=font, fill=(240, 240, 240))
        sheet.save(out_path, "JPEG", quality=82)
        log.info("filmstrip built: %s", out_path)
        return out_path
    except Exception as e:  # noqa: BLE001
        log.info("filmstrip unavailable (%s)", e)
        return None


def post_video(page_id, slug, mp4_path, sheet_path=None):
    # Deliver as base64-in-JSON, the image-engine's proven daily-working pattern.
    # Hostinger's WAF 403-blocks multipart file uploads from datacenter IPs (run #4)
    # but passes JSON POSTs (scraper + image engine deliver this way every day).
    import base64
    with open(mp4_path, "rb") as fh:
        b64 = base64.b64encode(fh.read()).decode("ascii")
    body = {"token": INGEST_TOKEN, "page_id": int(page_id),
            "slug": slug or "", "video_b64": b64}
    if _RENDER_REPORT:                     # r25: planner decisions for diagnosis
        body["report"] = dict(_RENDER_REPORT)
    if sheet_path and os.path.isfile(sheet_path):
        with open(sheet_path, "rb") as fh:
            body["sheet_b64"] = base64.b64encode(fh.read()).decode("ascii")
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
    gravity = str(post.get("gravity") or "standard").strip().lower()
    grave = gravity == "grave"   # r16: tragedy register (calm bgm/sfx/tts)
    if grave:
        log.info("page %s is a GRAVE story: ambient bed, no whoosh/"
                 "transitions, halved hook-rate boost", page_id)
    if not script:
        raise ValueError(f"post {page_id} missing script")
    if not hook:
        hook = " ".join(script.split()[:8])

    os.makedirs(WORKDIR, exist_ok=True)
    # r28: this story's harvested platform clips (Twitch/TikTok/Kick/YouTube) —
    # the scene planner pulls these in as REAL MOVING footage matched to the
    # story, each fetched with its proper method (fetch_platform_clip).
    global _STORY_CLIPS
    _STORY_CLIPS = [c.get("url") for c in (post.get("clips") or [])
                    if isinstance(c, dict) and platform_of(c.get("url"))]
    if _STORY_CLIPS:
        log.info("story clips available: %d (%s)", len(_STORY_CLIPS),
                 ", ".join(sorted({platform_of(u) for u in _STORY_CLIPS})))
    pool, person_map = build_visual_pool(post, page_id)
    broll_terms = post.get("broll") if isinstance(post.get("broll"), list) \
        else []
    if not pool and not (broll_terms and (PEXELS_API_KEY or PIXABAY_API_KEY)):
        raise ValueError(f"post {page_id}: no usable visuals at all")

    shotlist = post.get("shotlist")
    if not isinstance(shotlist, dict):
        shotlist = None
        log.info("no shotlist in feed; v3 behaviour throughout")

    # v6: resolve the shotlist's visual_i references (real story images)
    visual_map = build_visual_map(post, page_id, pool, shotlist)

    # v4.5/r17: REAL evidence (post.receipts, idx order — receipt_i maps into
    # this dict). r17 BEIGE RETIRED: event entries arrive with url='' (the
    # server renders no event PNG anymore); only post/promo cards download
    # here. Events then resolve through resolve_event_receipts: clean article
    # screenshot > og:image report photo > subject photo (planner fallback).
    # No trim on card downloads: the cards' dark paper background must never
    # be shaved by the letterbox detector.
    receipt_paths = {}
    recs = post.get("receipts")
    if isinstance(recs, list) and recs:
        # v6: cap raised 16 -> 20 (up to 10 events + 6 posts + the branded
        # PROMO card appended LAST — the cap must never cut the promo off).
        for i, u in enumerate(recs[:20]):
            if not (isinstance(u, str) and u.startswith("http")):
                continue                   # r17: event rows carry no PNG
            p = fetch_visual(
                u, os.path.join(WORKDIR, f"receipt-{page_id}-{i}.png"),
                trim=False)
            if p:
                receipt_paths[i] = p
        log.info("receipts: %d card(s) downloaded of %d entries (event "
                 "entries carry no card by design)", len(receipt_paths),
                 len(recs))

        # r17 EVIDENCE CHAIN for events — "found, not made", never beige:
        # (a) clean article screenshot (screenshot_articles: ads hidden,
        #     headline REQUIRED, no raw top-of-page fallback);
        # (b) else the article's real og:image photo (photo scene);
        # (c) else the planner's subject-photo fallback.
        meta = post.get("receipt_meta")
        if isinstance(meta, list) and meta:
            # r28 relevance: keywords that a REAL headline about this story must
            # contain, so the screenshot picks the MAIN article headline — not a
            # "trending now / related" module's headline (an unrelated Eminem
            # story slipped in exactly this way on allhiphop).
            _topic_kw = []
            for _p in (post.get("people") or []):
                _nm = (_p.get("name") if isinstance(_p, dict) else str(_p)) or ""
                for _w in re.split(r"\s+", _nm.lower()):
                    _w = re.sub(r"[^a-z0-9]", "", _w)
                    if len(_w) >= 4:
                        _topic_kw.append(_w)
            for _w in re.split(r"\s+", str(post.get("title") or "").lower()):
                _w = re.sub(r"[^a-z0-9]", "", _w)
                if len(_w) >= 5 and _w not in ("their", "after", "about"):
                    _topic_kw.append(_w)
            _topic_kw = list(dict.fromkeys(_topic_kw))

            def _shooter(targets):
                if not REAL_SHOTS:
                    log.info("VIDEO_REAL_SHOTS=0: skipping article "
                             "screenshots (og/subject chain only)")
                    return {}
                return screenshot_articles(targets, page_id, topic_kw=_topic_kw)

            def _og_fetch(i, u):
                return fetch_visual(
                    u, os.path.join(WORKDIR, f"receipt-og-{page_id}-{i}.jpg"))

            receipt_paths, shot_n, og_n = resolve_event_receipts(
                meta, receipt_paths, _shooter, _og_fetch)
            log.info("event receipts: %d clean screenshot(s), %d og report "
                     "photo(s); the rest fall back to subject photos",
                     shot_n, og_n)

    mp3 = os.path.join(WORKDIR, f"voice-{page_id}.mp3")
    # r12: expressive segmented narration first; ANY doubt -> the proven
    # single-pass path (synthesize_expressive verifies its own offsets and
    # returns None rather than risk caption sync).
    result = synthesize_expressive(script, mp3, grave=grave)
    if result is not None:
        timings, duration = result
    else:
        if EXPRESSIVE_TTS:
            log.info("expressive TTS unavailable for page %s; single-pass "
                     "synthesis", page_id)
        timings, duration = synthesize(script, mp3)

    # r18 GRAFT A FORCED ALIGNMENT: measure the REAL audio and, only when the
    # measurement passes the sacred sync gates, replace the edge-tts timings for
    # BOTH captions (split_beats) and the EDL (build_edl -> map_tokens_to_spans).
    # ANY failure -> keep edge timings exactly as today.
    if FORCED_ALIGN:
        try:
            measured = forced_align(mp3, script)
        except Exception as exc:  # noqa: BLE001 — never fatal
            measured = None
            log.info("FORCED-ALIGN unavailable; edge timings (%s)",
                     str(exc)[:80])
        if measured and accept_forced_timings(measured, script, duration):
            timings = measured
            duration = max(duration, measured[-1][2])
            log.info("FORCED-ALIGN: %d words measured (replaced edge timings)",
                     len(measured))
        else:
            log.info("FORCED-ALIGN unavailable; edge timings")

    out = os.path.join(WORKDIR, f"video-{page_id}.mp4")
    compose_video(pool, broll_terms, mp3, hook, script, timings, duration,
                  font_path, out, bgm_path=pick_bgm(page_id, grave=grave),
                  shotlist=shotlist, page_id=page_id,
                  receipts=receipt_paths, title=post.get("title", ""),
                  person_map=person_map, visual_map=visual_map,
                  gravity=gravity)

    # v3: the vision judge sees the FINISHED (faststart-remuxed) artifact.
    # r16: it also gets the EDL so every sampled frame carries the words
    # spoken under it (said-vs-seen enforcement).
    verdict = vision_judge(out, hook, post.get("title", ""),
                           duration + TAIL_SECONDS, edl=LAST_EDL)
    if verdict is not None and verdict.get("pass") is not True:
        mism = verdict.get("mismatches") or []
        weird = verdict.get("weird") or []
        if len(mism) >= 2:
            # r16 CLOSED LOOP: a mismatch-class rejection means the PLAN is
            # wrong, not the render — re-rendering the same shotlist would
            # fail the same way. Ask the server to re-direct the story
            # (shotlist=NULL) and exit red; the next run renders the new plan.
            prev = replan_count(page_id)
            if prev >= REPLAN_CAP and not weird:
                log.error(
                    "REPLAN CAP REACHED for page %s (%d replans already): the "
                    "judge still sees %d said-vs-seen mismatch(es) but we "
                    "DELIVER ANYWAY rather than loop forever. mismatches=%s",
                    page_id, prev, len(mism), mism[:4])
            else:
                if prev < REPLAN_CAP:
                    reasons = [
                        f"frame {m.get('frame')}: said "
                        f"'{str(m.get('words') or '')[:120]}' but showed "
                        f"{str(m.get('what_shown') or '')[:120]}"
                        for m in mism[:6]]
                    request_replan(page_id, reasons)
                    now = bump_replan(page_id)
                    raise JudgeRejected(
                        f"said-vs-seen judge rejected page {page_id} "
                        f"(replan {now}/{REPLAN_CAP} requested): "
                        f"mismatches={mism[:4]} weird={weird} "
                        f"issues={verdict.get('issues')}")
                raise JudgeRejected(
                    f"vision judge rejected page {page_id} (replan cap "
                    f"reached but weirdness remains): weird={weird} "
                    f"mismatches={mism[:4]} issues={verdict.get('issues')}")
        else:
            raise JudgeRejected(
                f"vision judge rejected page {page_id}: "
                f"weird={weird} issues={verdict.get('issues')} "
                f"scores={verdict.get('scores')}")

    # r19: build + deliver the filmstrip (12 frames + spoken words) so the
    # operator's AI can SEE what the render shows vs says. Never fatal.
    sheet = build_filmstrip(out, duration + TAIL_SECONDS,
                            os.path.join(WORKDIR, f"sheet-{page_id}.jpg"))
    post_video(page_id, slug, out, sheet_path=sheet)
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
