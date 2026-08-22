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

import base64          # r30: screenshot_is_clean/footage_is_relevant used this
import glob             # WITHOUT importing it -> NameError -> both vision gates
import hashlib          # failed open on EVERY call. See the r30 note below.
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
# r132 VOICE ROTATION (idea from MoneyPrinterTurbo's multi-voice service).
# One voice on every video is a monotony signal both to returning viewers and
# to the platforms' variety heuristics. The pool rotates deterministically by
# page_id — same video re-rendered keeps its voice — and VIDEO_VOICE still
# overrides everything with a single fixed voice when set.
# Grave stories always take the first (calmest-read) pool voice.
VOICE_POOL = [v.strip() for v in os.environ.get(
    "VIDEO_VOICE_POOL",
    "en-US-AndrewMultilingualNeural,en-US-BrianMultilingualNeural,"
    "en-US-AvaMultilingualNeural").split(",") if v.strip()]
VOICE = os.environ.get("VIDEO_VOICE", "")     # explicit override only

# The broll cache dir the workflow persists across runs (r132); empty = the
# old per-run behavior, so a missing actions/cache step changes nothing.
BROLL_CACHE_DIR = os.environ.get("VIDEO_BROLL_CACHE_DIR", "").strip()
if BROLL_CACHE_DIR:
    try:
        os.makedirs(BROLL_CACHE_DIR, exist_ok=True)
        # Prune to the newest ~40 files (~1.2GB worst case) so the cache the
        # workflow uploads stays far under GitHub's 10GB repo quota.
        _bc = sorted((os.path.join(BROLL_CACHE_DIR, x)
                      for x in os.listdir(BROLL_CACHE_DIR)),
                     key=os.path.getmtime, reverse=True)
        for _old in _bc[40:]:
            try:
                os.remove(_old)
            except OSError:
                pass
    except OSError:
        BROLL_CACHE_DIR = ""


_ACTIVE_VOICE = ["en-US-AndrewMultilingualNeural"]   # set per video in make_one


def _looks_like_mp4(path):
    """True when the file starts like a real mp4 (ftyp box in the first 64
    bytes) and is big enough to be footage rather than an error page."""
    try:
        if os.path.getsize(path) < 65536:
            return False
        with open(path, "rb") as fh:
            return b"ftyp" in fh.read(64)
    except OSError:
        return False


def pick_voice(page_id, grave=False):
    """The voice for this video: explicit override > grave register > pool
    rotation by page_id (stable across re-renders of the same story)."""
    if VOICE:
        return VOICE
    if not VOICE_POOL:
        return "en-US-AndrewMultilingualNeural"
    if grave:
        return VOICE_POOL[0]
    return VOICE_POOL[int(page_id) % len(VOICE_POOL)]
VOICE_RATE = float(os.environ.get("VIDEO_VOICE_RATE", "1.05"))
VOICE_VOLUME = float(os.environ.get("VIDEO_VOICE_VOLUME", "1.0"))

# --- TREATMENT V2 (2026-08-05): 2.5D depth parallax on real photos -----------
# Depth-Anything-V2-Small ONNX (Apache-2.0 — the ONLY DA2 size we may ship;
# Base/Large are CC-BY-NC). The yml downloads the model to build/; missing
# model or onnxruntime = classic motion, never fatal. Measured on the runner
# (depth-bench run #1): 0.85s depth/photo, 8.4ms/frame warp — the warp is
# CHEAPER than moviepy's per-frame PIL resize it replaces.
DEPTH_PARALLAX = os.environ.get("VIDEO_DEPTH_PARALLAX", "1") != "0"
DEPTH_MODEL_PATH = os.environ.get("VIDEO_DEPTH_MODEL", "build/depth_vits.onnx")
DEPTH_AMP_X = 16.0            # px of max near-plane horizontal drift
DEPTH_AMP_Y = 6.0             # vertical drift (subtler — vertical par. reads odd)
DEPTH_DRIFT_PERIOD = 7.0      # s per drift orbit (slow, documentary)
DEPTH_GRAIN = 5.0             # film-grain sigma (uint8 space), depth scenes only
VIDEO_BATCH = int(os.environ.get("VIDEO_BATCH", "1"))

W, H = 1080, 1920
# r82d: horizontal safe margin for text overlays. Nothing is drawn closer
# than this to the frame edge — both for phone-safe legibility and because
# edge-touching overlays are what crashed moviepy compose_mask.
SAFE_X = 24
FPS = int(os.environ.get("VIDEO_FPS", "30"))
HOOK_FONT = 96
HOOK_TEXT_MAX_S = float(os.environ.get("VIDEO_HOOK_TEXT_MAX_S", "2.2"))   # r126: opener text clears fast
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
# PLATFORM UI SAFE AREAS (r94, measured against the published 2026 specs).
# Every platform paints its own furniture over our frame, and the zones differ:
#   top     TikTok 200 · Shorts 200 · Reels 250   -> take the worst, 250
#   bottom  TikTok 350 · Shorts 400 · Reels 400   -> take the worst, 400
#   right   the like/comment/share column, 130-150 on all three
# The old values (220/320) were both too small AND dead code — nothing read
# SAFE_BOTTOM at all. They are corrected and now actually enforced by
# safe_xy(), because a number no code obeys protects nothing: today's layout
# happens to sit inside these zones, and the next overlay someone adds would
# have had nothing to stop it.
SAFE_TOP = 250
SAFE_BOTTOM = 400
SAFE_RIGHT = 150


def safe_xy(x, y, w=0, h=0):
    """Clamp a CORNER-ANCHORED overlay into the area no platform covers.

    For badges and chips only — NOT for the captions or the hook. Tested when
    written: an 880px centred caption fed through this gets shoved 50px left,
    because the right button column leaves no room for something that wide.
    Wide centred text is SUPPOSED to run under that column; the buttons are
    semi-transparent and centred text stays readable. Shifting it would break
    the centring to solve a problem that does not exist.

    Whole pixels only — fractional offsets are their own encode trap (r82c).
    """
    x = min(max(int(round(x)), SAFE_X), max(SAFE_X, W - SAFE_RIGHT - int(w)))
    y = min(max(int(round(y)), SAFE_TOP), max(SAFE_TOP, H - SAFE_BOTTOM - int(h)))
    return (x, y)
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
# r43 PACING LAW (owner, all day: "frozen slideshow", "trash"): the planner put
# 23 scenes across 66.5s = 2.89s on EVERY still, each carrying one slow zoom.
# That reads as a slideshow no matter how good the images are. Short-form rhythm
# is a cut every ~1.2-1.5s, and the people fix finally supplies enough distinct
# visuals (~24/story) to pay for those cuts. Long STILLS are therefore split into
# consecutive beats that each show a DIFFERENT image. Set target to 0 to disable.
# Tuned against the real scene durations of the page-415 render (1.6s-4.2s):
# 23 scenes @ 2.89s avg -> 45 beats @ 1.48s avg, longest hold 2.3s.
# ============================================================================
# r65 STYLE SYSTEM — the owner's A/B test: same story, same ~60s+ length, but a
# different FEEL, so we can learn which treatment actually makes people share.
# A style is a preset over knobs that already exist, so no new render code and
# no new failure modes. Three dimensions vary together because they are what a
# viewer actually perceives: CUT RHYTHM, TRANSITION HARDNESS, MOTION INTENSITY,
# and MUSIC BED. The chosen style is stamped into the render report so the
# metrics collector can compare performance per style later.
#   rapid     - machine-gun cuts, hard cuts, aggressive push-in, energetic bed
#   slowburn  - long holds, soft dissolves, gentle drift, dark ambient bed
#   punch     - middle rhythm, biggest zoom hits, third bed
# Override for a deliberate test with VIDEO_STYLE=rapid|slowburn|punch.
# ============================================================================
# r67 adds the CONTENT dimension: "lead" decides WHAT dominates the screen, so
# the three styles differ in substance and not only in rhythm.
#   receipts - proof-heavy: screenshots/tweets carry more scenes (evidence cap 3)
#   footage  - moment-heavy: real clips run longer and are chased harder
#   faces    - people-heavy: portraits win subject shots, footage stays sparse
VIDEO_STYLES = {
    "rapid":    {"split": 1.0, "xfade": 0.00, "zoom": 0.20, "punch": 1.22,
                 "bgm": 2, "lead": "receipts"},
    "slowburn": {"split": 2.2, "xfade": 0.25, "zoom": 0.10, "punch": 1.10,
                 "bgm": 1, "lead": "footage"},
    "punch":    {"split": 1.5, "xfade": 0.08, "zoom": 0.16, "punch": 1.17,
                 "bgm": 3, "lead": "faces"},
}
STYLE_LEAD = ""                    # receipts | footage | faces
EVIDENCE_MAX_SCENES = 2            # scenes one proof image may back (r21 cap)
STYLE_NAME = ""
STYLE_BGM = 0                      # 1-based bgm index chosen by the style


def pick_style(page_id):
    """Deterministic per story so a re-render of the same page keeps its style
    (a fair test needs a stable assignment), rotating across the library."""
    forced = os.environ.get("VIDEO_STYLE", "").strip().lower()
    if forced in VIDEO_STYLES:
        return forced
    names = sorted(VIDEO_STYLES)
    return names[int(page_id) % len(names)]


def apply_style(name):
    """Bind the style's knobs. Called once per video before planning."""
    global SCENE_SPLIT_TARGET_S, XFADE, SCENE_ZOOM, PUNCH_BUILD_SCALE
    global STYLE_NAME, STYLE_BGM
    st = VIDEO_STYLES.get(name)
    if not st:
        return
    STYLE_NAME = name
    SCENE_SPLIT_TARGET_S = float(st["split"])
    XFADE = float(st["xfade"])
    SCENE_ZOOM = float(st["zoom"])
    PUNCH_BUILD_SCALE = float(st["punch"])
    STYLE_BGM = int(st["bgm"])
    # r67 CONTENT LEAD — retune the mix knobs that decide what fills the screen.
    global STYLE_LEAD, EVIDENCE_MAX_SCENES, FOOTAGE_CK_MAX_CONSEC
    STYLE_LEAD = str(st.get("lead", ""))
    if STYLE_LEAD == "receipts":
        EVIDENCE_MAX_SCENES = 3        # let a strong proof carry one scene more
        FOOTAGE_CK_MAX_CONSEC = 2      # keep clips short so proof stays the star
    elif STYLE_LEAD == "footage":
        EVIDENCE_MAX_SCENES = 2
        FOOTAGE_CK_MAX_CONSEC = 6      # let the real moment breathe
    elif STYLE_LEAD == "faces":
        EVIDENCE_MAX_SCENES = 2
        FOOTAGE_CK_MAX_CONSEC = 2      # portraits carry it, clips are punctuation
    log.info("STYLE %s: lead=%s cuts~%.1fs xfade=%.2f zoom=%.2f punch=%.2f "
             "bgm_%d evidence_cap=%d footage_consec=%d",
             name, STYLE_LEAD, SCENE_SPLIT_TARGET_S, XFADE, SCENE_ZOOM,
             PUNCH_BUILD_SCALE, STYLE_BGM, EVIDENCE_MAX_SCENES,
             FOOTAGE_CK_MAX_CONSEC)


SCENE_SPLIT_TARGET_S = float(os.environ.get("VIDEO_SPLIT_TARGET_S", "1.2"))
SCENE_SPLIT_MIN_S = float(os.environ.get("VIDEO_SPLIT_MIN_S", "1.8"))
SCENE_SPLIT_MAX_PARTS = int(os.environ.get("VIDEO_SPLIT_MAX_PARTS", "4"))

# r47 RESOLUTION FLOOR: research line is 720x1280 — below that a still reads soft
# on a phone, and no upscaler recovers detail (Lanczos stays sharpest-per-cost;
# Real-ESRGAN over-smooths and invents textures). 720 is the short side we demand
# of a still that will fill a 1080-wide frame and then be zoomed into.
# r51: 720 was TOO STRICT — it rejected 1024x682, an ordinary press photo, and
# starved the pool to 6 images (31% repetition, worse than before the floor).
# Almost no news photo is 720 on its SHORT side. 540 keeps real press photos
# while still refusing thumbnails/avatars (428x428, 480x270). The proper fix is
# to stop upscaling mid-size photos at all (contain on a blurred fill) — until
# then, an ordinary photo beats no photo.
MIN_STILL_SHORT_SIDE = int(os.environ.get("VIDEO_MIN_SHORT_SIDE", "400"))
# r97: a floor for images that exist to be READ. The resolution floor above is
# skipped for text-heavy visuals, which is backwards — an article screenshot
# needs MORE pixels than a photo, not fewer, because a photo survives being
# soft and a headline does not. Page 358 was rejected by the judge for exactly
# this: "the article screenshot is rendered extremely small, leaving a massive
# blank area below it, making the text unreadable". At a 1080-wide frame,
# anything narrower than this arrives as an unreadable floating strip.
MIN_TEXT_WIDTH = int(os.environ.get("VIDEO_MIN_TEXT_WIDTH", "800"))
# r55: hard deadline on ONE edge-tts call.
# r57: 75 -> 45. r55's per-call deadline was correct but never bounded the
# STAGE: 3 segments x 2 attempts x 75s = 477s, which blows the 420s tts
# watchdog BEFORE the single-pass fallback ever gets a turn — so a stalled
# voice stream still threw away the whole render. A healthy call takes ~1.5s
# (measured: the hook segment, 13 cues, 4.46s of audio). 45s is already a
# 30x margin; anything past it is a dead stream, not a slow one.
TTS_CALL_TIMEOUT_S = int(os.environ.get("VIDEO_TTS_CALL_TIMEOUT", "45"))
# r57: WALL-CLOCK CEILING for the whole voice stage, retries included. This is
# the number that actually protects the render: whatever the ladder does, the
# stage cannot outlive its budget, so it always exits in time to be handled
# instead of being force-killed by the watchdog at 420s.
TTS_STAGE_BUDGET_S = int(os.environ.get("VIDEO_TTS_BUDGET", "300"))
_TTS_DEADLINE = None           # set by tts_begin() when the stage starts


def tts_begin():
    """Start the voice stage's wall clock. Called at _set_stage('tts')."""
    global _TTS_DEADLINE
    _TTS_DEADLINE = time.time() + TTS_STAGE_BUDGET_S


def tts_left():
    """Seconds of voice-stage budget remaining (inf when no stage clock)."""
    if _TTS_DEADLINE is None:
        return float("inf")
    return _TTS_DEADLINE - time.time()
# r52: a photo needing more than this much upscale to COVER the frame is
# rendered contained on a blurred fill instead of being stretched.
COVER_MAX_UPSCALE = float(os.environ.get("VIDEO_COVER_MAX_UPSCALE", "1.35"))

# r70: hunt for more footage until a story has at least this many clips.
CLIP_HUNT_MIN = int(os.environ.get("VIDEO_CLIP_HUNT_MIN", "3"))
# r71: minimum perceptual distance (of 64 bits) between two stills taken from
# the SAME clip. 6 was the pool-wide near-duplicate bar and far too loose here.
CLIP_FRAME_MIN_DIFF = int(os.environ.get("VIDEO_CLIP_FRAME_MIN_DIFF", "14"))

POOL_NO_REPEAT_WINDOW = 3      # r11: an image never reappears within 3 scenes
# r42: the window and the max-share below were tuned when a story had 4-6 images
# and HAD to recycle. The people-resolver fix (athletes were being dropped from
# their own stories) now yields ~24 distinct visuals for ~23 shots, so recycling
# is no longer necessary — tune_variety_for_pool() widens the window and tightens
# the share whenever the pool is rich, and leaves thin-pool behaviour untouched.
POOL_NO_REPEAT_WINDOW_BASE = 3

# --- v2: background music ---
# Drop ONLY CC0 / royalty-free .mp3 tracks in this folder (platform copyright
# strikes kill faceless channels). Missing/empty folder -> video stays silent.
BGM_DIR = os.environ.get("VIDEO_BGM_DIR", ".social/bgm")
BGM_VOLUME = float(os.environ.get("VIDEO_BGM_VOLUME", "0.10"))

# r113: the channel's ONE bed (see pick_bgm). 2 = "Fright Night", film-score
# tension. Grave stories still take the ambient bed; nothing else varies.
SIGNATURE_BGM = int(os.environ.get("VIDEO_BGM_SIGNATURE", "2"))

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
GEMINI_MODEL = os.environ.get("GEMINI_MODEL", "gemini-flash-latest")  # r28: 2.5-flash free quota is exhausted (429); -latest has budget
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
# r33 VARIETY LAW: no single still may carry more than this share of the still
# scenes, and a story must reach VISUAL_POOL_MIN distinct visuals before
# planning (Openverse tops up a thin pool). Both exist because one photo ran
# under ~60% of the El Risitas video while the judge's repetition rule slept.
VISUAL_MAX_SHARE = float(os.environ.get("VIDEO_MAX_VISUAL_SHARE", "0.34"))
VISUAL_MAX_SHARE_BASE = VISUAL_MAX_SHARE
# r56 (owner: "look for the ones already built and wire them safely"): the
# Openverse top-up below IS the "add an image search" advice — key-free and
# already implemented — but it only fired when the pool was under 4 images.
# Starved renders had 6, so it never ran once. A 25-45 beat video needs ~20
# distinct visuals, so that is the real floor. Openverse results still pass
# every existing gate (relevance, resolution, dedup, face), so raising this
# adds candidates WITHOUT lowering any quality bar.
# r69 CORRECTED DOWN from 20. Raising it to 20 (r56) made the Openverse
# top-up fire on almost every story, and on thin/abstract stories it has
# nothing real to find — an AI-actress story got robot components, a
# streamer story got gingerbread houses. BOTH videos were rejected by the
# judge for context mismatch. The judge is right: fewer honest images beat
# padding. 10 still rescues genuinely starved stories (the 6-image case)
# without inviting filler into every other one.
VISUAL_POOL_MIN = int(os.environ.get("VIDEO_POOL_MIN", "10"))


def tune_variety_for_pool(n_pool, n_scenes):
    """r42: scale the no-repeat window + max-share to the ACTUAL pool size.

    With 4 images and 20 scenes a photo MUST come back, so the base rules allow
    it (window 3, share 0.34 = one still may carry a third of the video). With
    24 images and 23 scenes nothing needs to repeat at all — yet the base rules
    still permitted the cover photo ~7 appearances, which is exactly the
    "they keep repeating the same imgs" the owner sees. So: the richer the pool,
    the wider the window and the tighter the per-image share. Thin pools keep
    the old behaviour untouched (never stricter than the base)."""
    global POOL_NO_REPEAT_WINDOW, VISUAL_MAX_SHARE
    n_pool = max(0, int(n_pool or 0))
    n_scenes = max(1, int(n_scenes or 1))
    if n_pool < 6:                       # thin pool: recycling is unavoidable
        POOL_NO_REPEAT_WINDOW = POOL_NO_REPEAT_WINDOW_BASE
        VISUAL_MAX_SHARE = VISUAL_MAX_SHARE_BASE
        return
    # Never revisit an image until most of the pool has been spent (cap 8 so a
    # huge pool can't starve the picker when some entries fail to download).
    POOL_NO_REPEAT_WINDOW = max(POOL_NO_REPEAT_WINDOW_BASE, min(8, n_pool - 1))
    # Ideal uses per image if we spread perfectly, +1 slack for pinned shots.
    ideal = math.ceil(n_scenes / float(n_pool))
    VISUAL_MAX_SHARE = min(VISUAL_MAX_SHARE_BASE,
                           max(0.10, (ideal + 1) / float(n_scenes)))
    log.info("VARIETY tuned for pool=%d scenes=%d -> window=%d max_share=%.2f",
             n_pool, n_scenes, POOL_NO_REPEAT_WINDOW, VISUAL_MAX_SHARE)
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
# r110: the bed drops another 4dB. It was never the loud part, but with the
# effects tamed it is the remaining thing you notice, and on a talking-head
# clip a bed you can pick out is a bed that is too loud.
BED_DB_VS_VO = float(os.environ.get("VIDEO_BGM_DB", "-22"))
DUCK_EXTRA_DB = -4.0           # 'duck' music state: extra reduction
# r113 SWELL (see _apply_swells): how far the bed climbs under the build, and
# how long it takes to get out of the way after the reveal lands. +5dB is a
# clear lift from a bed sitting 22dB under the voice without ever competing
# with it; the decay is long enough to feel like a release, not a cut.
SWELL_DB = float(os.environ.get("VIDEO_BGM_SWELL_DB", "5"))
SWELL_DECAY_MS = int(os.environ.get("VIDEO_BGM_SWELL_DECAY_MS", "1800"))
SWELL_MIN_MS = 600             # a build shorter than this does not read as one
SWELL_MAX = 2                  # at most two swells; more and nothing is big
# r110 QUIETER EFFECTS. These sat 6-8dB under the voice — roughly half its
# loudness — and fired on every cut, which is what made the audio tiring
# rather than punchy. An effect should be felt, not announced; -14/-16 is
# still clearly audible under speech but stops competing with it. Tunable by
# env so this can be dialled without a code change.
WHOOSH_DB_VS_VO = float(os.environ.get("VIDEO_SFX_WHOOSH_DB", "-15"))
IMPACT_DB_VS_VO = float(os.environ.get("VIDEO_SFX_IMPACT_DB", "-12"))
POP_DB_VS_VO = float(os.environ.get("VIDEO_SFX_POP_DB", "-17"))
RISER_DB_VS_VO = float(os.environ.get("VIDEO_SFX_RISER_DB", "-15"))
RISER_MAX_S = 3.0              # risers keep their LAST <=3s (they peak at the end)
SILENCE_LEAD_S = 0.30          # music cut this much BEFORE a 'silence' shot
# r137 VO-FOLLOWING DUCK (Q3 contract, D-001: GLM's half). The bed was a
# STATIC -22dB whether the voice was speaking or not — so during VO pauses it
# stayed buried (no lift = dead air feels empty) and during dense hook speech
# it had the same level it has under a calm line. Real mixers ride the bed
# against the voice. Envelope duck, pydub+numpy only: per-window VO RMS
# decides speech/pause, an attack/release ramp smooths the step (no clicks,
# no sidechain compressor "character" we cannot reason about), and the whole
# thing is one deterministic pure function. Swells pass through the duck, so
# a swell under speech still dips and only fully opens in the pause — which
# is exactly when a swell should be heard. VIDEO_BGM_DUCK=0 kills it.
DUCK_ENABLE = os.environ.get("VIDEO_BGM_DUCK", "1") != "0"
DUCK_DB = float(os.environ.get("VIDEO_BGM_DUCK_DB", "6"))
DUCK_ATTACK_MS = int(os.environ.get("VIDEO_BGM_DUCK_ATTACK_MS", "120"))
DUCK_RELEASE_MS = int(os.environ.get("VIDEO_BGM_DUCK_RELEASE_MS", "450"))
DUCK_WINDOW_MS = int(os.environ.get("VIDEO_BGM_DUCK_WINDOW_MS", "25"))
DUCK_SPEECH_FLOOR_DBFS = -45.0  # below this a window is silence, not speech
DUCK_SPEECH_VS_PEAK_DB = 20.0   # and within 20dB of the loudest window
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

# r135 HOLD CAP (owner watch session): a still on screen 8-12s reads as a
# frozen video — the "Yes!!!!!!!!" tweet on page 259 sat 12 seconds while
# three sentences played over it. Any still-backed EDL shot longer than this
# is split at word boundaries in build_edl; the first segment keeps the
# pinned evidence (receipt slam intact), continuations drop the pin so the
# planner's LRU serves fresh visuals. Clip-backed shots keep their length —
# motion is not the problem.
MAX_STILL_HOLD_S = float(os.environ.get("VIDEO_MAX_STILL_HOLD_S", "4.2"))

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
# r83 (owner: "make the video FULL of clips"): these caps are now
# env-tunable so a clips-first posture is a setting, not a code edit. The
# still-image accents remain — a wall of clips with no beat to breathe on
# reads as a rip, and the judge scores edit_variety.
FOOTAGE_CK_MAX_SCENES = int(os.environ.get("VIDEO_FOOTAGE_MAX_SCENES", "12"))
FOOTAGE_CK_MAX_TOTAL_S = float(os.environ.get("VIDEO_FOOTAGE_MAX_S", "40"))
FOOTAGE_CK_MAX_TOTAL_FRAC = float(os.environ.get("VIDEO_FOOTAGE_FRAC", "0.70"))
FOOTAGE_CK_MAX_FETCHES = int(os.environ.get("VIDEO_FOOTAGE_FETCHES", "8"))
FOOTAGE_CK_MAX_CONSEC = int(os.environ.get("VIDEO_FOOTAGE_CONSEC", "4"))
                                   # r25: footage may run up to N scenes before
                                   # a still accent (real story footage is NOT
                                   # the generic-stock 2-in-a-row cap; that cap
                                   # still bounds stock b-roll separately)
# r98: yt-dlp's own wiki asks for 5-10s between downloads on an authenticated
# session; we were at 2-4. Our volume is nowhere near the limit anyway (~8
# fetches per render against a documented ~2000/hour for an account), so the
# extra seconds cost one render nothing and remove the one behaviour that
# actually looks automated.
FOOTAGE_FETCH_SLEEP_S = (5.0, 9.0)  # polite sleep between spawns w/ cookies

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
# r91: the SCREENSHOT browser is Chromium, so it must claim Chrome. Keeping
# _BROWSER_UA (Firefox) for it was self-defeating — curl_cffi impersonates
# Firefox's TLS so that string is right THERE, and wrong here.
SHOT_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
           "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")
SHOT_CH_UA = '"Chromium";v="131", "Not_A Brand";v="24", "Google Chrome";v="131"'


# ============================================================================
# r29 STAGE HEARTBEAT — a daemon thread POSTs the current render stage to the
# server every few seconds. When a render hangs, the LAST heartbeat line in
# media/heartbeat.log names the exact stalled stage (Actions logs need repo
# admin to read, so we can't inspect them). Fully non-fatal; never blocks.
# ============================================================================
_STAGE = "boot"
_STAGE_PID = 0
_STAGE_SINCE = 0.0
_HB_STARTED = False
# Per-stage stall ceilings (seconds). If a stage sits longer than its cap the
# watchdog force-exits so a hang dies fast instead of burning the whole step
# timeout — and the last heartbeat names the stalled stage. compose (the
# moviepy encode) gets the most room; it can't be timed out from inside.
_STAGE_STALL_CAP = {
    # compose (the moviepy still-motion encode) is the heavy stage; give it room
    # to actually FINISH a slow-but-working render rather than killing it early.
    # r44: judge raised 180 -> 480. With r43 pacing a video now has ~43 beats, so
    # the judge samples far more frames and legitimately took 3.5min — the old cap
    # force-killed a render that was working (run 170).
# r53: tts 180 -> 420. edge-tts is a NETWORK service and stalls on its own
# schedule; a 180s cap was force-killing whole renders over a transient
# hang (two runs in a row produced no video at all). It has its own retry
# path, so give it room rather than throwing the render away.
    "compose": 900, "tts": 420, "screenshots": 150, "judge": 480,
    "filmstrip": 120, "post": 330, "receipts": 150, "visuals": 180,
}
_STAGE_STALL_DEFAULT = 240
# r35: one POST a minute, not four. Today's volume (heartbeats every 15s across
# a dozen runs + 40MB uploads + diag images) is what got the runner's IP
# blackholed by Hostinger's WAF mid-session — the render still ran, but nothing
# it produced could reach the server.
HEARTBEAT_EVERY_S = float(os.environ.get("VIDEO_HEARTBEAT_S", "60"))


def _set_stage(name, pid=None):
    global _STAGE, _STAGE_PID, _STAGE_SINCE
    _STAGE = str(name)
    _STAGE_SINCE = time.time()
    if pid is not None:
        _STAGE_PID = int(pid)
    log.info("STAGE: %s", name)


def _hb_post(body):
    """POST a heartbeat through the SAME browser-TLS path delivery uses:
    Hostinger's WAF TLS-fingerprint-blocks plain requests/curl, so a plain
    requests.post is silently dropped (that's why r29's first heartbeats never
    landed). curl_cffi impersonate=firefox is the working path; plain requests
    is only a fallback for envs without curl_cffi."""
    try:
        from curl_cffi import requests as cffi
        cffi.post(RECEIVE_URL, json=body, impersonate="firefox",
                  timeout=8, headers={"User-Agent": _BROWSER_UA})
        return
    except Exception:
        pass
    try:
        requests.post(RECEIVE_URL, json=body, timeout=8,
                      headers={"User-Agent": _BROWSER_UA})
    except Exception:
        pass


def _post_diag(page_id, name, img_path):
    """r30 EYES ON THE REJECT: ship an image the delivery path never carries —
    the filmstrip of a REJECTED render and the raw article screenshots that fed
    it. Three fix rounds were spent reasoning about frames nobody could look at;
    the judge's prose alone is not evidence. Lands in media/diag/. Non-fatal."""
    try:
        if not img_path or not os.path.isfile(img_path):
            return
        if os.path.getsize(img_path) > 6 * 1024 * 1024:
            return
        with open(img_path, "rb") as fh:
            b64 = base64.b64encode(fh.read()).decode("ascii")
        body = {"token": INGEST_TOKEN, "action": "diag",
                "page_id": int(page_id), "name": str(name)[:60],
                "img_b64": b64}
        # r113: 25s, not 120s+fallback. On 2026-08-12 this diagnostic hung for
        # SIX MINUTES against an unreachable host (120s cffi, then requests
        # retrying), ate the judge stage's whole stall budget, and the watchdog
        # force-exited a render whose video was already finished and passing.
        # A diagnostic that can kill the render it is diagnosing is a bug.
        try:
            from curl_cffi import requests as cffi
            r = cffi.post(RECEIVE_URL, json=body, impersonate="firefox",
                          timeout=25, headers={"User-Agent": _BROWSER_UA})
        except Exception:  # noqa: BLE001
            r = requests.post(RECEIVE_URL, json=body, timeout=25,
                              headers={"User-Agent": _BROWSER_UA})
        log.info("DIAG %s -> HTTP %s", name, getattr(r, "status_code", "?"))
    except Exception as exc:  # noqa: BLE001
        log.info("DIAG %s failed: %s", name, str(exc)[:90])
    finally:
        # ...and it must not spend the RENDER's clock either: whatever this
        # cost, give the stage its time back before the watchdog reads it.
        global _STAGE_SINCE
        if _STAGE not in ("boot", "done"):
            _STAGE_SINCE = time.time()


def _heartbeat_loop(start_ts):
    while True:
        stalled = (_STAGE not in ("boot", "done") and _STAGE_SINCE and
                   (time.time() - _STAGE_SINCE) >
                   _STAGE_STALL_CAP.get(_STAGE, _STAGE_STALL_DEFAULT))
        _hb_post({
            "token": INGEST_TOKEN, "action": "heartbeat",
            "page_id": _STAGE_PID,
            "stage": ("STALLED:" + _STAGE) if stalled else _STAGE,
            "elapsed": round(time.time() - start_ts, 1),
        })
        if stalled:
            log.error("WATCHDOG: stage '%s' stalled past its cap — force-exit",
                      _STAGE)
            os._exit(7)          # nothing posted yet; safe to die + retry
        time.sleep(HEARTBEAT_EVERY_S)   # r35: 60s default — POST volume today got the runner blackholed by the WAF


def _start_heartbeat():
    global _HB_STARTED
    if _HB_STARTED or not INGEST_TOKEN:
        return
    _HB_STARTED = True
    import threading
    threading.Thread(target=_heartbeat_loop, args=(time.time(),),
                     daemon=True).start()


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
    """r55 TTS DEADLINE. edge-tts stream_sync() iterates a NETWORK stream that
    has no timeout of its own, so a stalled connection blocks forever. That is
    what killed four renders tonight: the stage watchdog kept escalating (180s
    -> 420s -> 482s) and threw away the whole video each time, including work
    that had already succeeded. Run the synthesis in a worker thread with a hard
    deadline instead; on timeout we RAISE, which lets the caller fall back to its
    simpler single-pass path (or a retry) rather than losing the render. The
    thread is a daemon, so an abandoned stall cannot keep the process alive.

    r57: the per-call deadline is now also clamped by the STAGE budget, so the
    ladder (expressive segments -> single-pass retries) can never collectively
    outrun the watchdog the way it did on 07-30."""
    import threading
    box = {}

    left = tts_left()
    if left <= 5:
        raise RuntimeError(
            f"TTS budget exhausted ({TTS_STAGE_BUDGET_S}s); not starting "
            "another call")
    deadline = min(TTS_CALL_TIMEOUT_S, left)

    def _run():
        try:
            box["sub"] = _edge_tts_stream(text, voice_name, rate_str,
                                          out_mp3, pitch_str)
        except BaseException as exc:  # noqa: BLE001 — reported to the caller
            box["err"] = exc

    th = threading.Thread(target=_run, daemon=True)
    th.start()
    th.join(deadline)
    if th.is_alive():
        log.warning("TTS DEADLINE: edge-tts stalled past %.0fs; abandoning this "
                    "call so the render can fall back (%.0fs stage budget left)",
                    deadline, max(tts_left(), 0))
        try:
            if os.path.exists(out_mp3) and os.path.getsize(out_mp3) == 0:
                os.remove(out_mp3)
        except OSError:
            pass
        raise RuntimeError(f"edge-tts stalled >{deadline:.0f}s")
    if "err" in box:
        raise box["err"]
    return box["sub"]


def _edge_tts_stream(text, voice_name, rate_str, out_mp3, pitch_str=None):
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
        # r57: never START an attempt that the stage budget cannot pay for.
        # Without this the retry ladder just walks into the watchdog.
        if tts_left() <= 5:
            log.warning("TTS: stage budget spent after %d attempt(s); giving "
                        "up cleanly instead of stalling into the watchdog",
                        attempt - 1)
            break
        try:
            log.info("TTS attempt %d/%d voice=%s rate=%s (%.0fs budget left)",
                     attempt, TTS_OUTER_RETRIES, _ACTIVE_VOICE[0], rate_str,
                     max(tts_left(), 0))
            if mpt_voice is not None:
                sub = mpt_voice.tts(
                    text=script,
                    voice_name=mpt_voice.parse_voice_name(_ACTIVE_VOICE[0]),
                    voice_rate=VOICE_RATE,
                    voice_file=out_mp3,
                    voice_volume=VOICE_VOLUME,
                )
                if sub is None:
                    raise RuntimeError("voice.tts() returned None")
                duration = float(mpt_voice.get_audio_duration(sub) or 0)
            else:
                sub = _edge_tts_synthesize(script, _ACTIVE_VOICE[0], rate_str, out_mp3)
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
            # r57: don't sleep away budget we do not have.
            wait = int(max(0, min(wait, tts_left() - 5)))
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
            # r57: ONE attempt per segment, no in-segment retry. The single-pass
            # path below IS the retry, and it is the cheaper one — retrying a
            # dead stream twice per segment across three segments is exactly
            # what burned 477s and got the render force-killed before any
            # fallback could run. First stall here = abandon expressive.
            try:
                sub = _edge_tts_synthesize(text, _ACTIVE_VOICE[0], rate_str, seg_mp3,
                                           pitch_str=pitch)
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(f"segment {si} TTS failed: {exc}") from exc
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
# r30: shoot at a REAL desktop width. At 1080 many news layouts overflow their
# min-width container, so a headline line can physically extend past x=1080 —
# the r29 "full 1080 band" then cut it mid-word anyway (judge failed exactly
# that, twice, WITH the full-width fix in). 1440 renders the desktop layout as
# designed; the crop below then takes the article COLUMN out of it.
# r76/r81 LEGIBILITY, corrected. r76 tried a 1000px viewport so text would
# upscale instead of shrink — and it DID make Kotaku legible, but it broke
# three other sites in the same render: under ~1024px many news sites switch
# to their tablet/mobile layout, the desktop column structure disappears, and
# headline detection dies with "no headline block found" (gamerant, TOI,
# netinfluencer all skipped; page 116 starved to ~5 images and failed on
# repetition). r81 keeps the DESKTOP layout (1440, where detection is proven)
# and gets legibility the right way: device_scale_factor renders the capture
# at high resolution, so after normalizing to the 1080 card the text arrives
# ~1.1x its CSS size instead of 0.75x. The height cap below (headline + lede,
# never a wall of body copy) stays — that half of r76 was right.
SHOT_VIEW_W = int(os.environ.get("VIDEO_SHOT_VIEW_W", "1440"))
SHOT_VIEW_H = int(os.environ.get("VIDEO_SHOT_VIEW_H", "1800"))
SHOT_DSF    = float(os.environ.get("VIDEO_SHOT_DSF", "1.4"))
# Cap a card at headline + lede rather than a whole article, as a multiple of
# its own width. 1.25 keeps the story's first beat and drops the long tail.
SHOT_MAX_H_RATIO = float(os.environ.get("VIDEO_SHOT_MAX_H_RATIO", "1.25"))
SHOT_PAD    = 28               # breathing room around the measured text/photo

# r30c CROP GEOMETRY, measured in the page instead of guessed from one box.
# The diag frames (media/diag/) showed the crop slicing through whatever
# happened to sit on each edge: a headline cut at "...The Most Ag", a "Save for
# late[r]" button halved on the right, a breadcrumb halved at the top, a
# newsletter bar and an "HBCU AWARE FEST" ad poster swept in at the bottom.
# Three rules kill the whole class:
#   1. HORIZONTAL = THE COLUMN. The narrowest ancestor that still contains the
#      headline is the article column. A box is never narrower than the text it
#      lays out, so cropping to it CANNOT bisect a word — and the right rail,
#      being outside it, cannot get in.
#   2. The lead image must sit just BELOW the headline (<=520px). An in-content
#      promo/ad poster further down is not the lead image.
#   3. SNAP EDGES TO GAPS. Anything text-bearing that straddles the top or
#      bottom edge pulls that edge off it, so no edge ever lands mid-line.
# r32 (owner: "look for what's built and implement it here, better than
# building it"): stop hand-rolling article detection. Mozilla's Readability —
# the Firefox Reader Mode engine, the arc90 lineage every extractor descends
# from — already decides what on a page IS the article. Injected as an init
# script (CDP injection is not subject to the page's CSP, unlike add_script_tag)
# and run with `serializer: el => el`, which is the documented way to get a DOM
# element back instead of an HTML string. It parses a CLONE, so every live
# element is stamped with data-gzid first and the article's nodes are mapped
# back through those stamps — that bridge is what makes its verdict usable as
# live geometry. The union of those live boxes IS the column. Falls back to the
# r30c ancestor heuristic when Readability is absent or declines the page.
READABILITY_JS = os.environ.get("VIDEO_READABILITY_JS", "vendor/Readability.js")
_READABILITY_COL_JS = """() => {
  try {
    if (typeof Readability !== 'function') return null;
    const nodes = document.querySelectorAll('*');
    for (let i = 0; i < nodes.length; i++) nodes[i].setAttribute('data-gzid', String(i));
    const clone = document.cloneNode(true);
    const art = new Readability(clone, {serializer: (el) => el,
                                        keepClasses: true,
                                        charThreshold: 200}).parse();
    if (!art || !art.content || !art.content.querySelectorAll) return null;
    const marked = art.content.querySelectorAll('[data-gzid]');
    const sx = window.scrollX;
    let L = Infinity, R = -Infinity, n = 0;
    for (const m of marked) {
      const live = document.querySelector('[data-gzid="' + m.getAttribute('data-gzid') + '"]');
      if (!live) continue;
      const b = live.getBoundingClientRect();
      if (b.width < 220 || b.height < 12) continue;
      const txt = (live.textContent || '').trim();
      if (txt.length < 40 && live.tagName !== 'IMG') continue;
      L = Math.min(L, b.left + sx); R = Math.max(R, b.right + sx); n++;
    }
    if (!n || !isFinite(L) || R - L < 260) return null;
    return {l: L, r: R, n: n, title: String(art.title || '').slice(0, 120)};
  } catch (e) { return null; }
}"""

_CROP_JS = """(node, rcol) => {
  const sx = window.scrollX, sy = window.scrollY;
  const de = document.documentElement;
  const docw = Math.max(de.scrollWidth, de.clientWidth);
  const doch = Math.max(de.scrollHeight, de.clientHeight);
  const abs = (b) => ({l: b.left + sx, t: b.top + sy,
                       r: b.right + sx, bo: b.bottom + sy});
  const box = (el) => abs(el.getBoundingClientRect());

  // 1. the headline's REAL glyph box: line boxes, not the element box
  const rg = document.createRange();
  rg.selectNodeContents(node);
  const trs = Array.from(rg.getClientRects()).filter(b => b.width > 1 && b.height > 1);
  const src = trs.length ? trs : [node.getBoundingClientRect()];
  const hl = {
    l: Math.min(...src.map(b => b.left)) + sx,
    r: Math.max(...src.map(b => b.right)) + sx,
    t: Math.min(...src.map(b => b.top)) + sy,
    bo: Math.max(...src.map(b => b.bottom)) + sy
  };

  // 2. the COLUMN — nearest ancestor that fully contains the headline
  let col = {l: hl.l, r: hl.r};
  let root = node.parentElement || document.body;
  for (let el = node.parentElement; el; el = el.parentElement) {
    const b = box(el), w = b.r - b.l;
    if (w >= (hl.r - hl.l) - 2 && w >= 320) {
      col = {l: b.l, r: b.r}; root = el;
      if (w >= 380) break;
    }
    if (el === document.body) break;
  }
  // r32: Readability's own verdict on what the article is beats the ancestor
  // guess — its node set is the body text, so the union of those live boxes is
  // the column. Union with the headline glyphs in case the headline is a
  // full-bleed hero wider than the body column.
  if (rcol && rcol.r > rcol.l) {
    col = {l: Math.min(rcol.l, hl.l), r: Math.max(rcol.r, hl.r)};
  }

  // r33 WIKIPEDIA: its "article" is a wall of body text that contain-fits into
  // an unreadable grey block (that is what shipped at 6.8-8.4s of the El
  // Risitas video). The INFOBOX is the legible proof — portrait, name, dates,
  // a few facts — so on wikipedia the crop is the infobox plus the title.
  const _host = (typeof location !== 'undefined' && location.hostname) || '';
  if (_host.indexOf('wikipedia.org') >= 0) {
    const ib = document.querySelector && document.querySelector('table.infobox, .infobox');
    if (ib) {
      const b = box(ib);
      if (b.r - b.l > 180 && b.bo - b.t > 180) {
        const pad2 = 18;
        return {x: Math.max(0, Math.min(b.l, hl.l) - pad2),
                y: Math.max(0, hl.t - pad2),
                w: Math.min(docw, Math.max(b.r, hl.r) + pad2) -
                   Math.max(0, Math.min(b.l, hl.l) - pad2),
                h: Math.min(b.bo + pad2, doch) - Math.max(0, hl.t - pad2),
                docw: docw, doch: doch, img: true, colw: b.r - b.l,
                wiki: true};
      }
    }
  }

  // 3. the LEAD image: big, below the headline, and CLOSE below it
  let img = null;
  const imgs = Array.from(root.querySelectorAll('img')).slice(0, 40);
  for (const im of imgs) {
    const b = box(im), w = b.r - b.l, h = b.bo - b.t;
    if (w < 260 || h < 140) continue;
    if (b.t < hl.bo - 8) continue;
    if (b.t > hl.bo + 520) continue;
    img = b; break;
  }

  const pad = 24;
  let L = Math.max(0, Math.min(col.l, img ? img.l : col.l) - pad);
  let R = Math.min(docw, Math.max(col.r, img ? img.r : col.r) + pad);
  let T = Math.max(0, hl.t - 18);
  let B = img ? img.bo + 14 : hl.bo + 380;
  // r31 FILL THE PHONE. A wide-and-short crop contain-fits into a thin strip
  // with a huge dead blur below it (that is what the delivered r30c video
  // looked like). Reach for ~4:5 by carrying on down the column — but stop
  // short of the next IMAGE/embed, because that is where promos and ad
  // posters live. Body text between is real proof and reads fine.
  const target = T + (R - L) * 1.25;
  if (B < target) {
    let limit = target;
    for (const im of root.querySelectorAll('img, iframe, video')) {
      const b = box(im);
      if (b.t >= B + 4 && b.t < limit) limit = b.t - 8;
    }
    B = Math.max(B, Math.min(target, limit));
  }
  B = Math.min(B, T + (R - L) * 1.25);   // legibility: keep it near 4:5

  // 4. snap the horizontal edges off anything they cut through
  const els = Array.from(root.querySelectorAll('*')).slice(0, 1500);
  const cutters = [];
  for (const el of els) {
    let text = false;
    for (const c of el.childNodes) {
      if (c.nodeType === 3 && c.textContent.trim().length > 1) { text = true; break; }
    }
    if (!text && el.tagName !== 'IMG') continue;
    const b = box(el);
    if (b.bo - b.t < 6 || b.r - b.l < 6) continue;
    if (b.r <= L || b.l >= R) continue;          // not in the band at all
    cutters.push(b);
  }
  for (let pass = 0; pass < 3; pass++) {
    let moved = false;
    for (const b of cutters) {
      // starts above the edge and runs past it -> the edge is mid-element
      if (b.t < B && b.bo > B) { B = b.t - 6; moved = true; }
      if (b.t < T && b.bo > T) {
        const nt = Math.min(b.bo + 6, hl.t - 4);
        if (nt > T) { T = nt; moved = true; }
      }
    }
    if (!moved) break;
  }

  const w = R - L, h = B - T;
  if (w < 420 || h < 320) return null;           // unusable -> python fallback
  return {x: L, y: T, w: w, h: h, docw: docw, doch: doch,
          img: !!img, colw: col.r - col.l};
}"""

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


def _shot_dead_zone_fix(path):
    """r61 DEAD-ZONE BACKSTOP (page 484 shipped a giant black rectangle where
    an in-article video player never painted). Deterministic — PIL only, no
    vision quota, so it cannot go silent the way the Gemini ad-gate does when
    the key is over cap. Detection: rows where >35% of pixels are near-black
    (lum < 22) — the box spans only the article COLUMN, so whole-row
    uniformity does NOT work (measured: 0 hits on the exact 484 frame).
    Dark-photo guard: the band's black pixels must be FLAT fill (std < 6;
    measured 0.29 on 484) — a night photo's darks carry texture and pass.
    The band is spliced out (top+bottom rejoined; band edges are dead rows so
    the seam cannot cut text — verified visually on the 484 frame: headline,
    photo and body text flow cleanly). Too little page left, or still >20%
    dead after repair -> reject; the og-photo/subject chain covers the proof.
    Returns True if the file at `path` is usable (possibly rewritten)."""
    try:
        im = Image.open(path).convert("RGB")
        g = np.asarray(im.convert("L")).astype("float32")
        h, w = g.shape
        if h < 120:
            im.close()
            return True
        # MEASURED on the real 484 shot: the player box spans only the article
        # COLUMN (black left, white sidebar right), so whole-row uniformity
        # never fires — the first version of this check found 0 dead rows on
        # the exact frame it was written for. The working signal is per-row
        # NEAR-BLACK FRACTION: the box rows are >35% pixels under lum 22.
        black = g < 22.0
        frac = black.mean(axis=1)
        dead = frac > 0.35
        # largest contiguous dead band
        best_s, best_e, s = 0, 0, -1
        for y in range(h + 1):
            if y < h and dead[y]:
                if s < 0:
                    s = y
            elif s >= 0:
                if y - s > best_e - best_s:
                    best_s, best_e = s, y
                s = -1
        band = best_e - best_s
        if band < max(60, int(h * 0.12)):
            im.close()
            return True                       # nothing meaningful to repair
        # dark-PHOTO guard: a night photo can be >35% near-black, but its dark
        # pixels carry texture. An unpainted player is FLAT fill: the band's
        # black-pixel std is ~0 (measured 484: pure 0s). Std >= 6 -> real
        # photo content, leave the frame alone.
        band_black = g[best_s:best_e][black[best_s:best_e]]
        if band_black.size == 0 or float(band_black.std()) >= 6.0:
            im.close()
            return True
        keep_top = im.crop((0, 0, w, best_s)) if best_s > 0 else None
        keep_bot = im.crop((0, best_e, w, h)) if best_e < h else None
        parts = [p for p in (keep_top, keep_bot) if p is not None and p.height >= 40]
        if not parts:
            im.close()
            log.info("SHOT DEAD-ZONE: frame is one dead band; rejected (%s)",
                     os.path.basename(path))
            return False
        if len(parts) == 1:
            fixed = parts[0]
        else:
            fixed = Image.new("RGB", (w, parts[0].height + parts[1].height))
            fixed.paste(parts[0], (0, 0))
            fixed.paste(parts[1], (0, parts[0].height))
        if fixed.height < 260:                # too little page left to read
            im.close()
            log.info("SHOT DEAD-ZONE: only %dpx of page survives; rejected "
                     "(%s)", fixed.height, os.path.basename(path))
            return False
        fixed.save(path)
        im.close()
        # re-measure with the SAME fraction test: if the repaired frame still
        # has >20% dead rows (a second player box), reject rather than loop
        g2 = np.asarray(Image.open(path).convert("L")).astype("float32")
        if float(((g2 < 22.0).mean(axis=1) > 0.35).mean()) > 0.20:
            log.info("SHOT DEAD-ZONE: still >20%% dead after repair; rejected "
                     "(%s)", os.path.basename(path))
            return False
        log.info("SHOT DEAD-ZONE: removed %dpx dead band at y=%d (%s)",
                 band, best_s, os.path.basename(path))
        return True
    except Exception as exc:  # noqa: BLE001 — never block a shot on infra
        log.info("dead-zone check failed open (%s)", str(exc)[:60])
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
            # r91 STOP LOOKING LIKE A BOT. Measured in the PixelRAG bake-off:
            # Variety and Times of India are ALIVE from our own server (HTTP
            # 200) but served this runner a homepage and a 404. They were not
            # link rot and not a detection bug — we were being fingerprinted.
            #
            # The loudest tell was our own doing: this is CHROMIUM wearing a
            # FIREFOX user agent. Chromium sends sec-ch-ua client hints and
            # exposes navigator.userAgentData; Firefox does neither. Claiming
            # to be Firefox while emitting Chrome signals is a sharper flag
            # than sending nothing at all. So the screenshot browser now tells
            # the truth about being Chrome, with matching hints, and drops the
            # automation markers a real browser does not carry.
            browser = pw.chromium.launch(
                headless=True,
                args=["--disable-blink-features=AutomationControlled",
                      "--disable-features=IsolateOrigins,site-per-process"])
            ctx = browser.new_context(
                viewport={"width": SHOT_VIEW_W, "height": SHOT_VIEW_H},
                device_scale_factor=SHOT_DSF,      # r81: hi-dpi = legible text
                user_agent=SHOT_UA, locale="en-US",
                timezone_id="America/New_York",
                extra_http_headers={
                    "Accept-Language": "en-US,en;q=0.9",
                    "sec-ch-ua": SHOT_CH_UA,
                    "sec-ch-ua-mobile": "?0",
                    "sec-ch-ua-platform": '"Windows"',
                })
            # navigator.webdriver is true in every automated browser and false
            # in every real one; it is the single cheapest check a publisher
            # can run, so it goes before any page script runs.
            try:
                ctx.add_init_script(
                    "Object.defineProperty(navigator,'webdriver',{get:()=>undefined});"
                    "Object.defineProperty(navigator,'languages',"
                    "{get:()=>['en-US','en']});"
                    "window.chrome = window.chrome || {runtime:{}};")
            except Exception:  # noqa: BLE001 — never fatal
                pass
            # r32: Readability as an init script — injected through CDP before
            # any page script, so a strict Content-Security-Policy (most news
            # sites) cannot block it the way it would block add_script_tag.
            if os.path.isfile(READABILITY_JS):
                try:
                    ctx.add_init_script(path=READABILITY_JS)
                    log.info("Readability injected from %s", READABILITY_JS)
                except Exception as exc:  # noqa: BLE001
                    log.info("Readability inject failed (%s); ancestor "
                             "heuristic only", str(exc)[:80])
            else:
                log.info("Readability.js absent (%s); ancestor heuristic only",
                         READABILITY_JS)
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
                    # r29: hard-cap EVERY locator/action to 3.5s. The r28
                    # topic-aware headline scan (bounding_box + text_content on
                    # up to 10 h1s per page) inherited Playwright's 30s default,
                    # so a slow/detached element could stall a page for minutes
                    # and blow the render past its timeout. This bounds it.
                    try:
                        page.set_default_timeout(3500)
                    except Exception:  # noqa: BLE001
                        pass
                    # r18 GRAFT B: network ad-block — abort ad/tracker requests
                    # BEFORE navigation so no ad/analytics furniture ever paints.
                    try:
                        page.route("**/*", _block_ads)
                    except Exception:  # noqa: BLE001 — best-effort
                        pass
                    _resp = page.goto(url, wait_until="domcontentloaded",
                                      timeout=15000)
                    page.wait_for_timeout(1500)
                    # r91 NAME THE FAILURE. The bake-off showed four of these
                    # URLs were dead pages and two were bot-blocks served as a
                    # homepage — all of which reached us as the same vague
                    # "no headline found". A refusal we cannot explain is a
                    # refusal we cannot fix, so say which one it is.
                    try:
                        _status = _resp.status if _resp else 0
                        _t = (page.title() or "").lower()
                        _dead = any(s in _t for s in (
                            "404", "not found", "page unavailable",
                            "page not found", "access denied", "forbidden"))
                        if _status >= 400 or _dead:
                            log.info("screenshot: DEAD PAGE (http %s, title %r) "
                                     "— the source itself is gone, not a "
                                     "detection failure: %s",
                                     _status, (page.title() or "")[:60], url[:80])
                            url_shot[url] = None
                            page.close()
                            continue
                    except Exception:  # noqa: BLE001
                        pass
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
                          // r29: REMOVE (display:none, not just hide) ad + COMMERCE
                          // furniture so the lead-image picker can't grab a merch /
                          // shop / "buy our t-shirt" box (that exact box shipped in
                          // one frame). Broadened well past 'ad/sponsor' to the
                          // shop/store/merch/product/deal/affiliate + recirc widgets
                          // that carry their own big images. display:none also drops
                          // them from layout so nothing is measured or shot.
                          // r61: EMBEDDED-PLAYER KILL. Page 484 shipped with a
                          // huge BLACK dead zone: an in-article video player
                          // (a <video>/JS widget, NOT an iframe) that never
                          // paints in headless chromium. Kill the player
                          // family too; display:none reflows the text/lead
                          // image up into the space.
                          const sels = ['iframe', 'video',
                            '[class*="jwplayer" i]', '[class*="vjs-" i]',
                            '[class*="video-player" i]', '[class*="player-" i]',
                            '[id*="player" i]', '[data-player]',
                            '[class*="video-container" i]',
                            '[class*="connatix" i]', '[class*="brid-" i]',
                            '[id*="ad-" i]', '[id^="ad" i]', '[id*="-ad" i]',
                            '[class*="advert" i]', '[class*="-ad-" i]',
                            '[class*="sponsor" i]', '[class*="promo" i]',
                            '[class*="newsletter" i]', '[class*="subscribe" i]',
                            '[class*="merch" i]', '[class*="shop" i]',
                            '[class*="store" i]', '[class*="product" i]',
                            '[class*="commerce" i]', '[class*="affiliate" i]',
                            '[class*="deal" i]', '[class*="buy-" i]',
                            '[class*="related" i]', '[class*="recirc" i]',
                            '[class*="trending" i]', '[class*="outbrain" i]',
                            '[class*="taboola" i]', '[class*="widget" i]',
                            // run #232: Variety shipped an "ALLOW ADS"
                            // adblock-recovery begging box over the article
                            // photo (judge was quota-skipped so it aired).
                            // Kill consent/adblock/paywall furniture too —
                            // the shell guard below protects the article.
                            '[class*="adblock" i]', '[id*="adblock" i]',
                            '[class*="ad-block" i]', '[class*="allow-ads" i]',
                            '[class*="consent" i]', '[id*="consent" i]',
                            '[class*="paywall" i]', '[class*="regwall" i]',
                            // run #233 (510): "AOL Ads keep us running" box
                            // by the Admiral adblock-recovery vendor rode
                            // OVER the article photo — its markup carries
                            // none of the generic words above. Admiral
                            // mounts as a transparent-window engagement
                            // element; kill by vendor marks.
                            '[class*="admiral" i]', '[id*="admiral" i]',
                            '[data-admiral]', 'admiral-engagement',
                            '[class*="transparent-window" i]',
                            // run #244: an article-embedded TikTok's poster
                            // (a commentary creator's promo frame) rode the
                            // screenshot crop as if it were the article's
                            // lead image — kill social embeds pre-shot
                            'blockquote.tiktok-embed', '[class*="tiktok-embed" i]',
                            'blockquote.twitter-tweet', '.instagram-media',
                            '[class*="social-embed" i]', '[class*="embed-container" i]',
                            '[aria-label*="advertisement" i]',
                            'aside',
                            'a[href*="shop" i]', 'a[href*="/store" i]',
                            'a[href*="merch" i]', 'a[href*="amazon" i]',
                            'a[href*="amzn" i]', 'a[href*="teespring" i]'];
                          for (const sel of sels) {
                            let els = [];
                            try { els = document.querySelectorAll(sel); }
                            catch (e) { continue; }
                            for (const el of els) {
                              try {
                                // never nuke the whole page/article shell
                                const r = el.getBoundingClientRect();
                                if (r.width > 1000 && r.height > 2000) continue;
                                el.style.display = 'none';
                              } catch (e) {}
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
                        h1, h1_el = None, None
                        # r30: measure everything from the TOP of the document so
                        # element boxes (viewport-relative) and the text rects
                        # below (document coords) live in the same space — a
                        # cookie-banner click may have scrolled the page.
                        try:
                            page.evaluate("() => window.scrollTo(0, 0)")
                        except Exception:  # noqa: BLE001
                            pass
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
                                cands.append((bb, txt, el))
                            if topic_kw:
                                for bb, txt, el in cands:
                                    if any(kw in txt for kw in topic_kw):
                                        h1, h1_el = bb, el
                                        break
                                if h1 is None and cands:
                                    log.info("screenshot: no ON-TOPIC headline "
                                             "(%s) on %s; skipping", topic_kw[:3],
                                             url[:70])
                                    # r58: remember the refusal. This verdict is
                                    # deterministic — the same URL will fail it
                                    # every time — but the r22 url_shot cache was
                                    # only written on the LATER paths, so a URL
                                    # appearing at two receipt indexes was fetched
                                    # and re-judged twice. Run #198 spent ~11s
                                    # doing exactly that on one NYT article while
                                    # the screenshot stage runs against a hard
                                    # wall-clock budget, i.e. the waste is taken
                                    # straight out of other proofs' chances.
                                    url_shot[url] = None
                                    page.close()
                                    continue
                            elif cands:
                                h1, h1_el = cands[0][0], cands[0][2]
                        except Exception:  # noqa: BLE001
                            h1, h1_el = None, None
                        if not h1:
                            log.info("screenshot: no headline block found; "
                                     "skipping (no raw-page fallback): %s",
                                     url[:90])
                            url_shot[url] = None      # r58: also deterministic
                            page.close()
                            continue
                        # r30c: the whole crop is measured in the page by
                        # _CROP_JS (column + lead image + edges snapped off any
                        # text they cut). The pre-r30c math below is the
                        # fallback for a page where that returns nothing.
                        crop = None
                        if h1_el is not None:
                            rcol = None
                            try:                    # r32: Readability's column
                                rcol = page.evaluate(_READABILITY_COL_JS)
                                if rcol:
                                    log.info("Readability column %.0f..%.0f "
                                             "(%d nodes) on %s", rcol["l"],
                                             rcol["r"], rcol["n"], url[:55])
                            except Exception:  # noqa: BLE001
                                rcol = None
                            try:
                                crop = h1_el.evaluate(_CROP_JS, rcol)
                            except Exception:  # noqa: BLE001
                                crop = None
                        img_bb = None
                        if not crop:
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
                        # r30 TEXT-RECT CROP — fixes BOTH r29 judge failures at
                        # once ("headline cut mid-word" AND "sidebar/ad clutter").
                        # An h1's ELEMENT box is not where its TEXT is: a block
                        # h1 can measure narrower than its rendered lines (r29's
                        # mid-word cut) and r29's answer — shoot the whole
                        # viewport — simply re-admitted the right-rail furniture
                        # r27 had cropped out (the very next run failed on it).
                        # Measure the REAL line boxes with a Range over the h1's
                        # contents: their union is, to the pixel, every glyph of
                        # the headline. Crop to that union + the lead image +
                        # padding — a crop that contains every glyph cannot
                        # bisect a word, and it is still a COLUMN crop, so the
                        # sidebar stays out. Both failure modes closed together.
                        tb = None
                        if (not crop) and h1_el is not None:
                            try:
                                tb = h1_el.evaluate("""node => {
                                  const r = document.createRange();
                                  r.selectNodeContents(node);
                                  const rs = Array.from(r.getClientRects())
                                      .filter(b => b.width > 1 && b.height > 1);
                                  const box = node.getBoundingClientRect();
                                  const all = rs.length ? rs : [box];
                                  const sx = window.scrollX, sy = window.scrollY;
                                  const de = document.documentElement;
                                  return {
                                    left:   Math.min(...all.map(b => b.left))   + sx,
                                    right:  Math.max(...all.map(b => b.right))  + sx,
                                    top:    Math.min(...all.map(b => b.top))    + sy,
                                    docw: Math.max(de.scrollWidth, de.clientWidth),
                                    doch: Math.max(de.scrollHeight, de.clientHeight)
                                  };
                                }""")
                            except Exception:  # noqa: BLE001
                                tb = None
                        tb = tb or {}
                        doc_w = float(tb.get("docw") or SHOT_VIEW_W)
                        doc_h = float(tb.get("doch") or SHOT_VIEW_H)
                        t_left = float(tb.get("left", h1["x"]))
                        t_right = float(tb.get("right", h1["x"] + h1["width"]))
                        t_top = float(tb.get("top", h1["y"]))
                        # r27 (owner: balleralert's "Get Your Baller Alerts"
                        # signup box showed BESIDE the headline): the column, not
                        # the page width. The lead image spans the column, so its
                        # edges widen the crop only as far as the column goes.
                        left = min(t_left, img_bb["x"]) if img_bb else t_left
                        right = (max(t_right, img_bb["x"] + img_bb["width"])
                                 if img_bb else t_right)
                        x = max(0.0, left - SHOT_PAD)
                        right = min(right + SHOT_PAD, doc_w, float(SHOT_VIEW_W))
                        width = max(560.0, right - x)
                        # r27 (owner: "dexerto is our COMPETITOR, why are we
                        # giving them views/brand on our back"): crop from just
                        # above the HEADLINE, not the masthead — so the publisher
                        # logo + top nav (the competitor's brand) never show. The
                        # headline + lead image is the proof; the brand is not.
                        y = max(0.0, t_top - 16)
                        if img_bb:
                            bottom = img_bb["y"] + img_bb["height"] + 40
                        else:
                            bottom = t_top + 700
                        # r20 legibility: a tall narrow band contain-fits into an
                        # unreadable sliver (judge criterion c2) — hold the proof
                        # near 4:5 relative to its own width.
                        height = max(600.0, min(1350.0, width * 1.25, bottom - y))
                        height = min(height, max(1.0, doc_h - y))
                        if crop:                       # r30c measured geometry
                            x = float(crop["x"])
                            y = float(crop["y"])
                            width = min(float(crop["w"]), float(SHOT_VIEW_W) - x)
                            height = min(float(crop["h"]),
                                         max(1.0, float(crop["doch"]) - y))
                            doc_h = float(crop["doch"])
                            log.info("crop r30c: %.0fx%.0f at %.0f,%.0f "
                                     "(col %.0f, lead-img %s) %s",
                                     width, height, x, y, crop.get("colw", 0),
                                     crop.get("img"), url[:60])
                        # r41 (runs #160/#162 both died in receipts): full_page
                        # makes chromium RENDER THE ENTIRE DOCUMENT before
                        # clipping — boundless on an infinite-scroll news page,
                        # so the stage watchdog fired. The pre-r30 code shipped
                        # tall document-coordinate clips WITHOUT full_page for
                        # months (capture-beyond-viewport handles it); do that,
                        # and cap the crop at one viewport of height.
                        height = min(height, float(SHOT_VIEW_H))
                        # r76: and never taller than headline+lede for its own
                        # width — a whole-article card is unreadable at 9:16.
                        height = min(height, float(width) * SHOT_MAX_H_RATIO)
                        clip = {"x": x, "y": y, "width": width, "height": height}
                        page.screenshot(path=path, clip=clip)
                        # normalize the column crop to card width (1440-wide
                        # layouts shoot WIDER than 1080 now, so downscale too)
                        try:
                            im = Image.open(path)
                            if im.width != 1080:
                                r = 1080.0 / im.width
                                im = im.resize((1080, max(1, int(im.height * r))),
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
                # r61 DEAD-ZONE BACKSTOP (deterministic, quota-free): repair or
                # reject black player/embed rectangles BEFORE the vision gate,
                # so the fix works even when Gemini is over cap.
                if not _shot_dead_zone_fix(path):
                    url_shot[url] = None
                    continue
                # r29 AD BACKSTOP: vision-verify the shot is clean of ad / merch /
                # furniture; unclean -> drop it so the og:image (guaranteed clean
                # article photo) covers this proof instead of shipping the ad.
                if not screenshot_is_clean(path):
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


# r37 GITHUB BUS. Run #156 proved the runner<->Hostinger HTTP path is an IP
# lottery: bitninja blackholes some runner IPs at TCP connect (the feed GET
# timed out 3x45s; the previous run's POSTs landed fine). GitHub is the one
# host BOTH sides always reach, so the server publishes the job feed + its
# visuals to a `video-feed` branch (checked out by the workflow into
# VIDEO_FEED_DIR) and failed deliveries ride the `video-drop` branch home.
# HTTP stays as the fast path when the IP happens to be clean.
FEED_DIR = os.environ.get("VIDEO_FEED_DIR", "")


def _feed_local_visual(url):
    """A visual pre-staged on the video-feed branch: visuals/<sha1(url)>."""
    if not (FEED_DIR and url):
        return None
    p = os.path.join(FEED_DIR, "visuals",
                     hashlib.sha1(url.encode("utf-8")).hexdigest())
    return p if os.path.isfile(p) and os.path.getsize(p) > 2000 else None


def _download_bytes(url):
    lp = _feed_local_visual(url)     # r37: repo-staged copy beats the network
    if lp:
        with open(lp, "rb") as f:
            return f.read()
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


def upsize_image_url(url):
    """r49 SUPPLY (owner: "still no progress" — and the r47 resolution floor
    proved why: once blurry stills are refused only ~9 usable images per story
    remain, which cannot carry a 60s video). Most of that loss is
    self-inflicted: publishers hand us DELIBERATELY SHRUNK renditions and we
    downloaded them as-is. This asks the same host for a big version, so the
    resolution floor keeps the image instead of dropping it. Pure URL rewrite —
    no new dependency, and fetch_visual retries the ORIGINAL if a rewrite 404s.
      - WordPress size suffix   photo-800x600.jpg -> photo.jpg
      - WP/Jetpack width params ?w=1024 / ?width=640 -> 1600
      - Cloudflare image resize /cdn-cgi/image/?width=640/<real> -> width=1600
      - Google avatars          =s176-c-k / =w480-h270 -> =s1200
    """
    u = str(url or "")
    if not u.startswith("http"):
        return u
    try:
        # Google/YouTube avatar + thumbnail sizing suffix
        u = re.sub(r"=(?:s|w)\d+(-h\d+)?([-a-z0-9]*)$", "=s1200", u)
        # Cloudflare (and similar) on-the-fly resizers
        u = re.sub(r"(cdn-cgi/image/[^/]*?)width=\d+", r"\1width=1600", u)
        # WordPress hard-coded size suffix: keep the original file
        u = re.sub(r"-\d{2,4}x\d{2,4}(\.(?:jpe?g|png|webp))(\?|$)", r"\1\2", u)
        # width query params (WP, Jetpack/Photon, Commons Special:FilePath)
        def _bump(m):
            return m.group(1) + ("1600" if int(m.group(2)) < 1600 else m.group(2))
        u = re.sub(r"([?&](?:w|width)=)(\d+)", _bump, u)
    except Exception:  # noqa: BLE001 — a rewrite must never break a fetch
        return str(url or "")
    return u


def fetch_visual(url, dest, trim=True):
    """Download + validate one visual. Corrupt/tiny/unreadable -> None (dropped
    from the pool), never a crash. Letterbox bars are trimmed on arrival
    (trim=False for receipt cards: their dark paper background sits near the
    bar-detector threshold and must never be shaved).
    r49: a BIGGER rendition is requested first (upsize_image_url); if that fails
    the original URL is retried, so upsizing can only ever add resolution."""
    _big = upsize_image_url(url)
    data = None
    if _big != url:
        data = _download_bytes(_big)
        if data and len(data) >= 2000:
            log.info("UPSIZED: %s", _big[:110])
        else:
            data = None
    if data is None:
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


def image_quality(path):
    """r40 (owner watched the r39 opener: an over-zoomed blurry orange face —
    the worst possible first 1.2 seconds). (megapixels, sharpness) so the
    planner can rank visuals: sharpness = std of an edge-filtered 256px thumb.
    Cheap, no ML, never raises."""
    try:
        from PIL import ImageFilter
        im = Image.open(path).convert("L")
        w, h = im.size
        t = im.copy()
        t.thumbnail((256, 256))
        edges = t.filter(ImageFilter.FIND_EDGES)
        sharp = float(np.asarray(edges).std())
        im.close()
        t.close()
        return (w * h / 1e6, sharp)
    except Exception:  # noqa: BLE001
        return (0.0, 0.0)


def image_dhash(path, size=8):
    """r36: 64-bit difference hash. Run #155's pool held the SAME photo under
    two different URLs (identical YuNet face boxes proved it), so the per-path
    variety cap 'spread' scenes across two copies of one image and the judge
    counted 8 frames of the same face. Content identity, not URL identity."""
    try:
        g = Image.open(path).convert("L").resize((size + 1, size),
                                                 Image.Resampling.LANCZOS)
        px = list(g.getdata())
        g.close()
        bits = 0
        for row in range(size):
            for col in range(size):
                bits = (bits << 1) | (px[row * (size + 1) + col]
                                      > px[row * (size + 1) + col + 1])
        return bits
    except Exception:  # noqa: BLE001
        return None


def dhash_distance(a, b):
    return bin(a ^ b).count("1") if a is not None and b is not None else 64


_STILL_REL_CACHE = {}
_STILL_REL_CALLS = [0]
STILL_REL_MAX_CALLS = int(os.environ.get("VIDEO_STILL_REL_MAX", "8"))


# ==================  FREE-AI VISION ROTATION (2026-08-06)  ==================
# The nightly judge blindness had a cause: plain gemini-flash free tier is
# now ~20 requests/DAY. The SAME key serves gemma-4-31b-it (14,400 RPD) and
# gemini-3.5-flash-lite (500 RPD) — verified live against our key — plus the
# owner's new Groq key (qwen3.6-27b vision, 1,000 RPD, MAX 5 images/call)
# and Cloudflare Workers AI (llama-3.2-11b-vision, 10k neurons/day,
# single-image calls; license 'agree' already submitted, body shape
# verified live). ONE wrapper serves every vision call site; each tier
# failure falls to the next; total exhaustion returns None and every
# caller keeps its existing fail-open/fail-closed semantics unchanged.

GROQ_API_KEY = os.environ.get("GROQ_API_KEY", "")
CF_ACCOUNT_ID = os.environ.get("CF_ACCOUNT_ID", "")
CF_AI_TOKEN = os.environ.get("CF_AI_TOKEN", "")
_VISION_TIER_USED = {}      # tier name -> calls served this run


def _strip_think(t):
    """qwen/gemma emit <think>reasoning</think> before the answer."""
    return re.sub(r"<think>.*?</think>", "", t or "", flags=re.S).strip()


def _json_slice(t):
    """Reasoning models wrap JSON in prose — return the outermost {...}
    slice when it parses, else the text unchanged (caller's parsing rules
    stay authoritative)."""
    a, b = t.find("{"), t.rfind("}")
    if 0 <= a < b:
        cand = t[a:b + 1]
        try:
            json.loads(cand)
            return cand
        except Exception:  # noqa: BLE001
            pass
    return t


def _gemini_to_openai_content(body):
    out = []
    for part in (body.get("contents") or [{}])[0].get("parts", []):
        if part.get("text"):
            out.append({"type": "text", "text": part["text"]})
        elif "inline_data" in part:
            d = part["inline_data"]
            out.append({"type": "image_url", "image_url": {
                "url": f"data:{d.get('mime_type', 'image/jpeg')};base64,"
                       f"{d.get('data', '')}"}})
    return out


def vision_post(body, timeout=60, tag="vision", strong_first=False):
    """POST a Gemini-shaped vision request through the free-tier rotation.
    Returns the response TEXT (think-stripped; JSON isolated when the call
    asked for JSON) or None when every tier is down. Logs the serving tier
    whenever it is not the primary, so quota drift is visible in render
    logs.
    strong_first (run #240 lesson): GATE calls that decide what reaches the
    screen use the best rubric-follower FIRST — gemma-31b passed promo-slide
    'screenshots' as clean that flash-lite's judge then rejected. Flash-Lite
    has 500 RPD; gates cost ~10/render, easily afforded."""
    wants_json = "response_mime_type" in (body.get("generationConfig") or {})
    n_imgs = sum(1 for p in (body.get("contents") or [{}])[0].get("parts", [])
                 if "inline_data" in p)
    order = (GEMINI_MODEL, "gemma-4-31b-it", "gemini-3.5-flash-lite")
    if strong_first:
        order = ("gemini-3.5-flash-lite", GEMINI_MODEL, "gemma-4-31b-it")
    if GEMINI_API_KEY:
        for model in order:
            b = body
            if model.startswith("gemma") and wants_json:
                # Gemma rejects response_mime_type — JSON via prompt + slice
                b = dict(body)
                gc = dict(b.get("generationConfig") or {})
                gc.pop("response_mime_type", None)
                b["generationConfig"] = gc
            try:
                r = requests.post(
                    "https://generativelanguage.googleapis.com/v1beta/models/"
                    f"{model}:generateContent?key={GEMINI_API_KEY}",
                    json=b, timeout=timeout)
                if r.status_code == 200:
                    parts = ((r.json().get("candidates") or [{}])[0]
                             .get("content", {}).get("parts", []))
                    txt = _strip_think("\n".join(
                        p.get("text", "") for p in parts if p.get("text")))
                    if txt:
                        _VISION_TIER_USED[model] = \
                            _VISION_TIER_USED.get(model, 0) + 1
                        if model != GEMINI_MODEL:
                            log.info("vision rotation: %s served by %s",
                                     tag, model)
                        return _json_slice(txt) if wants_json else txt
                elif r.status_code != 429:
                    log.info("vision %s: %s HTTP %d", tag, model,
                             r.status_code)
            except Exception as exc:  # noqa: BLE001
                log.info("vision %s: %s failed (%s)", tag, model, exc)
    if GROQ_API_KEY and n_imgs <= 5:      # hard Groq cap: 5 images/request
        try:
            payload = {"model": "qwen/qwen3.6-27b",
                       "messages": [{"role": "user",
                                     "content": _gemini_to_openai_content(body)}],
                       "temperature": 0.1, "max_tokens": 1024}
            if wants_json:
                payload["response_format"] = {"type": "json_object"}
            r = requests.post("https://api.groq.com/openai/v1/chat/completions",
                              headers={"Authorization":
                                       f"Bearer {GROQ_API_KEY}"},
                              json=payload, timeout=timeout)
            if r.status_code == 200:
                txt = _strip_think(((r.json().get("choices") or [{}])[0]
                                    .get("message", {}).get("content", "")))
                if txt:
                    _VISION_TIER_USED["groq"] = \
                        _VISION_TIER_USED.get("groq", 0) + 1
                    log.info("vision rotation: %s served by groq/qwen3.6",
                             tag)
                    return _json_slice(txt) if wants_json else txt
            else:
                log.info("vision %s: groq HTTP %d", tag, r.status_code)
        except Exception as exc:  # noqa: BLE001
            log.info("vision %s: groq failed (%s)", tag, exc)
    if CF_ACCOUNT_ID and CF_AI_TOKEN and n_imgs == 1:   # CF: 1 image/call
        try:
            r = requests.post(
                "https://api.cloudflare.com/client/v4/accounts/"
                f"{CF_ACCOUNT_ID}/ai/run/"
                "@cf/meta/llama-3.2-11b-vision-instruct",
                headers={"Authorization": f"Bearer {CF_AI_TOKEN}"},
                json={"messages": [{"role": "user",
                                    "content": _gemini_to_openai_content(body)}],
                      "max_tokens": 512}, timeout=timeout)
            j = r.json() if r.status_code == 200 else {}
            txt = _strip_think((j.get("result") or {}).get("response", ""))
            if txt:
                _VISION_TIER_USED["cloudflare"] = \
                    _VISION_TIER_USED.get("cloudflare", 0) + 1
                log.info("vision rotation: %s served by cloudflare/llama-3.2",
                         tag)
                return _json_slice(txt) if wants_json else txt
            log.info("vision %s: cloudflare HTTP %d", tag, r.status_code)
        except Exception as exc:  # noqa: BLE001
            log.info("vision %s: cloudflare failed (%s)", tag, exc)
    log.warning("vision %s: ALL rotation tiers exhausted", tag)
    return None


def still_is_relevant(path, topic, strict=False):
    """r33: does this photo have anything to do with THIS story? A generic
    stock plate (the Twitch-logo keyboard that carried real scenes of a story
    about a person) is worse than one fewer scene — the viewer reads it as
    filler and loses the thread. Mirrors footage_is_relevant: no key, over the
    call cap, or any error -> True, so infra trouble never empties the pool.
    r38 strict=True flips that default: for UNVERIFIED candidates (Openverse
    keyword matches) an unanswerable identity question must keep the image OUT
    — wrong-person imagery is worse than a thin pool."""
    if not (GEMINI_API_KEY and path and topic):
        return not strict
    if path in _STILL_REL_CACHE:
        return _STILL_REL_CACHE[path]
    if _STILL_REL_CALLS[0] >= STILL_REL_MAX_CALLS:
        return not strict
    ok = not strict
    try:
        import io
        im = Image.open(path).convert("RGB")
        im.thumbnail((448, 448))
        buf = io.BytesIO()
        im.save(buf, "JPEG", quality=80)
        im.close()
        _STILL_REL_CALLS[0] += 1
        prompt = (
            "A short news video tells this story: \"%s\". Does this picture "
            "show something from that story — a person, place, event, product "
            "or document it is actually about? Answer relevant=false for "
            "GENERIC STOCK that merely matches a keyword (a stock photo of a "
            "keyboard, a phone, a logo, an empty studio, an abstract graphic) "
            "with no connection to the specific people or events. "
            'Respond ONLY JSON: {"relevant": true|false}.' % str(topic)[:220])
        body = {"contents": [{"parts": [
                    {"text": prompt},
                    {"inline_data": {"mime_type": "image/jpeg",
                        "data": base64.b64encode(buf.getvalue()).decode("ascii")}}]}],
                "generationConfig": {"temperature": 0.0,
                    "response_mime_type": "application/json"}}
        txt = vision_post(body, timeout=40, tag="still-relevance",
                          strong_first=True)
        if txt:
            if txt.startswith("```"):
                txt = txt.strip("`").strip()
                if txt.lower().startswith("json"):
                    txt = txt[4:].strip()
            ok = bool(json.loads(txt).get("relevant", not strict))
    except Exception:  # noqa: BLE001
        ok = not strict
    _STILL_REL_CACHE[path] = ok
    return ok


_PERSON_OK_CACHE = {}


def shows_person(path, name):
    """r75: does this photo show THIS PERSON? Identity only — nothing about
    the story.

    The Openverse top-up gate used to call still_is_relevant() with the story
    TITLE, so the model was asked whether a photo belonged to "Tom Brady and
    Logan Paul's Fanatics Fest Confrontation". A real portrait of Logan Paul
    from any other day is honestly NOT from that event, so it was rejected —
    correctly answering the wrong question. Nine of twelve genuine Logan Paul
    photos were binned that way and the pool starved at 7 for 12 scenes, which
    is what the judge then failed for repetition.

    Identity is the only thing this gate needs to protect: the r38 bug it was
    built for was two UNIDENTIFIED men in suits carried for 10 seconds, and
    wrong-person imagery on a serious story is the defamation risk the image
    engine exists to prevent. Asking "is this that person?" still stops all of
    that, while letting ordinary photos of the subject through.

    Fails CLOSED like its predecessor: no key, no answer, over the cap or any
    error keeps the picture OUT."""
    if not (GEMINI_API_KEY and path and name):
        return False
    ck = (path, name.lower())
    if ck in _PERSON_OK_CACHE:
        return _PERSON_OK_CACHE[ck]
    if _STILL_REL_CALLS[0] >= STILL_REL_MAX_CALLS:
        return False
    ok = False
    try:
        import io
        im = Image.open(path).convert("RGB")
        im.thumbnail((448, 448))
        buf = io.BytesIO()
        im.save(buf, "JPEG", quality=80)
        im.close()
        _STILL_REL_CALLS[0] += 1
        prompt = (
            'Does this photograph show %s? Answer shows=true only if %s is '
            'visible and recognisable in it. Answer shows=false for a '
            'different person, for someone you cannot identify, for a crowd '
            'in which they are not recognisable, and for a logo, product, '
            'graphic or empty scene with no person in it. It does NOT matter '
            'what event the photo is from or how old it is. '
            'Respond ONLY JSON: {"shows": true|false}.' % (name, name))
        body = {"contents": [{"parts": [
                    {"text": prompt},
                    {"inline_data": {"mime_type": "image/jpeg",
                        "data": base64.b64encode(buf.getvalue()).decode("ascii")}}]}],
                "generationConfig": {"temperature": 0.0,
                    "response_mime_type": "application/json"}}
        txt = vision_post(body, timeout=40, tag="person-identity",
                          strong_first=True)
        if txt:
            if txt.startswith("```"):
                txt = txt.strip("`").strip()
                if txt.lower().startswith("json"):
                    txt = txt[4:].strip()
            ok = bool(json.loads(txt).get("shows", False))
    except Exception:  # noqa: BLE001
        ok = False
    _PERSON_OK_CACHE[ck] = ok
    return ok


def openverse_photos(query, want=6):
    """r33: more REAL photos of the subject, key-free, from Openverse (the
    WordPress/CC aggregator over Flickr/Wikimedia/etc). A one-photo pool is
    what forced the planner to hold a single frame for 20 seconds.

    LICENCE GATE: we crop, zoom and pan every photo — that is a DERIVATIVE, so
    licences that forbid modification (BY-ND, NC-ND) are unusable however
    convenient. license_type=modification asks Openverse for only the licences
    that permit it."""
    if not query:
        return []
    try:
        r = requests.get("https://api.openverse.org/v1/images/",
                         # r57 HARD CAP AT 20. Openverse allows page_size>20
                         # only for AUTHENTICATED clients; anonymous requests
                         # above it are rejected with 401, not truncated.
                         # Measured against the live API:
                         #   page_size=20 -> HTTP 200 | page_size=21 -> HTTP 401
                         # r56 raised VISUAL_POOL_MIN 4 -> 20 to make this
                         # search fire, which made want=22 and page_size=44 —
                         # so the change meant to switch Openverse ON is what
                         # switched it off. Every render since has logged
                         # "openverse HTTP 401" and added zero photos.
                         params={"q": query,
                                 "page_size": max(4, min(20, want * 2)),
                                 "license_type": "modification",
                                 "mature": "false"},
                         headers={"User-Agent": _BROWSER_UA}, timeout=25)
        if r.status_code != 200:
            log.info("openverse HTTP %d for '%s'", r.status_code, query[:50])
            return []
        res = (r.json() or {}).get("results") or []
    except Exception as exc:  # noqa: BLE001
        log.info("openverse failed (%s)", str(exc)[:80])
        return []
    urls = []
    for it in res:
        u = it.get("url")
        if isinstance(u, str) and u.startswith("http"):
            urls.append(u)
        if len(urls) >= want:
            break
    if urls:
        log.info("openverse: %d modifiable-licence photos for '%s'",
                 len(urls), query[:50])
    return urls


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
            # r40: rank-able quality + does the image contain a human face at
            # all (an object-stock plate — keyboard, phone, logo — does not,
            # and gets capped to ONE scene by the variety pass).
            entry["quality"] = image_quality(p)
            # r47 RESOLUTION FLOOR (owner: "the resolution is so fucked"). Research
            # line: BELOW 720x1280 a still is visibly soft on a modern phone, and
            # upscaling never restores detail — only downscaling preserves it. We
            # were feeding 480x360 thumbnails into a 1080-wide frame and then
            # ZOOMING them, which is what turned faces into mush. So a still whose
            # short side cannot reach MIN_STILL_SHORT_SIDE is rejected outright
            # rather than upscaled. Text cards are exempt (they are rendered, not
            # photographed) and the gate never empties the pool: once fewer than 2
            # entries are in, a small image is still better than no image.
            try:
                with Image.open(p) as _pim:
                    entry["px_w"], entry["px_h"] = _pim.size
            except Exception:  # noqa: BLE001
                entry["px_w"] = entry["px_h"] = 0
            # r52 CONTAIN INSTEAD OF UPSCALE (the real fix behind the floor):
            # filling a 1080x1920 phone frame with a landscape press photo needs
            # a ~2.8x upscale, which is what turned faces to mush and forced an
            # aggressive floor that then starved the pool to 6 images. The engine
            # ALREADY has the professional answer — contain_scene_clip draws the
            # whole image over a blurred, darkened fill of ITSELF (no crop, no
            # upscale, no black bars). It was reserved for text cards. Any photo
            # that would need more than COVER_MAX_UPSCALE to cover now renders
            # that way instead: sharp picture, full frame, nothing sliced.
            if entry["px_w"] and entry["px_h"] and not textish:
                _need = max(W / float(entry["px_w"]), H / float(entry["px_h"]))
                if _need > COVER_MAX_UPSCALE:
                    # r57: DEDICATED flag. Setting textish here (r52) made every
                    # contain photo count as a CARD, and cards are never split into
                    # beats — so pacing collapsed from ~30 beats to 8 (7s per image,
                    # straight back to a slideshow). Contain is a RENDERING choice,
                    # not a content type: still a photo, still paced.
                    entry["contain"] = True
                    log.info("CONTAIN MODE: %dx%d needs %.1fx upscale to cover; "
                             "rendering whole on a blurred fill instead",
                             entry["px_w"], entry["px_h"], _need)
            # r97: text has its own floor, and it is stricter. Something we put
            # on screen to be read must be legible; a screenshot too small to
            # read is worse than no screenshot, because it occupies a beat AND
            # tells the viewer nothing.
            if (textish and entry["px_w"] and len(pool) >= 1
                    and entry["px_w"] < MIN_TEXT_WIDTH):
                log.info("TEXT FLOOR: %dx%d is too small to read at 1080 wide "
                         "(<%d); dropped rather than shown as a strip: %s",
                         entry["px_w"], entry["px_h"], MIN_TEXT_WIDTH, u[:80])
                continue
            _short = min(entry["px_w"], entry["px_h"])
            if (_short and not textish and len(pool) >= 2
                    and _short < MIN_STILL_SHORT_SIDE):
                log.info("RESOLUTION FLOOR: %dx%d (short side %d < %d) would be "
                         "upscaled into mush; dropped: %s",
                         entry["px_w"], entry["px_h"], _short,
                         MIN_STILL_SHORT_SIDE, u[:90])
                continue
            try:
                entry["has_face"] = detect_face_box(p) is not None
            except Exception:  # noqa: BLE001
                entry["has_face"] = True         # never punish on infra doubt
            # r33 (owner: "the twitch img idk what it does doing right there"):
            # a generic stock plate — a Twitch-logo keyboard — carried real
            # scenes of a story about a person. Ask whether the picture has
            # anything to do with THIS story; drop it if not. Person photos and
            # text cards are exempt (a portrait is the subject by definition,
            # a card is read, not depicted).
            # r40 (owner: the Twitch-keyboard plate came BACK): site-fed stock
            # now faces the same NAME-AWARE question as the Openverse extras —
            # a story that mentions Twitch does not make a stock keyboard
            # relevant. Strict (fail-closed) only once 2 entries are already
            # in, so an infra failure can never empty the whole pool.
            # r44 (judge: "masked person in hoodie is unrelated filler for a Tom
            # Brady story"): the person-exemption above assumed a person URL is a
            # PORTRAIT. A YouTube VIDEO THUMBNAIL is not — it is arbitrary cover
            # art that merely came from that person's channel, so it can show a
            # masked figure, a car or pure text. Such a thumbnail only earns the
            # portrait exemption when a face is actually visible in it; otherwise
            # it must justify itself like any other stock plate. Deterministic and
            # free (YuNet), which matters while the Gemini quota is exhausted.
            _is_thumb = "ytimg.com/vi" in u
            if _is_thumb and entry["person"] and not entry.get("has_face"):
                log.info("PORTRAIT GATE: person thumbnail has no visible face; "
                         "not a portrait: %s", u[:100])
                continue
            if not ((entry["person"] and not _is_thumb) or textish):
                _names = ", ".join(sorted(person_map)) or \
                    ", ".join(url2name.values())
                _t = str(post.get("title", ""))
                _topic = (_t + (" — the story is about %s; a generic stock "
                                "photo of an object, device, keyboard, phone "
                                "or logo is NOT relevant even if its brand is "
                                "mentioned in the story" % _names
                                if _names else ""))
                if not still_is_relevant(p, _topic, strict=len(pool) >= 2):
                    log.info("STILL GATE: off-topic stock dropped: %s", u[:100])
                    continue
            # r36 CONTENT DEDUP: same pixels under a second URL do not enter
            # the pool twice (distance <= 6 of 64 bits = same image, resized
            # or recompressed).
            entry["dhash"] = image_dhash(p)
            dup = next((e for e in pool
                        if dhash_distance(entry["dhash"], e.get("dhash")) <= 6),
                       None)
            if dup is not None:
                log.info("POOL DEDUP: %s is the same image as %s; skipped",
                         u[:80], (dup.get("url") or "")[:60])
                if entry["person"] and not dup.get("person"):
                    dup["person"] = entry["person"]
                    person_map.setdefault(entry["person"].lower(),
                                          []).append(dup)
                continue
            pool.append(entry)
            if entry["person"]:
                # r11: LIST per person — avatar + recent thumbnails, in feed
                # order, so consecutive shots of one person can cycle them.
                person_map.setdefault(entry["person"].lower(), []).append(entry)
    # r36 THIN-POOL RESCUE, now measured on the DISTINCT pool after dedup —
    # run #155 skipped the r33 pre-check because 5 raw URLs looked healthy,
    # but only ~3 were distinct images. Top up from Openverse (real,
    # modifiable-licence photos of the subject) through the SAME gates
    # (textish, dedup) as every other candidate.
    if len(pool) < VISUAL_POOL_MIN:
        # r87 NEVER ASK A PHOTO LIBRARY FOR A PERSON. Openverse indexes
        # free-licence photography; it holds no pictures of current internet
        # figures, so a search for one can only return a DIFFERENT human with
        # a similar name. Page 192 asked it for "Justine Moore" and opened the
        # video on Justin Moore, the country singer, in a white cowboy hat —
        # the identity gate below was the last line of defence and it did not
        # hold, because a vision model cannot recognise a face it has never
        # been shown. Removing the question is the fix; the gate stays as
        # defence in depth. Objects and places only, from the title.
        q = ""
        people_names = {w for e in (post.get("people") or [])
                        for w in str(e.get("name") if isinstance(e, dict)
                                     else e or "").lower().split()}
        words = [w for w in re.findall(r"[A-Za-z0-9']{3,}",
                                       str(post.get("title") or ""))
                 if w.lower() not in SEARCH_STOP
                 and w.lower() not in people_names][:4]
        if len(words) < 2:
            log.info("openverse top-up skipped: nothing left to search for "
                     "once the people are removed, and a name returns strangers")
        else:
            q = " ".join(words)
        for j, u in enumerate(openverse_photos(q, want=VISUAL_POOL_MIN + 2)
                              if q else []):
            if len(pool) >= VISUAL_POOL_MIN + 1:
                break
            if u in seen:
                continue
            p = fetch_visual(u, os.path.join(WORKDIR, f"vis-{page_id}-ov{j}"))
            if not p:
                continue
            entry = {"path": p, "textish": _textish(p, u), "url": u,
                     "person": None, "designed": False,
                     "dhash": image_dhash(p)}
            if any(dhash_distance(entry["dhash"], e.get("dhash")) <= 6
                   for e in pool):
                continue
            # r38 IDENTITY GATE (the r37 filmstrip carried two unidentified men
            # in suits for 10 seconds — a keyword match is NOT the person, and
            # wrong-person imagery on a death story is the defamation bug the
            # image engine exists to prevent). An Openverse candidate must
            # positively show the story's subject; on any doubt it stays out —
            # for UNVERIFIED extras, fail CLOSED, unlike the feed's own
            # site-verified visuals.
            # r87: the query is now always a SUBJECT, never a person, so the
            # right question is relevance, not identity. And the old "textish"
            # bypass is gone: a candidate that merely contains text used to
            # enter the pool with no relevance check at all, which is how an
            # unverified stranger could reach the screen. An unverified extra
            # must earn its place or stay out.
            if not still_is_relevant(p, str(post.get("title", "")), strict=True):
                log.info("openverse candidate rejected (not this story): %s",
                         u[:90])
                continue
            entry["quality"] = image_quality(p)
            try:
                entry["has_face"] = detect_face_box(p) is not None
            except Exception:  # noqa: BLE001
                entry["has_face"] = True
            pool.append(entry)
            log.info("openverse top-up joined the pool: %s", u[:90])

    log.info("visual pool: %d distinct of %d candidates (%d from people, "
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


def enforce_visual_variety(scenes, alt_paths, max_share=None,
                           single_cap_paths=None):
    """r33 (owner: "litteraly the same imgs keeps repeating"). still_hold_ok
    only stops a THIRD CONSECUTIVE hold — it says nothing about the same photo
    carrying 15 of 18 scenes non-consecutively, which is exactly what shipped:
    one El Risitas frame under ~60% of the runtime. The judge has a 3+ repeats
    rule and passed it anyway, so prevention cannot live in the judge.

    Rewrites the worst offenders to the least-used alternative available.
    Pure list-in/list-out over {"path": ...} dicts so it is unit-testable
    offline (tools/variety_test.js has the same cases in JS for the crop; this
    one is exercised by tools/variety_test.py). Returns the number of swaps."""
    max_share = max_share or VISUAL_MAX_SHARE
    idxs = [i for i, s in enumerate(scenes)
            if s.get("path") and s.get("type") != "broll"]
    if len(idxs) < 4:
        return 0
    cap = max(2, int(len(idxs) * max_share))
    swaps = 0
    counts = {}
    for i in idxs:
        counts[scenes[i]["path"]] = counts.get(scenes[i]["path"], 0) + 1
    # r40: object-stock plates (no face, not a text card — the Twitch keyboard)
    # get ONE scene ever; they are seasoning, not a co-star. Their per-path cap
    # is 1 regardless of the share cap.
    singles = set(single_cap_paths or ())

    def _cap_of(path):
        return 1 if path in singles else cap

    for i in idxs:
        p = scenes[i]["path"]
        if counts.get(p, 0) <= _cap_of(p):
            continue
        # the least-used alternative that is not this path and not adjacent
        neighbours = {scenes[j]["path"] for j in (i - 1, i + 1)
                      if 0 <= j < len(scenes) and scenes[j].get("path")}
        cands = [a for a in alt_paths if a != p and a not in neighbours]
        if not cands:
            continue
        alt = min(cands, key=lambda a: counts.get(a, 0))
        if counts.get(alt, 0) + 1 > _cap_of(alt):
            continue                      # swapping would just move the problem
        counts[p] -= 1
        counts[alt] = counts.get(alt, 0) + 1
        scenes[i]["path"] = alt
        scenes[i]["variety_swap"] = True
        swaps += 1
    if swaps:
        log.info("VARIETY: %d scene(s) re-pointed (cap %d of %d still scenes)",
                 swaps, cap, len(idxs))
    return swaps


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
    # r31: THIS path is YouTube, the one source the platform-check proved is
    # walled from CI (403 even with WARP + cookies) while Kick/Twitch/TikTok
    # all downloaded fine. Spawning yt-dlp here only burns the render clock —
    # which is what timed out four renders and got footage switched off
    # wholesale. Skip it by default; Kick/Twitch/TikTok still fetch.
    if os.environ.get("VIDEO_YT_FETCH", "0") == "0":
        log.info("FOOTAGE: youtube fetch disabled (CI is bot-walled); "
                 "thumbnail still + platform clips carry this scene")
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
# r45 MONEY MOMENT: url -> the seconds offset the REPORTER embedded the clip at
# (youtube.com/embed/<id>?start=182 on the Brady/Logan Paul article = the exact
# second of the slap). That offset is the most valuable number in the story: it
# is where the event happens, so it is what the HOOK must show.
_STORY_CLIP_START = {}
# r87 PROVENANCE. Clip URLs a reporter EMBEDDED in one of this story's own
# source articles. Their topicality is established by publication, not by
# guessing: a journalist writing about this story chose to put this video in
# the piece. That outranks a vision model's opinion of one sampled frame —
# which on page 192 threw away BOTH of the story's TikToks as "off-topic" and
# left a clips-first video with zero footage.
_STORY_CLIP_SRC = set()
_HOOK_CLIP = [None]        # (path, src_off) of the clip chosen to open the video
_CLIP_ARTIFACT_FRAMES = []  # jpgs of each clip beat's REAL video, shipped
                            # with delivery for the carousel (joined by
                            # event_id — "the video beat must show THAT
                            # video, not an img")
_TIMELINE_MODE = [False]   # TIMELINE CONTRACT (2026-08-06): shotlist meta
                           # timeline=1 -> beats are deterministic artifact
                           # orders; ALL legacy clip opportunism (hook-law
                           # money grab, clip-frame harvest into the pool,
                           # still->clip upgrades) is OFF — run #237's judge
                           # caught exactly those paths breaking the
                           # 1-beat-1-artifact contract (a spare clip played
                           # over the "X comment" sentence; harvested clip
                           # frames with burned-in captions leaked into
                           # photo scenes as cut text).
_CLIP_FRAMES_DONE = [False]   # r57: the supply harvest runs once per story
_FOOTAGE_REL_CACHE = {}    # r28 smart gate: clip path -> is-it-on-topic
_FOOTAGE_REL_CALLS = [0]
FOOTAGE_REL_MAX_CALLS = 5  # cap Gemini relevance checks per render (speed)


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
            "topic is NOT related. Also answer related=false when the frame "
            "carries BURNED-IN meme captions, jokes or subtitles about some "
            "OTHER subject (a caption like 'Actual Nazi' on a meme edit) — "
            "that text ships inside our video and hijacks the story. "
            'Respond ONLY JSON: {"related": true|false}.')
        body = {"contents": [{"parts": [
                    {"text": prompt},
                    {"inline_data": {"mime_type": "image/jpeg",
                        "data": base64.b64encode(buf.getvalue()).decode("ascii")}}]}],
                "generationConfig": {"temperature": 0.0,
                    "response_mime_type": "application/json"}}
        txt = vision_post(body, timeout=40, tag="footage-relevance")
        if txt:
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


_SHOT_CLEAN_CACHE = {}
_SHOT_CLEAN_CALLS = [0]
SHOT_CLEAN_MAX_CALLS = int(os.environ.get("VIDEO_SHOT_CLEAN_MAX", "6"))


def screenshot_is_clean(png_path):
    """r29 AD BACKSTOP (owner: 'fix the screenshots — no ads in a frame').
    Selector-based ad-hiding can't cover every layout, so AFTER a shot is taken
    ask Gemini: is this a CLEAN article proof (masthead/headline/photo/body
    text), or is a meaningful part of the frame an ad / merch / shop / 'buy our
    t-shirt' box, a cookie/newsletter/subscribe banner, or unrelated page
    furniture? Unclean -> reject the shot so the og:image / subject-photo chain
    supplies a guaranteed-clean fallback. No key / over cap / any error -> True
    (never blocks a shot on infra problems). Cached per path; capped per render."""
    if not GEMINI_API_KEY or not png_path:
        return True
    if png_path in _SHOT_CLEAN_CACHE:
        return _SHOT_CLEAN_CACHE[png_path]
    if _SHOT_CLEAN_CALLS[0] >= SHOT_CLEAN_MAX_CALLS:
        return True
    clean = True
    try:
        import io
        im = Image.open(png_path).convert("RGB")
        im.thumbnail((512, 640))
        buf = io.BytesIO()
        im.save(buf, "JPEG", quality=82)
        im.close()
        _SHOT_CLEAN_CALLS[0] += 1
        prompt = (
            "This image is a screenshot of a news/article page shown as on-screen "
            "PROOF in a short video. Is it CLEAN — showing the article's headline "
            "and/or main photo and/or body text and nothing intrusive? Answer "
            "clean=false if a MEANINGFUL part of the frame is taken up by any of: "
            "an advertisement, a merch / shop / store / 'buy our t-shirt' or "
            "product box, a cookie / newsletter / subscribe banner, or unrelated "
            "page furniture (nav menus, related-story grids, comment widgets). "
            "ALSO clean=false when the frame is dominated by promotional or "
            "marketing content of ANY kind — a course ad ('free course', "
            "'sign up'), a product promo slide, a giveaway graphic, an "
            "email-capture form — or by a cookie-consent / legal-terms dialog "
            "or its dimmed gray backdrop. ALSO clean=false if you cannot see "
            "an actual news headline or article body text anywhere in the "
            "frame (a proof shot must PROVE something). "
            "A small logo or a thin byline is fine. "
            'Respond ONLY JSON: {"clean": true|false}.')
        body = {"contents": [{"parts": [
                    {"text": prompt},
                    {"inline_data": {"mime_type": "image/jpeg",
                        "data": base64.b64encode(buf.getvalue()).decode("ascii")}}]}],
                "generationConfig": {"temperature": 0.0,
                    "response_mime_type": "application/json"}}
        txt = vision_post(body, timeout=40, tag="screenshot-clean",
                          strong_first=True)
        if txt:
            if txt.startswith("```"):
                txt = txt.strip("`").strip()
                if txt.lower().startswith("json"):
                    txt = txt[4:].strip()
            clean = bool(json.loads(txt).get("clean", True))
            if not clean:
                log.info("SHOT AD-GATE: ad/merch-cluttered screenshot rejected "
                         "(%s)", os.path.basename(png_path))
    except Exception:  # noqa: BLE001
        clean = True
    _SHOT_CLEAN_CACHE[png_path] = clean
    return clean


ARCHIVE_ENABLED = os.environ.get("VIDEO_ARCHIVE_FETCH", "1") != "0"
ARCHIVE_MAX_ITEMS = int(os.environ.get("VIDEO_ARCHIVE_MAX", "5"))
ARCHIVE_CLIP_S = float(os.environ.get("VIDEO_ARCHIVE_CLIP_S", "6"))


def _archive_len_s(val):
    """archive.org 'length' is either seconds ('195.32') or 'H:MM:SS'."""
    try:
        s = str(val or "").strip()
        if ":" in s:
            parts = [float(p) for p in s.split(":")]
            out = 0.0
            for p in parts:
                out = out * 60.0 + p
            return out
        return float(s)
    except Exception:  # noqa: BLE001
        return 0.0


SEARCH_STOP = {
    "the", "and", "with", "from", "that", "this", "what", "when", "after",
    "over", "into", "your", "just", "they", "them", "than", "then", "have",
    "here", "news", "video", "clip", "full", "says", "new", "his", "her",
    "its", "world", "game", "games", "gaming", "life", "live", "show",
    "shows", "story", "stories", "part", "series", "movie", "film", "people",
    "player", "players", "best", "first", "last", "time", "times", "year",
    "years", "week", "today", "night", "real", "official", "channel", "watch",
    "online", "free", "home", "modern", "look", "thing", "things", "universe",
    "america", "american", "update", "updates", "response", "incident",
    "rise", "fallout", "drama", "viral", "trend", "trending", "internet",
    "reaction", "explained", "tiktok", "twitter", "youtube", "instagram",
}


def distinctive_words(*texts):
    """The rare words that make a title THIS story's and not a coincidence.

    r87: lifted out of archive_org_clips, where it has been earning its keep
    since the "World of Warcraft" -> "Modern World Of Doctor Who" incident,
    so the YouTube clip hunt can use the same test. Common long words are
    stopwords too — a match has to rest on something genuinely rare.
    """
    out = set()
    for t in texts:
        for w in re.findall(r"[A-Za-z0-9']{3,}", str(t or "")):
            # "TikTok's" is the stopword tiktok wearing a possessive; strip it
            # or the apostrophe smuggles a banned word back in as distinctive.
            lw = re.sub(r"'s$|'$", "", w.lower())
            if len(lw) >= 4 and lw not in SEARCH_STOP:
                out.add(lw)
    return out


def title_is_topical(title, distinct):
    """Whole-word test — "brooke" must not match inside "#brookemonk", which
    is a different creator entirely (r87, caught on the measured results)."""
    tl = str(title or "").lower()
    return any(re.search(r"(?<![a-z0-9])" + re.escape(w) + r"(?![a-z0-9])", tl)
               for w in distinct)


def archive_org_clips(terms, want=3):
    """r33 (owner: a video about the most iconic laugh on the internet that
    contained neither the laugh nor the show). REAL footage from archive.org —
    key-free, no bot-wall, no cookies, no WARP, and it holds exactly the old
    TV/meme material our stories are about. Proven for this very story: a
    search for El Risitas returns 5 movie items, one a 195s clip.
    Returns playable https URLs (the story-clip pool takes it from there)."""
    if not (ARCHIVE_ENABLED and terms):
        return []
    # r36 (run #155's log: NOT ONE archive line — the search matched nothing
    # and said nothing). The title arrived as the exact phrase "El Risitas
    # Passing", which no archive item's title contains, while a search for the
    # subject alone finds 3 items. Build VARIANTS per term (full, first two
    # words) and try title: first, then full-text, stopping at the first hit —
    # and LOG a miss, per the no-silent-caps rule.
    variants, seenv = [], set()
    for t in terms[:4]:
        words = str(t).replace('"', "").split()
        for v in (" ".join(words), " ".join(words[:2])):
            if len(v) >= 4 and v.lower() not in seenv:
                seenv.add(v.lower())
                variants.append(v)
    docs = []
    for field in ("title", None):
        q = " OR ".join(('%s:("%s")' % (field, v)) if field else ('("%s")' % v)
                        for v in variants)
        try:
            r = requests.get(
                "https://archive.org/advancedsearch.php",
                params={"q": "(%s) AND mediatype:(movies)" % q,
                        "fl[]": ["identifier", "title"],
                        "rows": ARCHIVE_MAX_ITEMS, "output": "json"},
                headers={"User-Agent": _BROWSER_UA}, timeout=25)
            docs = (r.json().get("response") or {}).get("docs") or []
        except Exception as exc:  # noqa: BLE001
            log.info("archive.org search failed (%s)", str(exc)[:80])
            docs = []
        if docs:
            break
    if not docs:
        log.info("archive.org: 0 items for %s", variants[:4])
        return []
    # r38: TITLE-MATCHED items first. Run #157 opened the video on a meme
    # compilation whose burned-in caption read "Actual Nazi" — an item matched
    # only by full-text. An item whose TITLE names the subject is the subject's
    # own footage; everything else is remix material and goes to the back.
    vlow = [v.lower() for v in variants]
    docs.sort(key=lambda d: 0 if any(v in str(d.get("title") or "").lower()
                                     for v in vlow) else 1)
    # r57 ACRONYM GUARD. archive.org TOKENISES title:("PSA Class"), so a short
    # acronym in a story title matches any item containing it: the PSA
    # trading-card lawsuit pulled back "Youth Spaces PSA" and "Media
    # Representation of Women PSA" — 1990s public service announcements. Those
    # frames would have been presented as the event itself.
    # Require a DISTINCTIVE story word (>=4 chars, not a stopword) to appear in
    # the item's TITLE. A 3-letter acronym can then never carry a match alone.
    # This keeps the proven El Risitas behaviour ("risitas" is distinctive) and
    # is deterministic — no vision quota needed, which matters because the
    # Gemini gates are rate-limited exactly when renders pile up (HTTP 429 on
    # run #198, so no vision check could have caught this).
    # r76: the >=4-char rule alone is not distinctiveness. "World of Warcraft"
    # let the word WORLD carry a match, so a search for the WoW cheating
    # scandal came back with "A Look At The Modern World Of Doctor Who",
    # "wonderful world of inventions" and "WORLD OF HONOR" — three coincidence
    # matches shipped as this story's footage. Common long words are stopwords
    # too; a title must share something genuinely rare (warcraft, risitas)
    # before we treat an archive item as this story's own material.
    _STOP = {"the", "and", "with", "from", "that", "this", "what", "when",
             "after", "over", "into", "your", "just", "they", "them", "than",
             "then", "have", "here", "news", "video", "clip", "full", "says",
             "new", "his", "her", "its",
             "world", "game", "games", "gaming", "life", "live", "show",
             "shows", "story", "stories", "part", "series", "movie", "film",
             "people", "player", "players", "best", "first", "last", "time",
             "times", "year", "years", "week", "today", "night", "real",
             "official", "channel", "watch", "online", "free", "home",
             "modern", "look", "thing", "things", "universe", "america",
             "american", "update", "updates", "response", "incident"}
    distinctive = {w for v in variants for w in v.lower().split()
                   if len(w) >= 4 and w not in _STOP}
    if not distinctive:
        log.info("archive.org: no distinctive term in %s (acronym-only "
                 "story); skipped rather than risk a coincidence match",
                 variants[:3])
        return []
    kept = [d for d in docs
            if any(w in str(d.get("title") or "").lower()
                   for w in distinctive)]
    if len(kept) < len(docs):
        log.info("archive.org: dropped %d coincidence match(es) with no "
                 "distinctive story word in the title (e.g. %s); kept %d",
                 len(docs) - len(kept),
                 str((docs[len(kept)] if len(kept) < len(docs) else {})
                     .get("title") or "")[:40], len(kept))
    docs = kept
    if not docs:
        log.info("archive.org: 0 topical items for %s", list(distinctive)[:4])
        return []
    out = []
    for d in docs:
        if len(out) >= want:
            break
        ident = d.get("identifier")
        if not ident:
            continue
        try:
            m = requests.get("https://archive.org/metadata/%s" % ident,
                             headers={"User-Agent": _BROWSER_UA},
                             timeout=20).json()
        except Exception:  # noqa: BLE001
            continue
        best = None
        for f in (m.get("files") or []):
            name = str(f.get("name") or "")
            if not name.lower().endswith((".mp4", ".m4v")):
                continue
            size = int(f.get("size") or 0)
            dur = _archive_len_s(f.get("length"))
            if dur < 12.0 or dur > 2400.0 or size > 320 * 1024 * 1024:
                continue
            if best is None or size < best[1]:      # smallest derivative wins
                best = (name, size, dur)
        if best:
            url = "https://archive.org/download/%s/%s" % (
                ident, urllib.parse.quote(best[0]))
            out.append(url)
            log.info("archive.org clip: %s (%s, %.0fs, %.1fMB)",
                     str(d.get("title"))[:60], ident, best[2],
                     best[1] / 1e6)
    return out


def clip_is_caption_free(mp4_path):
    """r39: archive.org items for a meme personality are mostly MEME EDITS with
    burned-in joke captions — two renders shipped 'Actual Nazi' and a German
    government joke over a death story. Two prompt nudges on the single-frame
    gate failed (the caption isn't on every frame), so this is now explicit and
    multi-frame: pull 3 frames across the cut, one Gemini call, and FAIL CLOSED
    — no key / error / over cap means the archive clip is NOT used. A thin
    video is recoverable; 'Actual Nazi' over a memorial is not."""
    if not GEMINI_API_KEY:
        return False
    if _STILL_REL_CALLS[0] >= STILL_REL_MAX_CALLS + 4:   # own small headroom
        return False
    try:
        import io
        parts = [{"text": (
            "These are 3 frames sampled across one short video clip. Does ANY "
            "frame carry burned-in caption/subtitle/joke text composited onto "
            "the video (any language)? Channel watermarks or tiny corner logos "
            "do not count; readable caption text does. "
            'Respond ONLY JSON: {"captions": true|false}.')}]
        for pos in ("10", "50", "90"):
            fp = mp4_path + f".cap{pos}.jpg"
            subprocess.run(["ffmpeg", "-y", "-nostdin", "-i", mp4_path,
                            "-vf", f"select=gte(t\\,{int(pos)/100*5}),scale=448:-2",
                            "-frames:v", "1", fp],
                           timeout=30, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=False)
            if not (os.path.isfile(fp) and os.path.getsize(fp) > 2000):
                continue
            with open(fp, "rb") as f:
                parts.append({"inline_data": {
                    "mime_type": "image/jpeg",
                    "data": base64.b64encode(f.read()).decode("ascii")}})
        if len(parts) < 3:                      # need >=2 real frames to judge
            return False
        _STILL_REL_CALLS[0] += 1
        body = {"contents": [{"parts": parts}],
                "generationConfig": {"temperature": 0.0,
                                     "response_mime_type": "application/json"}}
        txt = vision_post(body, timeout=45, tag="caption-free")
        if not txt:
            return False
        if txt.startswith("```"):
            txt = txt.strip("`").strip()
            if txt.lower().startswith("json"):
                txt = txt[4:].strip()
        clean = not bool(json.loads(txt).get("captions", True))
        if not clean:
            log.info("ARCHIVE CAPTION GATE: burned-in text found; clip dropped "
                     "(%s)", os.path.basename(mp4_path))
        return clean
    except Exception as exc:  # noqa: BLE001 — fail closed
        log.info("caption gate errored (%s); archive clip NOT used",
                 str(exc)[:70])
        return False


def fetch_archive_clip(url, seconds=None):
    """Pull ONE short section straight out of an archive.org mp4 with ffmpeg's
    HTTP range support — no yt-dlp, no impersonation, no full download of a
    45MB file. Re-encodes (a cut at an arbitrary offset is not on a keyframe).
    r39: the cut must pass clip_is_caption_free before it is served."""
    seconds = seconds or ARCHIVE_CLIP_S
    stem = "arch-" + hashlib.md5(url.encode()).hexdigest()[:12]
    out = os.path.join(WORKDIR, stem + ".mp4")
    if os.path.isfile(out) and os.path.getsize(out) > 40000:
        return out
    # skip titles/intros; the meme moment is rarely at second zero
    for ss in (25.0, 8.0, 0.0):
        cmd = ["ffmpeg", "-y", "-nostdin", "-ss", "%.1f" % ss, "-i", url,
               "-t", "%.1f" % seconds, "-an", "-c:v", "libx264",
               "-preset", "ultrafast", "-pix_fmt", "yuv420p",
               "-vf", "scale=-2:1280", out]
        try:
            subprocess.run(cmd, timeout=110, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=False)
        except Exception as exc:  # noqa: BLE001
            log.info("archive clip ffmpeg failed (%s)", type(exc).__name__)
            continue
        if os.path.isfile(out) and os.path.getsize(out) > 40000:
            if not clip_is_caption_free(out):
                try:
                    os.remove(out)          # never serve it from the cache
                except OSError:
                    pass
                # r58 NO SILENT REFUSAL. This gate fails CLOSED by design, and
                # correctly so. But it returned None without a word, so when the
                # Gemini quota is spent (HTTP 429 on runs #198/#199) EVERY
                # archive clip was dropped here and the log looked as if no clip
                # had ever been found — a working gate reading as a broken
                # feature. Say which it is. NOTE: the gate itself is unchanged.
                log.info("archive clip REFUSED by the caption gate (burned-in "
                         "text, or the check could not run — it fails closed "
                         "on purpose): %s", url[:70])
                return None
            log.info("archive.org footage: %.1fMB from %s",
                     os.path.getsize(out) / 1e6, url[:70])
            return out
    log.info("archive clip unusable at every offset (25s/8s/0s): %s", url[:70])
    return None


def platform_of(url):
    """Which platform a clip URL belongs to (or None)."""
    u = (url or "").lower()
    if "archive.org" in u:                      return "archive"
    if "kick.com" in u:                         return "kick"
    if "twitch.tv" in u:                        return "twitch"
    if "tiktok.com" in u:                       return "tiktok"
    if "youtube.com" in u or "youtu.be" in u:   return "youtube"
    if "x.com" in u or "twitter.com" in u:      return "x"
    # r97: Facebook downloads fine from a runner — measured on two real story
    # URLs (8.3MB and 21.6MB). It was simply never recognised here, so every
    # Facebook clip we held was invisible to the downloader.
    if "facebook.com" in u or "fb.watch" in u:  return "facebook"
    if "instagram.com" in u:                    return "instagram"
    # A plain media file (the jwplayer/CDN mp4s articles embed) IS the video —
    # nothing to extract. These were being bound to beats server-side and then
    # dropped here for want of a name.
    if re.search(r"\.(mp4|m4v|mov|webm)(\?|$)", u):  return "file"
    return None


def _feed_local_clip(url):
    """r104: a clip the SERVER already downloaded and staged into the feed
    branch (feed/clips/<sha1(url)>.mp4). TikTok refuses this runner from every
    angle, but a resolver hands the server a working link, so the file arrives
    the same way our images do — already here, nothing to fetch."""
    if not (FEED_DIR and url):
        return None
    p = os.path.join(FEED_DIR, "clips",
                     hashlib.sha1(url.encode("utf-8")).hexdigest() + ".mp4")
    return p if os.path.isfile(p) and os.path.getsize(p) > 100000 else None


def fetch_platform_clip(url):
    """r28: download a short clip from ANY supported platform with the RIGHT
    method (proven by platform-check). Returns a local video path or None.
    Cached per URL per run; counts toward the run fetch cap; never raises."""
    global _FOOTAGE_FETCHES
    _staged = _feed_local_clip(url)
    if _staged:
        log.info("CLIP staged by the server: %s (%s)",
                 os.path.basename(_staged), url[:60])
        _PLATFORM_CLIP_CACHE[url] = _staged
        return _staged
    if url in _PLATFORM_CLIP_CACHE:
        return _PLATFORM_CLIP_CACHE[url]
    plat = platform_of(url)
    if plat is None:
        _PLATFORM_CLIP_CACHE[url] = None
        return None
    if plat == "archive":       # r33: ffmpeg range-fetch, no yt-dlp, no cap
        path = fetch_archive_clip(url)
        _PLATFORM_CLIP_CACHE[url] = path
        return path
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
               # r99: "b" means a SINGLE file carrying both video and audio,
               # and YouTube mostly stopped serving those above 360p — the
               # streams are separate now. Asking only for a combined file is
               # how you get "Requested format is not available" on a video
               # that is sitting right there. Ask for video+audio and let
               # ffmpeg merge (it is installed on the runner), then fall back
               # to a combined file, then to anything at all.
               "-f", ("bv*[height<=720]+ba/b[height<=720]/bv*+ba/b"),
               "--merge-output-format", "mp4",
               "--max-filesize", "200M",
               "-o", outtmpl, url]
        # r45 MONEY MOMENT: when the reporter embedded this clip at a timestamp,
        # download the window AROUND that second rather than the video's opening
        # (a 40-minute panel stream opens on an empty stage; second 182 is the
        # slap). 2s of run-up + 8s after gives the hook something to cut into.
        _st = _STORY_CLIP_START.get(url, 0)
        if _st > 0:
            _a = max(0, _st - 2)
            cmd[1:1] = ["--download-sections", f"*{_a}-{_a + 10}",
                        "--force-keyframes-at-cuts"]
            log.info("CLIP window: %s at t=%ds (reporter's timestamp)",
                     plat, _st)
        elif plat in ("youtube", "facebook", "twitch", "kick"):
            # r100 THE 45MB TRAP. Without a timestamp we asked for the WHOLE
            # video and capped it at 45MB — and a full YouTube upload has no
            # format that small, so yt-dlp filtered every one of them out and
            # answered "Requested format is not available". It read like a
            # missing format; it was us rejecting all of them on size. Page
            # 358 lost all four clips to this AFTER the auth wall was beaten.
            # A beat is a few seconds long, so take the opening slice: small
            # whatever the video's length, and no cap can bite.
            cmd[1:1] = ["--download-sections", "*0-45",
                        "--force-keyframes-at-cuts"]
        if plat in ("kick", "twitch", "tiktok"):
            # TLS-fingerprint bypass — the whole trick for these three.
            cmd[1:1] = ["--impersonate", "chrome"]
        elif plat == "instagram":
            # r98: its own cookie file and its own account. Without them
            # Instagram returns an empty media response, so there is no point
            # spawning the download at all — say so once and move on.
            _ig = os.path.join(WORKDIR, "ig_cookies.txt")
            if not (os.path.isfile(_ig) and os.path.getsize(_ig) > 100):
                log.info("CLIP skipped (instagram): no IG_COOKIES — Instagram "
                         "serves nothing to a logged-out request")
                _PLATFORM_CLIP_CACHE[url] = None
                return None
            cmd[1:1] = ["--cookies", _ig]
            time.sleep(random.uniform(*FOOTAGE_FETCH_SLEEP_S))
        elif plat == "facebook":
            # r97: measured working with no impersonation and no cookies. The
            # ONE thing that broke it was --download-sections, which needs
            # ffmpeg; the plain retry below already covers that.
            pass
        elif plat == "file":
            # a direct media URL: yt-dlp fetches it as-is, no extractor needed
            pass
        elif plat == "youtube":
            if os.environ.get("VIDEO_YT_FETCH", "0") == "0":
                _PLATFORM_CLIP_CACHE[url] = None    # r31: walled from CI
                return None
            if ck:
                cmd[1:1] = ["--cookies", ck]
                time.sleep(random.uniform(*FOOTAGE_FETCH_SLEEP_S))
        elif plat == "x":
            cmd[1:1] = ["--impersonate", "chrome"]
            if ck:
                cmd[1:1] = ["--cookies", ck]
        # r95: KEEP THE ERROR. stderr went to DEVNULL, so every refusal — a
        # geo-block, a dead post, a missing impersonation target, a format that
        # does not exist — arrived as the same blank "fetch failed", and page
        # 131 lost both its clips without saying why. The message is the only
        # thing that tells us whether to retry differently or give up.
        _err = ''
        try:
            _p = subprocess.run(cmd, timeout=FOOTAGE_FETCH_TIMEOUT + 15,
                                stdout=subprocess.DEVNULL,
                                stderr=subprocess.PIPE, text=True, check=False)
            _err = (_p.stderr or '').strip()
        except Exception as exc:  # noqa: BLE001 (timeout included)
            _err = type(exc).__name__
            log.info("CLIP fetch failed (%s: %s)", plat, type(exc).__name__)
        hits = [p for p in glob.glob(os.path.join(WORKDIR, f"{stem}.*"))
                if p.rsplit(".", 1)[-1] in ("mp4", "webm", "mkv", "mov")
                and os.path.getsize(p) > 30000]
        # r95 SECOND CHANCE. Two of the flags we add are the usual suspects:
        # --download-sections needs the extractor to support ranges, and a
        # height-capped format may simply not exist on TikTok. If the first
        # attempt produced nothing, drop both and ask for whatever it has.
        if not hits and ('--download-sections' in cmd or '-f' in cmd):
            # r100: the retry drops the format selector AND the size cap —
            # the cap was itself a cause of "format is not available", so
            # carrying it into the rescue attempt defeated the rescue.
            _plain = ["yt-dlp", "--no-playlist", "--quiet", "--no-warnings",
                      "--max-filesize", "200M", "-o", outtmpl, url]
            if plat in ("kick", "twitch", "tiktok", "x"):
                _plain[1:1] = ["--impersonate", "chrome"]
            if ck and plat in ("youtube", "x", "tiktok"):
                _plain[1:1] = ["--cookies", ck]
            try:
                _p2 = subprocess.run(_plain, timeout=FOOTAGE_FETCH_TIMEOUT + 15,
                                     stdout=subprocess.DEVNULL,
                                     stderr=subprocess.PIPE, text=True, check=False)
                if (_p2.stderr or '').strip():
                    _err = (_p2.stderr or '').strip()
            except Exception as exc:  # noqa: BLE001
                _err = type(exc).__name__
            hits = [p for p in glob.glob(os.path.join(WORKDIR, f"{stem}.*"))
                    if p.rsplit(".", 1)[-1] in ("mp4", "webm", "mkv", "mov")
                    and os.path.getsize(p) > 30000]
            if hits:
                log.info("CLIP %s: plain retry worked where the windowed "
                         "download did not", plat)
        if not hits and _err:
            log.info("CLIP REFUSED (%s): %s | %s", plat,
                     _err.splitlines()[-1][:180], url[:70])
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
# r48: EVERY detected face, not just the biggest. detect_face_box collapses to
# max-area for framing, which threw the other faces away — and the judge kept
# failing frames for "human face on the left edge sliced by the frame border".
# The crop needs them all so it can avoid cutting through any of them.
_FACE_ALL = {}
_FACE_CASCADE = None
_PROFILE_CASCADE = None
_YUNET = None
YUNET_MODEL = os.environ.get("VIDEO_YUNET_MODEL",
                             "models/face_detection_yunet_2023mar.onnx")


def _face_cascade():
    global _FACE_CASCADE
    if _FACE_CASCADE is None:
        import cv2
        _FACE_CASCADE = cv2.CascadeClassifier(
            cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
    return _FACE_CASCADE


def _yunet():
    """r32: YuNet (cv2.FaceDetectorYN) — OpenCV's own CNN face detector, the
    documented modern replacement for the haar cascade. Built into the opencv
    we already install; the model is a 232KB ONNX from opencv/opencv_zoo. It is
    specifically strong on the side/occluded faces that beheaded our subjects
    (OpenCV's own comparison: 10 faces found vs the cascade's 7). Cascades stay
    as the fallback when the model file is missing."""
    global _YUNET
    if _YUNET is None:
        import cv2
        _YUNET = cv2.FaceDetectorYN.create(
            YUNET_MODEL, "", (320, 320), 0.6, 0.3, 5000)
    return _YUNET


def _profile_cascade():
    """r31: the frontal cascade misses a turned head, a tilted head, shades or
    a hat brim — exactly how our subjects are photographed. Every miss fell
    through to a CENTER crop, which is what beheaded Soulja Boy at 0.4s and
    sliced Kai Cenat's forehead off for three straight scenes."""
    global _PROFILE_CASCADE
    if _PROFILE_CASCADE is None:
        import cv2
        _PROFILE_CASCADE = cv2.CascadeClassifier(
            cv2.data.haarcascades + "haarcascade_profileface.xml")
    return _PROFILE_CASCADE


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
            faces = []
            if os.path.isfile(YUNET_MODEL):
                try:                       # r32: YuNet first — it sees profiles
                    det = _yunet()
                    det.setInputSize((img.shape[1], img.shape[0]))
                    _, found = det.detect(img)
                    if found is not None and len(found):
                        faces = [(f[0], f[1], f[2], f[3]) for f in found]
                except Exception as exc:  # noqa: BLE001 -> cascade fallback
                    log.info("YuNet unavailable (%s); cascade fallback",
                             str(exc)[:70])
                    faces = []
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            gray = cv2.equalizeHist(gray)
            if not len(faces):
                faces = _face_cascade().detectMultiScale(
                    gray, scaleFactor=1.1, minNeighbors=5, minSize=(36, 36))
            if not len(faces):
                # r31: turned head / shades / hat -> try the profile cascade,
                # then the mirrored frame (it only detects one side).
                try:
                    faces = _profile_cascade().detectMultiScale(
                        gray, scaleFactor=1.1, minNeighbors=4, minSize=(36, 36))
                    if not len(faces):
                        flipped = cv2.flip(gray, 1)
                        got = _profile_cascade().detectMultiScale(
                            flipped, scaleFactor=1.1, minNeighbors=4,
                            minSize=(36, 36))
                        gw = gray.shape[1]
                        faces = [(gw - int(x) - int(fw), y, fw, fh)
                                 for (x, y, fw, fh) in got]
                except Exception:  # noqa: BLE001
                    faces = []
            if len(faces):
                x, y, fw, fh = max(faces, key=lambda f: int(f[2]) * int(f[3]))
                box = (x / scale, y / scale, fw / scale, fh / scale)
                _FACE_ALL[path] = [(float(f[0]) / scale, float(f[1]) / scale,
                                    float(f[2]) / scale, float(f[3]) / scale)
                                   for f in faces]
                log.info("face detected in %s: %dx%d at (%d,%d)",
                         os.path.basename(path), int(box[2]), int(box[3]),
                         int(box[0]), int(box[1]))
    except Exception as exc:  # noqa: BLE001
        log.warning("face detection unavailable (%s); center framing", exc)
    _FACE_CACHE[path] = box
    return box


def cover_fit_headroom(pil_img, tw, th, bias=0.14):
    """r31: cover-crop biased toward the TOP of the source, not dead center.
    When no face is detected the center crop of a standing person is their
    torso — the head sits above the crop window. That is what beheaded the
    0.4s hook frame. Heads live in the upper part of a photo, so keep the
    upper part: the crop window starts 14% into the vertical slack instead
    of 50%."""
    pil_img = pil_img.convert("RGB")
    w, h = pil_img.size
    scale = max(tw / float(w), th / float(h))
    nw = max(tw, int(round(w * scale)))
    nh = max(th, int(round(h * scale)))
    img = pil_img.resize((nw, nh), Image.Resampling.LANCZOS)
    top = max(0.0, min((nh - th) * bias, float(nh - th)))
    left = max(0.0, (nw - tw) / 2.0)
    return img.crop((int(left), int(top), int(left) + tw, int(top) + th))


def cover_fit_face(pil_img, tw, th, box, all_faces=None):
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

    # r48 EDGE-SAFE CROP (judge, repeatedly: "human face on the left edge of the
    # frame is sliced by the frame border"). The eyeline rules above frame the
    # PRIMARY face well, but every other face in the photo was ignored, so the
    # crop boundary regularly landed in the middle of someone's head — the exact
    # thing the owner sees. This is Katna's idea without Katna's scipy weight:
    # try a handful of candidate horizontal offsets and keep the one that slices
    # the fewest faces, preferring the one closest to the ideal framing. A face
    # is "sliced" when the crop edge passes THROUGH it — fully in or fully out
    # are both fine.
    faces_scaled = []
    for f in (all_faces or []):
        try:
            faces_scaled.append((f[0] * sx, f[2] * sx))       # (x, width)
        except Exception:  # noqa: BLE001
            continue
    if len(faces_scaled) > 1:
        def _sliced(cand_left):
            n = 0
            for fx0, fwid in faces_scaled:
                fx1 = fx0 + fwid
                # straddles the left edge or the right edge of the crop window
                if (fx0 < cand_left < fx1) or (fx0 < cand_left + tw < fx1):
                    n += 1
            return n
        cands = [left]
        for f0, fwd in faces_scaled:                 # align edges just outside faces
            cands.append(f0 - 8)                     # face fully to the right
            cands.append(f0 + fwd + 8 - tw)          # face fully to the left
        cands = [max(0.0, min(c, nw - tw)) for c in cands]
        best = min(cands, key=lambda c: (_sliced(c), abs(c - left)))
        if _sliced(best) < _sliced(left):
            log.info("EDGE-SAFE CROP: shifted %+dpx — %d sliced face(s) -> %d",
                     int(best - left), _sliced(left), _sliced(best))
            left = best

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
            # r132 (from MoneyPrinterTurbo's current material.py): landscape
            # is REJECTED, not kept as fallback. Their hard-learned rule —
            # "unverifiable/mismatched orientation mixes landscape into
            # portrait tasks and produces black bars" — matches our own
            # physics: a 1920x1080 clip cover-cropped to 9:16 needs 1.78x
            # upscale, past our COVER_MAX_UPSCALE sharpness ceiling, so it
            # rendered as letterboxed filler anyway. Photos are the better
            # fallback we already have.
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
        # r132 (MoneyPrinterTurbo's material_cache, adapted for an ephemeral
        # runner): their cache assumes a persistent server; ours dies with
        # every workflow run. BROLL_CACHE_DIR points at a directory the
        # workflow persists via actions/cache, so a stock clip downloaded for
        # one video is free for every later video that searches similar terms
        # — Pexels/Pixabay quota and runner bandwidth stop being paid twice
        # for the same file.
        if BROLL_CACHE_DIR:
            cached = os.path.join(BROLL_CACHE_DIR, f"broll-{key}.mp4")
            if os.path.isfile(cached) and _looks_like_mp4(cached):
                log.info("broll cache HIT: %s", os.path.basename(cached))
                self.downloads[key] = cached
                return cached
            got = self._stream_to(url, cached)
            # r132b (MoneyPrinterTurbo detects Cloudflare challenge pages on
            # its API calls; same disease, our weakest point): a CDN error or
            # challenge page saved as .mp4 would live FOREVER in the
            # persistent cache and poison every future render that hits it.
            # Nothing enters the cache without the mp4 magic bytes.
            if got and not _looks_like_mp4(got):
                log.info("broll download is not an mp4 (challenge/error page); discarded")
                try:
                    os.remove(got)
                except OSError:
                    pass
                got = None
            self.downloads[key] = got
            return got
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
            text = vision_post(body, timeout=60, tag="broll-rerank")
            if not text:
                log.warning("vision re-rank unavailable; keyword order")
                return None
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


# r135 EMOJI STRIP (owner watch session, page 259): a quoted tweet's emojis
# ("!!" + folded-hands) reached the spoken script, and the caption font has
# no emoji glyphs — the published video showed tofu boxes on screen. Emojis
# are stripped once at ingest in make_one, so TTS, forced alignment and the
# captions all inherit clean text from the same place.
_EMOJI_RX = re.compile(
    "["
    "\\U0001F000-\\U0001FAFF"        # emoticons, symbols, transport, extended
    "\\U0001FB00-\\U0001FBFF"
    "\\U0001F1E6-\\U0001F1FF"        # regional-indicator flags
    "\\u2600-\\u27BF"                # misc symbols + dingbats
    "\\u2B00-\\u2BFF"                # more symbols (stars, votes)
    "\\u2190-\\u21FF"                # arrows
    "\\u203C\\u2049\\u2122\\u2139"   # bang-bang, interrobang, tm, info
    "\\uFE00-\\uFE0F"                # variation selectors
    "\\u200D"                        # zero-width joiner
    "]+")


def strip_emoji(text):
    t = _EMOJI_RX.sub(" ", str(text or ""))
    return re.sub(r"[ \t]{2,}", " ", t).strip()


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


def build_edl(shotlist, script, timings, total, pool_n=None):
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
                # TIMELINE CONTRACT (2026-08-06): deterministic beat fields
                # from the timeline generator — "date" renders as the beat's
                # date chip; "clip_url" is the beat's OWN platform clip
                # (1 event = 1 artifact = 1 sentence; a data join, never a
                # Director guess).
                "date": str(s.get("date") or "").strip(),
                "clip_url": str(s.get("clip_url") or "").strip(),
                "event_id": int(s.get("event_id") or 0),
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

        # r135 HOLD CAP: split still-backed shots longer than
        # MAX_STILL_HOLD_S at word boundaries (see the constant's comment).
        # The final shot is never split — it rides the outro card and the
        # audio tail, and a random pool photo AFTER the brand card would be
        # worse than the hold.
        # r135b (run #247 judge reject): the first version unpinned EVERY
        # continuation to the photo pool — 5 splits drained page 116's
        # 8-still pool and one stage photo carried 3 frames (repetition
        # fail). Now: a receipt continuation KEEPS its card and re-cuts it
        # as a punch-in (zero pool cost — the pro "zoom into the document"
        # move), and photo-pool continuations are budgeted against the
        # actual pool headroom so a thin story keeps its longer holds
        # instead of recycling images.
        n_subj_base = sum(1 for x in merged if x["shot_class"] == "subject"
                          and not (x.get("clip") or x.get("clip_url")))
        photo_budget = (max(0, int(pool_n) - n_subj_base - 1)
                        if pool_n is not None else 99)
        split = []
        for si, sh in enumerate(merged):
            dur = sh["end"] - sh["start"]
            has_motion = (sh.get("clip") or sh.get("clip_url")
                          or sh["shot_class"] == "broll")
            if (has_motion or si == len(merged) - 1
                    or dur <= MAX_STILL_HOLD_S * 1.35):
                split.append(sh)
                continue
            is_receipt = (sh["shot_class"] == "receipt"
                          and sh.get("receipt_i") is not None)
            n_seg = max(2, int(math.ceil(dur / MAX_STILL_HOLD_S)))
            if is_receipt:
                # card + one punch-in are free; segments beyond 2 need pool
                n_seg = min(n_seg, 2 + photo_budget)
            else:
                if n_seg - 1 > photo_budget:
                    n_seg = 1 + photo_budget
                if n_seg < 2:
                    log.info("HOLD CAP: %.1fs subject shot kept whole "
                             "(pool has no headroom)", dur)
                    split.append(sh)
                    continue
            cuts = []                       # (word index, boundary time)
            prev_b = sh["start"]
            for ci in range(1, n_seg):
                tgt = sh["start"] + dur * ci / n_seg
                wi = min(range(sh["w_in"] + 1,
                               min(sh["w_out"], n_tok - 1) + 1),
                         key=lambda w: abs(spans[w][0] - tgt),
                         default=None)
                if wi is None:
                    break
                b = spans[wi][0] - VISUAL_LEAD_S
                if (b < prev_b + 1.2 or b > sh["end"] - 1.2
                        or (cuts and wi <= cuts[-1][0])):
                    continue
                cuts.append((wi, b))
                prev_b = b
            if not cuts:
                split.append(sh)
                continue
            bounds = [sh["start"]] + [b for _, b in cuts] + [sh["end"]]
            w_starts = [sh["w_in"]] + [wi for wi, _ in cuts]
            for gi in range(len(bounds) - 1):
                seg = dict(sh)
                seg["start"], seg["end"] = bounds[gi], bounds[gi + 1]
                seg["w_in"] = w_starts[gi]
                seg["w_out"] = (w_starts[gi + 1] - 1
                                if gi + 1 < len(w_starts) else sh["w_out"])
                seg["w_out"] = max(seg["w_in"], seg["w_out"])
                seg["phrase"] = " ".join(
                    tokens[seg["w_in"]:seg["w_out"] + 1])
                if sh.get("emph_t") is not None and not (
                        seg["start"] <= sh["emph_t"] < seg["end"]):
                    seg["emph_t"] = None
                if gi > 0:
                    seg["sfx"] = "none"     # no re-slam mid-beat
                    if is_receipt and gi == 1:
                        # same evidence, deeper cut — planner honors this
                        seg["card_hold"] = True
                    else:                   # photo continuation: fresh LRU
                        seg["shot_class"] = "subject"
                        seg["receipt_i"] = None
                        seg["visual_i"] = None
                        photo_budget -= 1
                split.append(seg)
            log.info("HOLD CAP: %.1fs %s shot -> %d segments%s",
                     dur, sh["shot_class"], len(bounds) - 1,
                     " (card punch-in)" if is_receipt else "")
        merged = split

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


def harvest_clip_frames(clip_path, pool, want=12, label="event footage"):
    """r54/r57: turn ONE downloaded clip into ~12 extra pool stills.

    Every frame is high-res, on-topic by construction, real (not AI), free and
    carries no licensing doubt — the best supply source we have, and the direct
    cure for the pool starvation the quality gates keep exposing.

    r57 WIRING FIX: r54 buried this inside the "money moment" hook branch, which
    only fires for a YouTube clip carrying the reporter's ?start= timestamp.
    YouTube is bot-walled from CI (measured again in tts-test: "Sign in to
    confirm you're not a bot", with cookies, tunnel or no tunnel), so on a real
    story the branch never opened and the harvest never ran once — even when a
    clip HAD been downloaded successfully from archive.org. Any clip we are
    already holding is now harvested, whatever platform it came from.

    Returns the number of stills added. Never raises."""
    added = 0
    try:
        # r71 FRAME SPACING (judge: "REPETITION: image repeated"). Sampling every
        # 0.75s from one continuous shot produced near-identical stills: they
        # counted as 12 images but read as ONE picture on screen, so the video
        # was rejected for repetition while the pool "had" 27 visuals. Two fixes:
        #   1. spread the samples across the WHOLE clip instead of a fixed 0.75s
        #      cadence, so we cross actual cuts and movement;
        #   2. judge sameness far more strictly for clip frames. 6/64 bits apart
        #      is nothing between two frames of the same static shot — the
        #      threshold below is what let visually identical frames through.
        try:
            from moviepy import VideoFileClip as _VFC
            _v = _VFC(clip_path); _dur = float(_v.duration or 0); _v.close()
        except Exception:  # noqa: BLE001
            _dur = 0.0
        if _dur > 1.5:
            _lo, _hi = 0.3, max(0.6, _dur - 0.3)
            _times = [_lo + (_hi - _lo) * i / float(max(1, want - 1))
                      for i in range(want)]
        else:
            _times = [0.6 + 0.75 * k for k in range(want)]
        frames = _extract_frames_at(clip_path, _times,
                                    prefix="clipfr", width=1080)
        kept_hashes = []
        for fp, _ft in frames:
            dh = image_dhash(fp)
            if any(dhash_distance(dh, e.get("dhash")) <= 6 for e in pool):
                continue                  # near-duplicate of a pool image
            # STRICT against sibling frames from this same clip.
            if any(dhash_distance(dh, kh) < CLIP_FRAME_MIN_DIFF
                   for kh in kept_hashes):
                log.info("clip frame too similar to one already kept; skipped")
                continue
            kept_hashes.append(dh)
            entry = {"path": fp, "textish": False, "url": None,
                     "person": None, "designed": False,
                     "dhash": dh, "quality": image_quality(fp)}
            try:
                entry["has_face"] = detect_face_box(fp) is not None
            except Exception:  # noqa: BLE001
                entry["has_face"] = True
            pool.append(entry)
            added += 1
        if added:
            log.info("CLIP FRAMES: +%d stills harvested from the %s "
                     "(pool %d -> %d)", added, label,
                     len(pool) - added, len(pool))
        else:
            log.info("CLIP FRAMES: 0 usable stills from the %s (all "
                     "near-duplicates of the pool)", label)
    except Exception as exc:  # noqa: BLE001 — never fatal
        log.info("clip-frame harvest unavailable (%s)", str(exc)[:70])
    return added


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
    r42: variety rules are tuned to the ACTUAL pool size first (see
    tune_variety_for_pool) so a rich pool never recycles a photo.
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
    # TIMELINE POOL HYGIENE (run #242): the visual pool carries harvested
    # event/gallery thumbnails of UNVERIFIED provenance — for 131 that meant
    # a commentary creator's ad-course TikTok thumbs, which rode beats as
    # fallbacks whenever the beat's own artifact died (deleted TikToks).
    # Under the timeline contract a fallback must still be TRUE: restrict the
    # pool to identity-verified person photos (+ the cover) so a failed beat
    # shows the story's people, never a stranger's thumbnail.
    if _TIMELINE_MODE[0] and pool:
        # r80: a person LABEL is not person IMAGERY. Kyedae's December vlog
        # thumbnail ("GINGERBREAD HOUSES", burned-in text and all) carried
        # person="Kyedae" and sailed through this filter into an awards-
        # scandal video — the judge caught it, twice. A YouTube thumbnail is
        # a decorated promo frame, not a portrait; in timeline mode it can
        # never be evidence, whoever it is labeled as. This strip is
        # UNCONDITIONAL (the earlier person/designed preference only applied
        # when >=2 safe entries existed, so on thin pools the junk that
        # caused the rejection was exactly what survived).
        no_thumb = [e for e in pool
                    if "ytimg.com/vi" not in (e.get("url") or "")]
        if no_thumb and len(no_thumb) < len(pool):
            log.info("TIMELINE POOL: dropped %d thumbnail(s) — decorated "
                     "promo frames are never evidence",
                     len(pool) - len(no_thumb))
            pool = no_thumb
        # r80b: the old person/designed preference is GONE. It existed to
        # exclude unverified thumbnails, which the strip above now removes
        # directly — so all it still did was discard genuine report photos
        # for lacking a person label. The 116 retry proved it: pool cut
        # 6 -> 2 (the Kotaku screenshot of the actual moment thrown away),
        # then rejected for repetition because two images can't carry a
        # video. Report photos, portraits and covers are all legitimate
        # timeline imagery; the per-image gates already police quality.
    # r42: size the variety rules to THIS story's pool before any picking.
    try:
        tune_variety_for_pool(
            len({e.get("path") for e in (pool or []) if e.get("path")}),
            len(edl or []))
    except Exception:  # noqa: BLE001 — variety tuning is never fatal
        pass
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
        # r67 FACES LEAD: when the style leads on people, a pool image with a
        # visible face beats a scene-setting plate for the same slot. Only a
        # PREFERENCE — if no faces are free we fall through to the normal set,
        # so this can never starve the picker.
        if STYLE_LEAD == "faces":
            _faces = [e for e in cands if e.get("has_face")]
            if len(_faces) >= 2:
                cands = _faces
        if not cands:
            prev = scenes[-1].get("path") if scenes else None
            cands = [e for e in base if e["path"] != prev] or base
        if not scenes:
            # r40 OPENER LAW (r39 opened on an over-zoomed blurry face): the
            # first thing on screen is the BEST image we have — a real face,
            # then the sharpest/biggest — never whatever LRU happens to serve.
            best = [e for e in cands if not e.get("textish")] or cands
            faced = [e for e in best if e.get("has_face")] or best
            entry = max(faced, key=lambda e: e.get("quality") or (0.0, 0.0))
            last_used[entry["path"]] = si
            return entry
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
        contain_here = False           # r57: contained render, still a photo
        planned_here = False           # r17: this scene is a PLANNED clip shot
        footage = False                # r25: init early — GAP-FILL may set it
                                       # before the opportunistic upgrade block
        gapfill = False                # r25: this scene came from GAP-FILL
        sfx, emph_t = sh["sfx"], sh["emph_t"]

        # TIMELINE CONTRACT (format pivot 2026-08-06): a shot carrying
        # "clip_url" is a deterministic artifact ORDER — the timeline script
        # generator bound this beat to its OWN event's platform clip
        # (1 dated event = 1 artifact = 1 sentence; placement is a data
        # join, not a Director guess — the owner's "clip at the wrong
        # place" class of failure is impossible by construction). Fetch
        # failure falls through to the beat's receipt/photo like any shot.
        t_curl = str(sh.get("clip_url") or "").strip()
        if t_curl:
            _tp = fetch_platform_clip(t_curl)
            # r87: a clip a reporter embedded in this story's own article is
            # topical by publication. The vision gate judges ONE sampled frame,
            # and a 9:16 TikTok frame is usually a face or a caption card, so
            # it read both of page 192's clips as "off-topic" and shipped a
            # clips-first video with no clips in it. Provenance wins; the gate
            # still guards every clip we merely FOUND.
            _trusted = t_curl in _STORY_CLIP_SRC
            if _tp and _trusted:
                log.info("PROVENANCE: beat %d clip kept without a vision vote "
                         "(embedded in this story's own coverage)", si + 1)
            if _tp and (_trusted or footage_is_relevant(_tp, title)):
                path, typ, textish = _tp, "broll", False
                # run #237 judge lesson: TikTok clips carry burned-in
                # captions edge to edge — ANY zoom motion crops them
                # mid-word (judge criterion a). motion=None = plain cover
                # fit; a 9:16 source fills the 9:16 frame uncropped.
                motion, footage = None, True
                planned_here = True
                foot_n += 1
                foot_s += need_s
                log.info("TIMELINE ARTIFACT: beat %d plays its own clip %s",
                         si + 1, os.path.basename(_tp))
            else:
                log.info("TIMELINE ARTIFACT: clip unavailable for beat %d "
                         "(%s); receipt fallback", si + 1,
                         "off-topic" if _tp else "fetch failed")
                # truthful judge manifest (run #242): the artifact died
                # upstream — stop DEMANDING it on this beat, or the judge
                # fails the honest fallback for not being the dead clip
                sh["clip_url"] = ""

        # r45 HOOK LAW (owner: "the video is about the slap — why not pull the
        # clip itself and put it in the hook, the hook is what keeps the watcher").
        # ~1/3 of viewers leave inside 3s, so the FIRST scene must be the event,
        # not a portrait of a bystander. The source article embedded the footage
        # at a timestamp (?start=182 = the slap); fetch_platform_clip cuts that
        # exact window. If we land it, scene 1 opens on it — outranking receipts,
        # portraits and every pinned still. Failure changes nothing downstream.
        # r126 HOOK STUDY: this branch was excluded from timeline mode — the
        # only mode we ship — so every video opened on a frozen still while
        # the measured exodus happened at 0:01. The first frame is the
        # thumbnail on TikTok; it must MOVE. Timeline videos now open on a
        # story clip too, and when no reporter-timestamped money clip exists,
        # any identity-vetted clip from the pool qualifies (they all passed
        # the author gate before reaching the feed).
        if si == 0 and clip_pool and _HOOK_CLIP[0] is None:
            # r126b: try candidates in order until one actually FETCHES.
            # The single-shot version bet everything on clip_pool[0], which
            # on the first live run was a TikTok URL the runner cannot
            # download — one predictable failure and the whole opener fix
            # silently reverted to the frozen still it exists to replace.
            # Money-moment clips (reporter timestamp) still rank first.
            _cands = ([u for u in clip_pool if _STORY_CLIP_START.get(u)]
                      + [u for u in clip_pool if not _STORY_CLIP_START.get(u)])
            for _money in _cands[:3]:
                _hp = fetch_platform_clip(_money)
                if _hp and footage_is_relevant(_hp, title):
                    _HOOK_CLIP[0] = _hp
                    harvest_clip_frames(_hp, pool, label="money-moment clip")
                    try:
                        clip_pool.remove(_money)
                    except ValueError:
                        pass
                    path, typ, textish = _hp, "broll", False
                    motion, footage = "punch_build", True
                    foot_n += 1
                    foot_s += need_s
                    log.info("HOOK = OPENING CLIP: %s (t=%ds)",
                             os.path.basename(_hp),
                             _STORY_CLIP_START.get(_money, 0))
                    break
                log.info("HOOK: candidate unavailable (%s); trying next",
                         "off-topic" if _hp else "fetch failed")
            if _HOOK_CLIP[0] is None:
                log.info("HOOK: no fetchable clip; normal opener")
        # r57 SUPPLY HARVEST — the money-moment branch above is the ONLY thing
        # that ever called the frame harvest, and it needs a YouTube clip with
        # the reporter's ?start= timestamp. Most stories have neither. But we
        # often DO hold a perfectly good clip from archive.org / Twitch / Kick /
        # TikTok, already downloaded, whose frames are real on-topic photos of
        # the event. Mine it for stills even when it is not the opener. The
        # clip stays in clip_pool so it can still play as footage later, and
        # fetch_platform_clip caches per URL, so this costs no extra download.
        if (si == 0 and clip_pool and not _CLIP_FRAMES_DONE[0]
                and _HOOK_CLIP[0] is None
                and not _TIMELINE_MODE[0]):     # money clip already harvested
            _CLIP_FRAMES_DONE[0] = True
            _got = 0
            for _cu in list(clip_pool)[:2]:
                _cp = fetch_platform_clip(_cu)
                if _cp and harvest_clip_frames(_cp, pool,
                                               label="story clip"):
                    _got = 1
                    break
            if not _got:
                # r58: say so. Run #199 held one archive clip and harvested
                # nothing, with zero log lines explaining why.
                log.info("CLIP FRAMES: no usable clip of the %d available "
                         "(fetch or a quality gate refused every one); pool "
                         "stays at %d", len(clip_pool), len(pool))
        # r45: `path is None` guard — the HOOK LAW above may already have claimed
        # scene 1 for the money clip, and a receipt must not overwrite the event.
        # (The subject/broll branches below already carried this guard.)
        if path is None and sh["shot_class"] == "receipt":
            rv = receipts.get(sh.get("receipt_i"))
            r_photo = isinstance(rv, dict)     # r17: og report photo entry
            path = rv.get("path") if r_photo else rv
            # r21 SCENE-LEVEL EVIDENCE CAP (filmstrip verdict: the same
            # article screenshot still carried 3 scenes via receipt_i reuse —
            # the per-index cap has an index-reuse loophole). Any single
            # evidence image backs at most 2 SCENES, full stop.
            # r135b card_hold: the hold-cap split's punch-in continuation is
            # the SAME card on purpose (deeper cut of the evidence, zero pool
            # cost) — the repeat vetoes below would turn it back into the
            # pool drain that failed run #247, so it passes them.
            # r135c: ONLY when its own base segment actually showed this card.
            # Run #248 proved the hole: the Director pinned one article on TWO
            # beats; beat 2's base was correctly vetoed to a photo (2-scene
            # cap), but its punch-in still bypassed the cap — same document in
            # 3 frames, the judge's exact fail pattern.
            if path and sh.get("card_hold"):
                if scenes and scenes[-1].get("path") == path:
                    log.info("receipt %s continues as punch-in (hold-cap "
                             "split)", sh.get("receipt_i"))
                else:
                    log.info("receipt %s punch-in dropped (base segment did "
                             "not carry this card); subject photo",
                             sh.get("receipt_i"))
                    path = None
            elif path and evidence_scene_uses.get(path, 0) >= EVIDENCE_MAX_SCENES:
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
            if path and r_photo and _TIMELINE_MODE[0]:
                # run #244 (supersedes the run-#240 relevance gate, which a
                # commentary creator's promo PASSED — commentary IS
                # "related"): og social-cards are the weakest evidence class
                # (the article's marketing thumbnail, often a promo
                # composite). Under the timeline contract they no longer
                # ride receipts at all — the beat falls to the verified
                # person photo instead. og images still serve the carousel,
                # where each faces a per-sentence DEPICTION gate.
                log.info("receipt %s og social-card excluded under timeline "
                         "contract; person-photo fallback",
                         sh.get("receipt_i"))
                path = None
            if path and r_photo:
                # r17: the article's real og:image — it IS the moment's
                # photo, so it renders as a NORMAL photo scene (cover-crop,
                # face-aware), never the contain/card path.
                typ, textish = "photo", False
                log.info("receipt %s -> og report photo (photo scene)",
                         sh.get("receipt_i"))
            elif path:
                typ, textish = "receipt", True
                # r113: the "receipt slam" used to add a pop to EVERY card
                # scene whose beat asked for silence — so r110's "no effect
                # unless it marks something" was overruled on exactly the
                # card-heavy videos, and page 259 came back with six sounds
                # where the script had ordered three. The beat decides now;
                # silence means silence.
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
                # r42 (owner: "they keep repeating the same imgs"): prefer a
                # photo of this person we have NEVER shown. Since the people fix
                # each person now carries up to 8 real photos, so revisiting one
                # while a fresh shot of the SAME person sits unused is pure
                # self-inflicted repetition. Falls back to the old
                # first-not-recent rule when they have all been used.
                for k in range(len(p_entries)):
                    cand = p_entries[(start + k) % len(p_entries)]
                    if cand["path"] not in recent and cand["path"] not in last_used:
                        entry = cand
                        person_rot[pname] = (start + k + 1) % len(p_entries)
                        break
                if entry is None:
                    for k in range(len(p_entries)):    # first of theirs not recent
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
            from_pin = False
            if entry is None and sh.get("visual_i") is not None \
                    and sh["visual_i"] in visual_map:
                entry = visual_map[sh["visual_i"]]
                from_pin = True
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
            elif entry is not None and from_pin and entry["path"] in last_used:
                # r42 THE REPEAT SOURCE (measured on page 415: the Director
                # pinned visual_i=0 THREE times across 23 shots, and the window
                # guard above only catches repeats INSIDE the window, so the
                # cover photo came back twice more while 24 pool images sat
                # untouched). If a pinned image has already been on screen and
                # the pool still holds images we have never shown, spend a fresh
                # one — _lru_pick ranks never-used entries first.
                _unused = [e for e in pool
                           if e.get("path") and e["path"] not in last_used
                           and not e.get("designed")]
                if _unused:
                    log.info("pinned visual_i already shown at scene %d; %d "
                             "unused pool image(s) left -> fresh pick instead",
                             last_used.get(entry["path"], -1), len(_unused))
                    entry = None
                    planned_here = False
            if entry is not None:
                last_used[entry["path"]] = si          # r11: LRU sees pins too
                path, typ, textish = entry["path"], "photo", entry["textish"]
                contain_here = bool(entry.get("contain"))     # r57
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
                    contain_here = bool(entry.get("contain"))     # r57
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
                and not _TIMELINE_MODE[0]
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
                    # r28 SMART GATE here too: an article-embedded clip can still
                    # be a music video (the reporter used it as b-roll). Vision-
                    # check it against the topic; off-topic -> try the next clip.
                    if (cand and cand not in _recent_paths()
                            and footage_is_relevant(cand, title)):
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
            "contain": contain_here,   # r57: contain-render without card rules
            "src_off": FOOTAGE_SUB_OFF_S if footage else None,
            "date": str(sh.get("date") or ""),   # timeline beat's date chip
            "card_hold": bool(sh.get("card_hold")),  # r135b punch-in segment
            # freeze-frame edit: timeline clip beats longer than ~4s play
            # 2.5s of real motion then hold the frame under the narration
            "freeze_after": 2.5 if (planned_here and need_s > 4.0) else None,
            "event_id": int(sh.get("event_id") or 0),
            "is_clip_beat": bool(planned_here),
        })
        # truthful judge manifest: record what this beat actually RESOLVED
        # to (broll/receipt/photo) on the shared EDL dict — the judge's
        # per-frame expectation follows reality, not the dead original plan
        sh["resolved"] = typ if not textish else "receipt"
        prev_motion = motion

    # r46 SPEND THE POOL (owner, watching the scene plan: "fix the picker so it
    # uses all the images"). Repetition survived r42/r43 because it enters from
    # THREE independent doors: a pinned visual_i, a person shot cycling a short
    # photo list, and the LRU fallback. Rather than police each door, enforce the
    # outcome once, here: an image may not appear a second time while ANY pool
    # image is still unused. Measured on this story vis-415-0 carried scenes
    # 4, 5 AND 10 while other pool images were never touched.
    # Receipts (textish) and footage are exempt — a proof card is chosen for what
    # it PROVES, and footage is not interchangeable with a still.
    if scenes and pool:
        _used, _swapped = {}, 0
        for sc in scenes:
            p = sc.get("path")
            if not p or sc.get("textish") or sc.get("footage"):
                continue
            if p in _used:
                _fresh = [e for e in pool
                          if e.get("path") and e["path"] not in _used
                          and not e.get("designed") and not e.get("textish")]
                if _fresh:
                    sc["path"] = _fresh[0]["path"]
                    _used[sc["path"]] = 1
                    _swapped += 1
                    continue
            _used[p] = 1
        if _swapped:
            log.info("SPEND THE POOL: %d repeat(s) swapped for unused images "
                     "(%d distinct stills now on screen)", _swapped, len(_used))

    # r43 PACING: split long STILLS into ~SCENE_SPLIT_TARGET_S beats, each with a
    # different image, so the picture changes at short-form rhythm instead of
    # sitting for ~3s. Audio/captions are untouched — a sub-beat only subdivides
    # its parent's own time window. Cards (textish: need read time) and footage
    # (already moving) are never split. The parent's one-shot cues (sfx, emphasis)
    # stay on the first beat so the sound design is unchanged.
    if SCENE_SPLIT_TARGET_S > 0 and scenes:
        split_scenes = []
        for sc in scenes:
            dur = float(sc["end"]) - float(sc["start"])
            parts = 1
            if (sc.get("type") == "photo" and not sc.get("textish")
                    and not sc.get("footage") and dur >= SCENE_SPLIT_MIN_S):
                parts = max(1, min(SCENE_SPLIT_MAX_PARTS,
                                   int(dur // SCENE_SPLIT_TARGET_S)))
            if parts <= 1:
                split_scenes.append(sc)
                continue
            step = dur / float(parts)
            for k in range(parts):
                sub = dict(sc)
                sub["start"] = float(sc["start"]) + k * step
                sub["end"] = (float(sc["start"]) + (k + 1) * step
                              if k < parts - 1 else float(sc["end"]))
                if k > 0:
                    si_here = len(split_scenes)
                    recent = {s.get("path")
                              for s in split_scenes[-POOL_NO_REPEAT_WINDOW:]}
                    cands = [e for e in pool
                             if e.get("path") and e["path"] not in recent
                             and not e.get("designed") and not e.get("textish")]
                    if not cands:
                        # r50 FROZEN GUARD: a beat with no fresh image would
                        # inherit the parent's, and 3 identical consecutive
                        # scenes is exactly what the pre-encode selfcheck
                        # hard-fails ("same image FROZEN", page 515 crash). So
                        # stop splitting here and give the remaining time back
                        # to the beat we already created — better one honest
                        # longer shot than a fake cut to the same picture.
                        if split_scenes:
                            split_scenes[-1]["end"] = float(sc["end"])
                        break
                    alt = min(cands,
                              key=lambda e: last_used.get(e["path"], -1))
                    sub["path"] = alt["path"]
                    sub["textish"] = False
                    sub["contain"] = bool(alt.get("contain"))
                    sub["footage"] = False
                    sub["src_off"] = None
                    last_used[alt["path"]] = si_here
                    # one-shot cues belong to the parent's first beat only
                    sub["sfx"] = None
                    sub["emph_t"] = None
                    sub["emph_rel"] = None
                    sub["motion"] = "zoom_out" if (k % 2) else "zoom_in"
                split_scenes.append(sub)
        if len(split_scenes) != len(scenes):
            log.info("PACING: %d scenes -> %d beats (target %.1fs/image, "
                     "avg %.2fs)", len(scenes), len(split_scenes),
                     SCENE_SPLIT_TARGET_S,
                     (float(scenes[-1]["end"]) - float(scenes[0]["start"]))
                     / max(1, len(split_scenes)))
            scenes = split_scenes

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
        "style": STYLE_NAME,          # r65: which A/B treatment this video used
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


# =================  TREATMENT V2: 2.5D DEPTH PARALLAX (2026-08-05)  ==========
# The camera moves THROUGH the real photo instead of zooming a flat image:
# every pixel is displaced by its estimated depth (foreground travels more
# than background), on top of the exact Law-6 zoom curves and face-anchor
# rules scene_clip already enforces. Nothing is generated — the pixels are
# the photo's own (found-not-made law holds). Any failure at any point
# returns None and the caller falls back to classic scene_clip.

_DEPTH_SESS = "unset"          # tri-state: "unset" | None (dead) | session
_DEPTH_CACHE = {}              # image_path -> float32 HxW depth of its frame
_GRAIN_PLATE = None


def _depth_session():
    global _DEPTH_SESS
    if _DEPTH_SESS != "unset":
        return _DEPTH_SESS
    _DEPTH_SESS = None
    if not DEPTH_PARALLAX:
        return None
    try:
        import onnxruntime as ort
        if (not os.path.isfile(DEPTH_MODEL_PATH)
                or os.path.getsize(DEPTH_MODEL_PATH) < 10_000_000):
            log.info("depth: no model at %s — classic motion", DEPTH_MODEL_PATH)
            return None
        _DEPTH_SESS = ort.InferenceSession(
            DEPTH_MODEL_PATH, providers=["CPUExecutionProvider"])
        log.info("depth: session ready")
    except Exception as exc:  # noqa: BLE001
        log.warning("depth: unavailable (%s) — classic motion", exc)
        _DEPTH_SESS = None
    return _DEPTH_SESS


def depth_for_frame(arr, key=None):
    """Relative depth (float32 HxW in [0,1], 1=near) for an RGB uint8 frame.
    Preprocessing copied verbatim from fabio-sim/Depth-Anything-ONNX
    dynamo.py infer() (its own comment: "implement this part in your chosen
    language"). None on any failure."""
    if key is not None and key in _DEPTH_CACHE:
        return _DEPTH_CACHE[key]
    sess = _depth_session()
    if sess is None:
        return None
    try:
        import cv2
        t0 = time.time()
        img = arr.astype(np.float32) / 255.0
        x = cv2.resize(img, (518, 518), interpolation=cv2.INTER_CUBIC)
        x = (x - [0.485, 0.456, 0.406]) / [0.229, 0.224, 0.225]
        x = x.transpose(2, 0, 1)[None].astype(np.float32)
        out = sess.run(None, {sess.get_inputs()[0].name: x})[0]
        d = out[0] if out.ndim == 3 else out[0, 0]
        d = (d - d.min()) / max(1e-6, float(d.max() - d.min()))
        d = cv2.resize(d.astype(np.float32), (arr.shape[1], arr.shape[0]),
                       interpolation=cv2.INTER_CUBIC)
        if key is not None:
            _DEPTH_CACHE[key] = d
        log.info("depth: %s in %.2fs", os.path.basename(str(key)) if key else "frame",
                 time.time() - t0)
        return d
    except Exception as exc:  # noqa: BLE001
        log.warning("depth inference failed (%s)", exc)
        return None


def _grain_plate():
    global _GRAIN_PLATE
    if _GRAIN_PLATE is None:
        rng = np.random.default_rng(7)
        _GRAIN_PLATE = rng.normal(
            0.0, DEPTH_GRAIN, (H, W, 1)).astype(np.float32)
    return _GRAIN_PLATE


def depth_scene_clip(image_path, start, end, motion, emph_rel=None,
                     xfade=None, face=None):
    """Treatment-v2 photo scene: depth parallax + anchored zoom + hand-held
    micro-drift + film grain, all in one cv2.remap per frame. Reuses the
    exact face framing (cover_fit_face/_FACE_ALL) and zoom curves
    (motion_scale_fn/SCENE_ZOOM) of classic scene_clip so every v6/r48
    face-safety law holds unchanged. None -> caller uses scene_clip."""
    if not DEPTH_PARALLAX or motion in ("panl", "panr",
                                        "pan_left", "pan_right"):
        return None                       # pans keep their classic travel
    try:
        import cv2
        from moviepy import VideoClip, vfx
        dur = max(end - start, 0.2)
        pil = Image.open(image_path)
        if pil.size[0] < 720:             # r40: low-res keeps gentle classic
            pil.close()
            return None
        if face is not None:
            fitted, face_pt = cover_fit_face(
                pil, W, H, face, all_faces=_FACE_ALL.get(image_path))
        else:
            fitted = cover_fit_headroom(pil, W, H)
            face_pt = (W / 2.0, H * 0.30)
        pil.close()
        frame = grade_frame(np.array(fitted.convert("RGB")))
        depth = depth_for_frame(frame, key=image_path)
        if depth is None:
            return None
        if motion in ("punch_hit", "punch_build", "zoom_out"):
            scale_fn = motion_scale_fn(motion, dur, emph_rel)
        elif motion == "out":
            def scale_fn(t, d=dur):
                return max(1.001, 1.0 + SCENE_ZOOM - SCENE_ZOOM * (t / d))
        else:
            def scale_fn(t, d=dur):
                return max(1.001, 1.0 + SCENE_ZOOM * (t / d))
        yy, xx = np.indices((H, W), dtype=np.float32)
        fx = float(face_pt[0]) if face_pt else W / 2.0
        fy = float(face_pt[1]) if face_pt else H / 2.0
        # center depth on the anchor's plane so the face/subject stays put
        # and the world moves around it (parallax never shifts the eyeline)
        d0 = float(depth[min(H - 1, int(fy)), min(W - 1, int(fx))])
        dc = depth - d0
        grain = _grain_plate()
        rng = np.random.default_rng(abs(hash(image_path)) % (2 ** 32))
        j = rng.uniform(0, 2 * np.pi, 4)
        phase0 = rng.uniform(0, 2 * np.pi)

        def mk(t):
            s = float(scale_fn(t))
            ph = phase0 + 2 * np.pi * (t / DEPTH_DRIFT_PERIOD)
            dx = DEPTH_AMP_X * np.sin(ph)
            dy = DEPTH_AMP_Y * np.cos(ph * 0.7)
            jx = 1.3 * np.sin(t * 2.1 + j[0]) + 0.7 * np.sin(t * 5.3 + j[1])
            jy = 1.1 * np.sin(t * 1.7 + j[2]) + 0.6 * np.sin(t * 4.3 + j[3])
            # run-229 lesson: dx/dy/jx are float64 scalars, and float32 array
            # + float64 scalar promotes the whole map to float64 — which
            # cv2.remap REJECTS (needs CV_32FC1). Cast at the boundary.
            map_x = ((xx - fx) / s + fx + dc * dx + jx).astype(
                np.float32, copy=False)
            map_y = ((yy - fy) / s + fy + dc * dy + jy).astype(
                np.float32, copy=False)
            out = cv2.remap(frame, map_x, map_y, cv2.INTER_LINEAR,
                            borderMode=cv2.BORDER_REFLECT)
            g = np.roll(grain, (int(t * FPS) * 97) % H, axis=0)
            return np.clip(out.astype(np.float32) + g, 0, 255).astype(np.uint8)

        clip = VideoClip(mk, duration=dur).with_start(start)
        xf = XFADE if xfade is None else xfade
        if xf > 0 and start > 0:
            try:
                clip = clip.with_effects([vfx.CrossFadeIn(min(xf, dur / 2))])
            except Exception:  # noqa: BLE001
                pass
        log.info("DEPTH SCENE %s motion=%s dur=%.1fs",
                 os.path.basename(image_path), motion, dur)
        return clip
    except Exception as exc:  # noqa: BLE001
        log.warning("depth scene failed (%s) — classic motion", exc)
        return None


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
    # r40: a sub-720px source is already upscaled ~1.5x+ to fill the phone; a
    # punch zoom on top of that is what produced the blurry orange opener.
    # Low-res sources keep gentle motion only.
    if src_w < 720 and motion in ("punch_hit", "punch_build", "zoom_out"):
        motion = "in"

    if motion in ("panl", "panr") and not portrait:
        bw = int(W * PAN_SCALE)
        base = ImageClip(grade_frame(np.array(
            cover_fit_headroom(pil, bw, H)))     # r31: keep heads in the pan
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
                # r48: hand the crop EVERY face found in this image so the frame
                # edge cannot land in the middle of a secondary face.
                fitted, face_pt = cover_fit_face(
                    pil, W, H, face, all_faces=_FACE_ALL.get(image_path))
            except Exception as exc:  # noqa: BLE001
                log.warning("face framing failed (%s); center crop", exc)
                fitted, face_pt = cover_fit(pil, W, H), None
        else:
            # r31: no face detected -> headroom crop, and anchor the push-in on
            # the upper third anyway. A center-anchored zoom walks whatever is
            # up there (the head we failed to detect) further off the top.
            fitted = cover_fit_headroom(pil, W, H)
            face_pt = (W / 2.0, H * 0.30)
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


def contain_scene_clip(image_path, start, end, xfade=None, card=False,
                       punch=False):
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
    # r135b PUNCH-IN CONTINUATION: the hold-cap split re-cuts the SAME card
    # as a second scene. It must read as a new shot without ever cutting
    # text (containment law): the drift reverses direction and the push-in
    # starts 55% into its travel — so it opens visibly tighter than the
    # previous scene ended, yet still ENDS at the legal 0.94*W.
    z0 = 0.55 if punch else 0.0
    drift_px = -drift if punch else drift   # canvas oversize stays positive
    pil = Image.open(image_path).convert("RGB")

    canvas_w = W + drift                       # oversize -> room to drift
    bg = cover_fit(pil, canvas_w, H).filter(ImageFilter.GaussianBlur(32))
    bg = ImageEnhance.Brightness(bg).enhance(0.45)

    w, h = pil.size
    # r30 CONTAINMENT LAW: the r25 push-in scales the canvas by (1 + CARD_ZOOM)
    # while the drift walks it left, so a card sized at 0.94*W at t=0 is
    # 0.94*W*1.07 = 1086px wide by the end — WIDER than the 1080 frame. Every
    # card scene therefore lost up to ~27px off its left edge in its final
    # third (cut text = judge criterion a, an automatic hard fail). Size the
    # card so the push-in ENDS at 0.94*W instead of starting there: it now
    # grows from 88% to 94% and never leaves the frame at any t.
    fit_w = (W * 0.94) / (1.0 + CARD_ZOOM)
    # r68 SMALL-PICTURE FIX (owner saw it immediately): a wide press photo shown
    # whole filled barely a third of the phone — sharp, but a postage stamp on a
    # blurred field. Two causes: the width budget above is shrunk twice (0.94 and
    # again by the card-zoom headroom), and min() then picks the SMALLER
    # constraint. Cards genuinely need that headroom because they push in and
    # must stay readable; PHOTOS do not. So photos get nearly the full width and
    # may scale up to the sharpness ceiling (COVER_MAX_UPSCALE) instead of
    # stopping at native size. Beyond that ceiling it would be mush again, which
    # is the bug this whole contain path exists to avoid.
    if not card:
        fit_w = W * 0.98
    if card:
        # v9/r25: card scenes anchor toward the top so the caption band below
        # stays clear — top at CARD_TOP_Y, bottom capped at CARD_MAX_BOTTOM.
        # r25: 0.80 -> 0.94 width so a real proof (screenshot / X post) fills
        # the phone instead of sitting small and letterboxed.
        scale = min(fit_w / w, float(CARD_MAX_BOTTOM - CARD_TOP_Y) / h)
    else:
        scale = min(fit_w / w, (H * 0.94) / h)
        # Grow toward filling the frame, but never past the sharpness ceiling.
        want = min(max(W / float(w), (H * 0.94) / float(h)), COVER_MAX_UPSCALE)
        if want > scale:
            scale = min(want, fit_w / w * COVER_MAX_UPSCALE)
    fg = pil.resize((max(1, int(w * scale)), max(1, int(h * scale))),
                    Image.Resampling.LANCZOS)
    canvas = bg.copy()
    if card:
        # r31: pinning a SHORT card to CARD_TOP_Y leaves the rest of the phone
        # as dead blur (the delivered r30c cards floated in a black void).
        # Centre it in the card band instead; a full-height card is unchanged
        # because there is no slack to centre in.
        band = float(CARD_MAX_BOTTOM - CARD_TOP_Y)
        fg_y = int(CARD_TOP_Y + max(0.0, (band - fg.height) / 2.0))
    else:
        fg_y = (H - fg.height) // 2
    canvas.paste(fg, ((canvas_w - fg.width) // 2, fg_y))
    pil.close()

    # r25 motion-lite: cards were nearly frozen (2% drift, no zoom) — the owner
    # paused on exactly these and saw dead frames. Give them a gentle push-in
    # (still fully readable — the whole card stays in frame, just grows) plus a
    # slow horizontal drift. Centered while zooming so no bars/edges show.
    cw, ch = float(canvas_w), float(H)

    # TREATMENT V2: TWO-LAYER card parallax — the panel travels the full
    # drift+zoom while the blurred backdrop travels ~35% of it at half the
    # zoom, so the card visibly floats IN FRONT of its background (the 2.5D
    # separation cue) with ZERO warping of the text pixels (warping text =
    # cut-letter risk, and screenshots are depth-flat anyway so the depth
    # model would add nothing). Panel geometry replicates the flat path's
    # canvas transform exactly, so every r30/r31 containment law holds.
    # Any failure -> the flat single-canvas path below, unchanged.
    if DEPTH_PARALLAX:
        try:
            bg_clip = ImageClip(grade_frame(np.array(bg))).with_duration(dur)
            fg_clip = ImageClip(grade_frame(np.array(fg))).with_duration(dur)
            cx_p = (canvas_w - fg.width) / 2.0
            fy_p = float(fg_y)

            def _bpos(t, d=dur, px=float(drift_px)):
                sb = 1.0 + CARD_ZOOM * 0.5 * (z0 + (1 - z0) * t / d)
                return ((W - cw * sb) / 2.0 + 0.35 * px * (0.5 - t / d),
                        (H - ch * sb) / 2.0)

            def _bscale(t, d=dur):
                return 1.0 + CARD_ZOOM * 0.5 * (z0 + (1 - z0) * t / d)

            def _fpos(t, d=dur, px=float(drift_px)):
                s = 1.0 + CARD_ZOOM * (z0 + (1 - z0) * t / d)
                return ((W - cw * s) / 2.0 + px * (0.5 - t / d) + cx_p * s,
                        (H - ch * s) / 2.0 + fy_p * s)

            def _fscale(t, d=dur):
                return 1.0 + CARD_ZOOM * (z0 + (1 - z0) * t / d)

            pil.close()
            clip = CompositeVideoClip(
                [bg_clip.resized(_bscale).with_position(_bpos),
                 fg_clip.resized(_fscale).with_position(_fpos)],
                size=(W, H)).with_duration(dur)
            clip = clip.with_start(start)
            if xfade > 0 and start > 0:
                try:
                    clip = clip.with_effects(
                        [vfx.CrossFadeIn(min(xfade, dur / 2))])
                except Exception as exc:  # noqa: BLE001
                    log.warning("crossfade unavailable (%s); hard cut", exc)
            return clip
        except Exception as exc:  # noqa: BLE001
            log.warning("card parallax failed (%s); flat card path", exc)

    base = ImageClip(grade_frame(np.array(canvas))).with_duration(dur)

    def _cscale(t, d=dur):
        return 1.0 + CARD_ZOOM * (z0 + (1 - z0) * t / d)

    def _pos(t, d=dur, cw=cw, ch=ch, px=float(drift_px)):
        s = 1.0 + CARD_ZOOM * (z0 + (1 - z0) * t / d)
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
                     xfade=None, t_off=None, freeze_after=None):
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

    # FREEZE-FRAME EDIT (owner-directed, 2026-08-06): the clip PLAYS its
    # moment (~2.5s of real motion), then FREEZES on that frame while the
    # narration keeps talking over the held still — the editor move he
    # described ("small clip from the tiktok, then stop and continue").
    # The frozen frame gets a slow push so it never reads as a dead frame.
    # Any failure keeps the plain full-length clip.
    # r105: FREEZE ONLY WHEN THERE IS NOTHING LEFT TO PLAY. The freeze edit was
    # asked for as "play the moment, then stop and continue" — but it fired on
    # EVERY clip beat over 4 seconds, so a 17-second TikTok played 2.5s and then
    # sat frozen for 5. Four beats of the AI-actress video did exactly that, and
    # the judge correctly reported "a static portrait instead of video footage"
    # on every one. When the source has enough footage to cover the beat, play
    # it; hold the frame only to cover a clip that runs out.
    _src_len = float(getattr(src, "duration", 0) or 0)
    _covers_beat = _src_len >= (dur - 0.25)
    if _covers_beat and freeze_after:
        log.info("FREEZE skipped: the clip runs %.1fs and the beat needs "
                 "%.1fs — playing it through", _src_len, dur)
        freeze_after = None
    if freeze_after and dur > float(freeze_after) + 1.0:
        try:
            from moviepy import ImageClip as _FreezeIC
            fz = float(freeze_after)
            live = clip.subclipped(0, fz)
            frame = clip.get_frame(max(0.0, fz - 0.04))
            hold = dur - fz
            frozen = (_FreezeIC(frame).with_duration(hold)
                      .with_start(fz)
                      .resized(lambda t, h=hold: 1.0 + 0.05 * (t / h))
                      .with_position("center"))
            clip = CompositeVideoClip([live, frozen],
                                      size=(W, H)).with_duration(dur)
            log.info("FREEZE FRAME: clip plays %.1fs then holds %.1fs", fz,
                     hold)
        except Exception as exc:  # noqa: BLE001
            log.warning("freeze-frame unavailable (%s); full clip", exc)

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


def _wrap_text(text, font_path, size, max_w, stroke=0):
    """Greedy word-wrap to fit `max_w` at `size`, returning newline-joined text.

    r57: `stroke` matters. font.getbbox measures the LETTERS ONLY, but the hook
    is drawn with a black outline that adds `stroke` px on EACH side. Wrapping
    to the full width therefore produced lines that render 2*stroke wider than
    they were measured to be — which is half of why the last word came out
    sliced. Reserve the outline before fitting."""
    from PIL import ImageFont

    try:
        font = ImageFont.truetype(font_path, size)
    except Exception:  # noqa: BLE001
        return text
    fit_w = max(1, max_w - 2 * stroke)
    lines, cur = [], ""
    for word in text.split():
        cand = f"{cur} {word}".strip()
        l, _, r, _ = font.getbbox(cand)
        if (r - l) <= fit_w or not cur:
            cur = cand
        else:
            lines.append(cur)
            cur = word
    if cur:
        lines.append(cur)
    return "\n".join(lines)


def _text_block_size(text, font_path, size, stroke, align="center"):
    """r57: measure a WRAPPED block exactly as it will be drawn.

    PIL's multiline_textbbox is the only measurement that accounts for BOTH the
    stroke outline and the interline spacing. MoviePy's method="label" sizes its
    canvas from glyph bounds alone, so the outline overflowed the canvas and was
    clipped at the edge — the reported "HAPPENED cut in half". Returns (w, h);
    (0, 0) on any failure so the caller can keep the old behaviour."""
    try:
        from PIL import Image, ImageDraw, ImageFont
        font = ImageFont.truetype(font_path, size)
        draw = ImageDraw.Draw(Image.new("RGB", (10, 10)))
        l, t, r, b = draw.multiline_textbbox((0, 0), text, font=font,
                                             stroke_width=stroke, align=align)
        return int(r - l), int(b - t)
    except Exception:  # noqa: BLE001
        return 0, 0


def date_chip_clip(date_label, start, end, font_path):
    """TIMELINE CONTRACT date chip: a small rounded badge ("JUN 25") that
    slides in from the left at its beat's start and holds — the visual
    grammar of the timeline format (every receipt is date-stamped on
    screen). PIL-only, top-left inside the phone-safe zone, below the UI
    band and above the card band. None on any failure — never fatal."""
    try:
        from moviepy import ImageClip, vfx
        from PIL import ImageDraw, ImageFont

        label = str(date_label).strip().upper()
        if not label:
            return None
        size = 46
        font = ImageFont.truetype(font_path, size)
        measurer = ImageDraw.Draw(Image.new("RGB", (8, 8)))
        tw = int(measurer.textlength(label, font=font))
        pad_x, pad_y, bar = 26, 14, 8
        w = tw + pad_x * 2 + bar
        h = size + pad_y * 2
        img = Image.new("RGBA", (w + 8, h + 8), (0, 0, 0, 0))
        d = ImageDraw.Draw(img)
        d.rounded_rectangle([4, 4, w + 4, h + 4], radius=12,
                            fill=(12, 12, 14, 230))
        d.rectangle([4, 4, 4 + bar, h + 4], fill=ACCENT)   # accent spine
        d.text((4 + bar + pad_x, 4 + pad_y - 4), label, font=font,
               fill="#FFFFFF")
        arr = np.array(img)

        ic = ImageClip(arr, transparent=True).with_start(start).with_end(end)
        # r94: was exactly 250 — the Reels top zone boundary, so the chip's
        # first row sat on the edge of their UI. A little clearance costs
        # nothing and the chip is the one thing on screen that must be read.
        y = float(SAFE_TOP + 20)

        clip_w = w + 8                    # the rendered image's real width

        def _pos(t, w=w, y=y, cw=clip_w):
            k = 1.0 - (1.0 - min(1.0, t / 0.25)) ** 3   # ease-out cubic
            x = 40 - (w + 52) * (1.0 - k)
            # r82e: THE CRASH. On its first frame this chip sat COMPLETELY
            # off-screen (x = -(w+12)), and a fully-outside clip makes
            # moviepy's compose_mask build a zero-width slice — the
            # "shapes (82,0) (82,1076)" encode death; 82 is this chip's
            # exact height. It only started biting when the coat-to-cloth
            # cut put a dated event on the FIRST beat. Keep 1px on screen
            # at all times: the slide looks identical, the encode lives.
            # (Whole pixels too — fractional offsets are their own trap.)
            return (int(round(max(1 - cw, x))), int(y))

        ic = ic.with_position(_pos)
        try:
            ic = ic.with_effects([vfx.CrossFadeIn(0.12)])
        except Exception:  # noqa: BLE001
            pass
        return ic
    except Exception as exc:  # noqa: BLE001
        log.warning("date chip failed (%s); no chip", exc)
        return None


def hook_clip(text, start, end, font_path):
    """The oversized HOOK card over the first ~2s (kept from v1): TextClip with
    pre-wrapped text, slide-up + CrossFadeIn."""
    from moviepy import TextClip, vfx

    text = text.strip()
    if not text:
        return None
    # r57 HOOK SLICING FIX (owner: "HAPPENED cut in half"). Two compounding
    # bugs, both about the black outline:
    #   1. the wrap fitted lines to the full width measuring LETTERS ONLY, so a
    #      full line rendered 2*stroke wider than it was measured to be;
    #   2. method="label" sizes its canvas from glyph bounds WITHOUT the stroke,
    #      so that overflow was clipped flat at the canvas edge.
    # Reserve the stroke when wrapping, measure the real block with PIL
    # (multiline_textbbox is stroke- and interline-aware), then hand moviepy an
    # explicitly padded canvas via method="caption" so nothing can be cropped.
    stroke = max(4, int(HOOK_FONT * 0.06))
    render_text = _wrap_text(text, font_path, HOOK_FONT, int(W * 0.86),
                             stroke=stroke)
    tw, th = _text_block_size(render_text, font_path, HOOK_FONT, stroke)
    kwargs = {}
    if tw > 0 and th > 0:
        pad = stroke * 2 + 8
        kwargs = {"method": "caption",
                  "size": (min(W, tw + 2 * pad), th + 2 * pad)}
    else:                      # measurement unavailable -> exact old behaviour
        kwargs = {"method": "label"}
    dur = max(end - start, 0.05)
    base_y = H * 0.34

    # TREATMENT V2 kinetic hook: multi-line hooks build LINE BY LINE — each
    # line lands 100ms after the previous with its own slide-up + fade (the
    # staggered title-card entrance every produced short uses). Single-line
    # hooks and any failure keep the exact pre-v2 single-clip path below.
    lines = [ln for ln in render_text.split("\n") if ln.strip()]
    if DEPTH_PARALLAX and len(lines) > 1 and dur > 0.6:
        try:
            clips = []
            y_cursor = base_y
            for i, ln in enumerate(lines):
                lw, lh = _text_block_size(ln, font_path, HOOK_FONT, stroke)
                pad = stroke * 2 + 8
                ltc = TextClip(
                    text=ln, font=font_path, font_size=HOOK_FONT,
                    color="#FFFFFF", stroke_color="#000000",
                    stroke_width=stroke, text_align="center",
                    method="caption",
                    size=(min(W, lw + 2 * pad), lh + 2 * pad))
                appear = start + min(i * 0.10, dur * 0.3)
                ltc = ltc.with_start(appear).with_end(start + dur)
                # r82c: WHOLE pixels only. A 1077px hook line centered at
                # x=1.5 hit moviepy compose_mask's fractional-offset bug —
                # the deterministic "shapes (82,0) (82,1077)" encode crash
                # (the caption clamp was innocent; same formula, different
                # overlay). Every animated position below rounds too.
                lx = float(int((W - ltc.w) // 2))
                ly = y_cursor

                def _lpos(t, lx=lx, ly=ly):
                    dy = -26.0 * max(0.0, 1.0 - (t / 0.18))
                    return (int(lx), int(round(ly + dy)))

                ltc = ltc.with_position(_lpos)
                fade = min(0.10, dur / 2.0)
                if fade > 0:
                    ltc = ltc.with_effects([vfx.CrossFadeIn(fade)])
                clips.append(ltc)
                y_cursor += lh + 2 * pad - stroke   # stack, stroke overlap
            return clips
        except Exception as exc:  # noqa: BLE001
            log.warning("kinetic hook failed (%s); single-clip hook", exc)

    tc = TextClip(
        text=render_text, font=font_path, font_size=HOOK_FONT, color="#FFFFFF",
        stroke_color="#000000", stroke_width=stroke,
        text_align="center", **kwargs
    )
    tc = tc.with_start(start).with_end(start + dur)
    x_center = float(int((W - tc.w) // 2))   # r82c: whole pixels

    def _pos(t):
        dy = -20.0 * max(0.0, 1.0 - (t / 0.14))   # slide up over first 0.14s
        return (int(x_center), int(round(base_y + dy)))

    tc = tc.with_position(_pos)
    fade = min(0.08, dur / 2.0)
    if fade > 0:
        tc = tc.with_effects([vfx.CrossFadeIn(fade)])
    return tc


# r135: words that must never END a caption chunk — a chunk ending "...AT
# NO." strands its object in the next pop and reads as gibberish on mute
# (owner watch session: "100 AT NO.", "A PAUSE ON", "STREAMER OF THE").
_CHUNK_GLUE = {
    "a", "an", "the", "to", "of", "at", "on", "in", "and", "or", "nor",
    "but", "for", "with", "per", "by", "from", "as", "into", "over",
    "under", "vs", "no", "is", "was", "are", "were", "be", "been", "being",
    "has", "have", "had", "its", "his", "her", "their", "our", "your",
    "my", "that", "this", "these", "those", "than", "so", "if", "while",
    "after", "before", "about", "against", "between", "during", "not",
}


def _chunk_words(beat_words):
    """Split one beat's words into caption chunks of 2-4 words that end on
    PHRASE boundaries (r135): break early at punctuation, never break right
    after a glue word (a/the/at/of/No. ...). The old fixed 2-3 word window
    cut wherever the count landed, so half the pops were mid-phrase
    fragments — and most feed viewers watch muted and READ the captions."""
    hard_max = CHUNK_MAX_WORDS + 1     # one word of slack to finish a phrase

    def _bare(w):
        return w.strip().strip('"\'“”').rstrip(".,!?;:").lower()

    def _punct_end(w):
        w = w.strip().strip('"\'“”')
        # "No.", "vs." etc. are abbreviations, not sentence ends.
        return bool(w) and w[-1] in ".,!?;:" and _bare(w) not in _CHUNK_GLUE

    chunks, cur = [], []
    for wt in beat_words:
        cur.append(wt)
        w = wt[0]
        if len(cur) >= 2 and _punct_end(w):
            chunks.append(cur)
            cur = []
        elif len(cur) >= CHUNK_MAX_WORDS and (
                _bare(w) not in _CHUNK_GLUE or len(cur) >= hard_max):
            chunks.append(cur)
            cur = []
    if cur:
        if len(cur) == 1 and chunks:   # a lone trailing pop reads as a stray
            chunks[-1].extend(cur)
        else:
            chunks.append(cur)
    return chunks


def render_chunk_frame(words, hot_idx, font_path, hot_boost=1.0):
    """Render one caption state as an RGBA array: the whole 2-3 word chunk on
    one line, every word white with a black stroke EXCEPT the currently spoken
    word which is slightly larger and in the brand accent. Baselines aligned.
    TREATMENT V2 hot_boost: extra multiplier on the hot word only — the
    kinetic pop-in renders a brief overshoot state (boost>1) that settles to
    the normal state, so the spoken word lands with a punch."""
    from PIL import ImageDraw, ImageFont

    words = [w.upper() for w in words]
    max_w = int(W * 0.88)
    measurer = ImageDraw.Draw(Image.new("RGB", (8, 8)))

    scale = 1.0
    fonts, widths, gap, total = [], [], 0, 0
    for _ in range(4):
        sizes = [
            max(20, int(round(CHUNK_FONT * scale
                              * (HOT_SCALE * hot_boost if i == hot_idx
                                 else 1.0))))
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
            mid = (st + en) / 2.0
            y_center = CAPTION_CENTER_Y
            for cw_s, cw_e in (card_windows or []):
                if cw_s <= mid < cw_e:      # v9: this word plays over a card
                    y_center = CARD_CAPTION_Y
                    break
            # TREATMENT V2 kinetic pop: the spoken word lands as a brief
            # OVERSHOOT state (hot word at 1.22x its accent size for the
            # first 90ms) then settles to the normal accent state — the
            # word-by-word "punch" every produced short uses. States too
            # short to carry both keep the single-state (pre-v2) render.
            states = [(st, en, 1.0)]
            if DEPTH_PARALLAX and (en - st) >= 0.18:
                pop_end = min(st + 0.09, mid)
                states = [(st, pop_end, 1.22), (pop_end, en, 1.0)]
            for s_st, s_en, boost in states:
                try:
                    arr = render_chunk_frame(chunk_words, k, font_path,
                                             hot_boost=boost)
                except Exception as exc:  # noqa: BLE001
                    log.warning("caption render failed (%s); skipped state",
                                exc)
                    continue
                # r82d: a caption strip is COMPOSITED ONTO A FULL FRAME here,
                # then placed at (0,0). Three takes proved the partial-overlap
                # path is the problem, not the numbers going into it: a strip
                # touching the frame edge makes moviepy's compose_mask produce
                # a zero-width slice and the whole encode dies
                # ("shapes (82,0) (82,1077)"). Clamping the width moved the
                # number (1077 -> 1076) and crashed identically; rounding the
                # position did too. A frame-sized overlay never overlaps
                # partially, so that code path is never entered. Cost is one
                # paste per caption state; correctness is absolute.
                if arr.shape[1] > W - SAFE_X * 2:
                    _sc = (W - SAFE_X * 2) / float(arr.shape[1])
                    _im = Image.fromarray(arr)
                    arr = np.asarray(_im.resize(
                        (W - SAFE_X * 2, max(1, int(round(arr.shape[0] * _sc)))),
                        Image.Resampling.LANCZOS))
                _cx = int((W - arr.shape[1]) // 2)
                _cy = int(round(y_center - arr.shape[0] / 2.0))
                _cy = max(0, min(H - arr.shape[0], _cy))
                _canvas = np.zeros((H, W, 4), dtype=np.uint8)
                _rgba = arr if arr.shape[2] == 4 else np.dstack(
                    [arr, np.full(arr.shape[:2], 255, np.uint8)])
                _canvas[_cy:_cy + _rgba.shape[0], _cx:_cx + _rgba.shape[1]] = _rgba
                ic = ImageClip(_canvas, transparent=True)
                ic = ic.with_start(s_st).with_end(s_en).with_position((0, 0))
                clips.append(ic)
    return clips


# ============================================================================
# Background music (optional, deterministic, non-fatal)
# ============================================================================
def pick_bgm(page_id, grave=False):
    # r113 SIGNATURE BED. Until now the STYLE owned the bed, and the style is
    # rolled per video (r65 A/B test) — so the channel played three different
    # musics and sounded like three different channels. That is backwards from
    # how the job is actually done: the bed is a CHANNEL decision made once
    # (viewers should know it is us within two seconds), and the STORY only
    # decides the effects — the turn, the build, the reveal.
    #
    # One bed, every video: bgm_2 "Fright Night" (film-score tension) — our
    # whole format is a tension timeline, and a score sits under a voice
    # without fighting it the way the trap bed did.
    #
    # Two exceptions survive, both deliberate:
    #   - grave stories (death, assault) fall through to the ambient bed below;
    #     a tension score under a death story is the wrong sound.
    #   - VIDEO_BGM_FILE overrides everything, so the signature can be changed
    #     in one place without a code edit.
    if not grave:
        _p = os.environ.get("VIDEO_BGM_FILE", "").strip() \
            or os.path.join(BGM_DIR, f"bgm_{SIGNATURE_BGM}.mp3")
        if os.path.exists(_p):
            log.info("bgm: SIGNATURE bed %s (same on every video, by design)",
                     os.path.basename(_p))
            return _p
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


def _apply_swells(bed, scenes, total_ms):
    """r113 SWELL: the bed RISES into the reveal instead of sitting flat.

    Until now the bed had exactly three volumes (normal / ducked / gone), so
    the biggest moment in the video sounded the same as the setup. Editors do
    the opposite: the music grows under the build and is at its loudest the
    instant the reveal lands, then gets out of the way again.

    Where the build is, is already known — the Director marks it. The shot it
    puts a `riser` on IS the build, and the shot after it is the reveal. So:

        riser shot  ..............  reveal shot
        |<-- bed ramps 0 -> +5dB -->|<-- decays +5 -> 0 over ~1.8s

    Shaped BEFORE the bed is cut into duck/silence intervals, so a swell and
    a duck can coexist and a `silence` reveal still means silence.
    """
    if not SWELL_DB or bed is None:
        return bed, 0
    done = 0
    for i, sc in enumerate(scenes):
        if done >= SWELL_MAX or (sc.get("sfx") or "none") != "riser":
            continue
        if i + 1 >= len(scenes):
            continue                       # no reveal to rise into
        nxt = scenes[i + 1]
        if (nxt.get("music") or "bed") == "silence":
            continue                       # the reveal is a music DROP; leave it
        a = int(float(sc["start"]) * 1000)
        peak = int(float(nxt["start"]) * 1000)
        if peak - a < SWELL_MIN_MS or peak >= total_ms:
            continue                       # too short to read as a build
        decay = min(SWELL_DECAY_MS, total_ms - peak)
        if decay < 200:
            continue
        try:
            bed = bed.fade(from_gain=0.0, to_gain=SWELL_DB,
                           start=a, duration=peak - a)
            bed = bed.fade(from_gain=SWELL_DB, to_gain=0.0,
                           start=peak, duration=decay)
        except Exception as exc:           # noqa: BLE001
            log.info("swell skipped (%s)", str(exc)[:70])
            continue
        log.info("sound: SWELL +%.0fdB over %.1fs into the reveal at %.1fs, "
                 "decaying %.1fs", SWELL_DB, (peak - a) / 1000.0,
                 peak / 1000.0, decay / 1000.0)
        done += 1
    return bed, done


def _duck_to_vo(bed, vo):
    """r137: ride the music bed against the voice (Q3 contract). Returns
    (bed', speech_fraction). Pure function: numpy envelope from per-window
    VO RMS, one attack/release ramp, sample-accurate multiply — no ffmpeg
    filter chains, no compressor artifacts, deterministic for the same
    inputs. Beyond the voice's end the envelope fully opens (outro bed)."""
    import array

    def _mono_samples(seg):
        if seg.channels == 1:
            raw = seg.get_array_of_samples()
        else:  # average channel pairs (set_channels(1) resamples; we just mix)
            raw = seg.get_array_of_samples()
            raw = array.array(raw.typecode,
                              ((raw[i] + raw[i + 1]) // 2
                               for i in range(0, len(raw) - 1, 2)))
        return np.asarray(raw, dtype=np.float64)

    win_ms = max(1, int(DUCK_WINDOW_MS))
    win_n = max(1, int(vo.frame_rate * win_ms / 1000))  # samples per window
    vo_ms = min(len(bed), len(vo))          # duck only where a voice exists
    vo_samples = int(vo_ms * vo.frame_rate / 1000)
    n_win = vo_samples // win_n
    if n_win < 4:                            # under four windows = no speech
        return bed, 0.0
    s = _mono_samples(vo[:vo_ms])[:vo_samples]
    win_rms = np.sqrt(
        np.square(s[: n_win * win_n].reshape(n_win, win_n)).mean(axis=1))
    peak = float(win_rms.max()) if win_rms.size else 0.0
    if peak <= 0:
        return bed, 0.0
    speech_thr = max(10.0 ** (DUCK_SPEECH_FLOOR_DBFS / 20.0),
                     peak * 10.0 ** (-DUCK_SPEECH_VS_PEAK_DB / 20.0))
    is_speech = win_rms > speech_thr

    # one pass of attack/release on the gain in dB (fast dip, slow recover —
    # the bed falls out of the way the moment the voice starts and creeps
    # back during pauses rather than popping)
    att = DUCK_DB * win_ms / max(1, DUCK_ATTACK_MS)
    rel = DUCK_DB * win_ms / max(1, DUCK_RELEASE_MS)
    db_env = np.empty(n_win)
    g = 0.0
    for i, sp in enumerate(is_speech):
        target = -DUCK_DB if sp else 0.0
        step = att if target < g else rel
        g += max(-step, min(step, target - g))
        db_env[i] = g

    # window envelope -> per-sample linear gain, applied to every bed channel
    env = np.ones(vo_samples)
    env[: n_win * win_n] = np.repeat(np.power(10.0, db_env / 20.0), win_n)
    out = bed[:vo_ms]
    raw = out.get_array_of_samples()
    vals = np.asarray(raw, dtype=np.float64)
    if out.channels > 1:
        vals = vals.reshape(-1, out.channels) * env[:, None]
    else:
        vals = vals * env
    vals = np.clip(vals, -(2 ** (8 * out.sample_width - 1)),
                   2 ** (8 * out.sample_width - 1) - 1)
    raw = array.array(raw.typecode,
                      vals.reshape(-1).astype(raw.typecode).tolist())
    from pydub import AudioSegment
    ducked = AudioSegment(data=raw.tobytes(), sample_width=out.sample_width,
                          frame_rate=out.frame_rate, channels=out.channels)
    if vo_ms < len(bed):                   # after the voice: bed plays open
        ducked = ducked.append(bed[vo_ms:], crossfade=0)
    return ducked, float(is_speech.mean())


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
            # r113: shape the bed (swell into the reveal) BEFORE it is cut into
            # duck/silence intervals, so both survive.
            bed, _swells = _apply_swells(bed, scenes, total_ms)
            # r137: then ride it against the voice (Q3). Duck BEFORE the
            # interval cut so a swell still opens fully in a real pause —
            # the cut only mutes 'silence' spans; the duck handles speech.
            if DUCK_ENABLE:
                bed, _sp_frac = _duck_to_vo(bed, vo)
                log.info("sound: vo-duck on (speech %.0f%% of bed span, "
                         "-%.0fdB, %d/%dms ramps)", _sp_frac * 100, DUCK_DB,
                         DUCK_ATTACK_MS, DUCK_RELEASE_MS)
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
            log.info("sound: bed %s over %d interval(s), %d swell(s)",
                     os.path.basename(bed_file), len(intervals), _swells)
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
    # r135b: the hold-cap split budgets its photo continuations against the
    # pool this planner will actually see (timeline mode strips yt thumbs).
    _pool_n = len({e.get("path") for e in (pool or []) if e.get("path")
                   and not (_TIMELINE_MODE[0]
                            and "ytimg.com/vi" in (e.get("url") or ""))})
    edl = build_edl(shotlist, script, word_timings, total, pool_n=_pool_n) \
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
        # CAROUSEL ARTIFACT FRAMES (2026-08-06, owner: carousel video beats
        # "must keep showing that video, not an img"): grab 2 real frames of
        # every timeline clip beat; post_video ships them and the server
        # joins them to the beat by event_id. Never fatal.
        del _CLIP_ARTIFACT_FRAMES[:]
        if _TIMELINE_MODE[0]:
            try:
                from moviepy import VideoFileClip as _VFrm
                for sc in scenes:
                    if (sc["type"] == "broll" and sc.get("event_id")
                            and sc.get("path")):
                        vsrc = _VFrm(sc["path"])
                        for j, tt in enumerate((1.0, 2.4)):
                            if vsrc.duration and tt < vsrc.duration - 0.1:
                                fr = Image.fromarray(
                                    vsrc.get_frame(tt).astype("uint8"))
                                fr.thumbnail((720, 1280))
                                fp = os.path.join(
                                    WORKDIR, f"clipframe-{page_id}-"
                                    f"{sc['event_id']}-{j}.jpg")
                                fr.save(fp, "JPEG", quality=88)
                                _CLIP_ARTIFACT_FRAMES.append(fp)
                        vsrc.close()
                if _CLIP_ARTIFACT_FRAMES:
                    log.info("carousel frames: %d real clip frame(s) staged",
                             len(_CLIP_ARTIFACT_FRAMES))
            except Exception as exc:  # noqa: BLE001
                log.warning("carousel frames failed (%s)", exc)
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
    # r33 VARIETY LAW, enforced BEFORE the selfcheck reads the scene list: no
    # single still may carry more than VISUAL_MAX_SHARE of the still scenes.
    try:
        _alts = [e["path"] for e in pool if e.get("path")]
        _singles = {e["path"] for e in pool
                    if e.get("path") and not e.get("textish")
                    and not e.get("person") and e.get("has_face") is False}
        enforce_visual_variety(scenes, _alts, single_cap_paths=_singles)
        _share = {}
        for _sc in scenes:
            if _sc.get("path") and _sc.get("type") != "broll":
                _share[_sc["path"]] = _share.get(_sc["path"], 0) + 1
        if _share:
            _top, _n = max(_share.items(), key=lambda kv: kv[1])
            _tot = sum(_share.values())
            log.info("VARIETY: %d distinct stills, top visual carries %d/%d "
                     "(%.0f%%)", len(_share), _n, _tot, 100.0 * _n / _tot)
            if _tot >= 6 and _n / float(_tot) > 0.55 and len(_alts) > 2:
                # alternatives existed and it STILL monopolised the video — a
                # planner bug, not a thin pool. Fail loudly instead of shipping
                # 20 seconds of one frame again.
                raise SelfCheckFailed(
                    "one visual carries %d/%d still scenes (%.0f%%) with %d "
                    "pool alternatives available: %s"
                    % (_n, _tot, 100.0 * _n / _tot, len(_alts),
                       os.path.basename(_top)))
    except SelfCheckFailed:
        raise
    except Exception as exc:  # noqa: BLE001 — variety must never crash a render
        log.warning("variety pass skipped (%s)", str(exc)[:90])

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
                t_off=sc.get("src_off"),   # r13: footage starts 2s in
                freeze_after=sc.get("freeze_after"))
            open_sources.append(src)     # must stay open until after encode
            layers.append(clip)
            scene_clips.append(clip)
        elif sc["textish"] or sc.get("contain"):
            clip = contain_scene_clip(sc["path"], sc["start"],
                                      sc["end"], xfade=xfade,
                                      card=(sc["type"] == "receipt"),
                                      punch=bool(sc.get("card_hold")))
            layers.append(clip)
            scene_clips.append(clip)
        else:
            # v6: face-aware phone framing on every photo scene (cached
            # detection; None -> the pre-v6 center crop, never a crash).
            # TREATMENT V2: depth parallax first; any failure -> classic.
            face_box = detect_face_box(sc["path"])
            clip = depth_scene_clip(sc["path"], sc["start"], sc["end"],
                                    sc["motion"],
                                    emph_rel=sc.get("emph_rel"),
                                    xfade=xfade, face=face_box)
            if clip is None:
                clip = scene_clip(sc["path"], sc["start"], sc["end"],
                                  sc["motion"],
                                  emph_rel=sc.get("emph_rel"),
                                  xfade=xfade, face=face_box)
            layers.append(clip)
            scene_clips.append(clip)

    # TIMELINE CONTRACT: date chips — one per dated beat, sliding in at the
    # beat's start. Above the scene image, below vignette/hook/captions.
    # r135: the HOLD CAP splits one beat into several contiguous scenes that
    # all carry the same date; adjacent same-date windows are merged here so
    # the chip slides in ONCE per beat instead of re-popping every segment.
    chip_windows = []
    for sc in scenes:
        if not sc.get("date"):
            continue
        if (chip_windows and chip_windows[-1][0] == sc["date"]
                and abs(chip_windows[-1][2] - sc["start"]) < 0.25):
            chip_windows[-1][2] = sc["end"]
        else:
            chip_windows.append([sc["date"], sc["start"], sc["end"]])
    for label, c_st, c_en in chip_windows:
        chip = date_chip_clip(label, c_st, c_en, font_path)
        if chip is not None:
            layers.append(chip)

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

    # r126 HOOK STUDY: the overlay held 3-4s while viewers left at 0:01. The
    # text is now 4-8 words (readable in ~1s per TikTok's own 5-10 words/sec
    # guidance), so it needs at most ~2.2s on screen — then it clears and the
    # opening clip carries the frame while the voice finishes the loop.
    hc = hook_clip(hook.upper(), 0.0, min(hook_end, HOOK_TEXT_MAX_S), font_path)
    if hc is not None:
        # treatment v2: the kinetic hook returns one clip PER LINE
        layers.extend(hc if isinstance(hc, list) else [hc])

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
        preset=os.environ.get("VIDEO_PRESET", "medium"),
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
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    except subprocess.TimeoutExpired:
        log.warning("faststart remux timed out; using raw output")
        os.replace(src, dst)
        return
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

ARTIFACT LAW (the hardest rule — judge like a human editor who researched this story): some frames below carry an EXPECTED ARTIFACT — the pipeline verified that the real artifact for that moment EXISTS and ordered it on screen. When the expectation says REAL VIDEO FOOTAGE, the frame must visibly be a frame OF THAT VIDEO (a real captured moment: interface, motion, environment) — a posed portrait or press photo of the person during that beat is an AUTOMATIC artifact_miss, even though it is the right person. The moment matters, not the face. When the expectation says a post/screenshot artifact, the actual post card or article capture must be the dominant visual. ONE artifact_miss fails the whole video.

FRAME WORDS (frame number: words spoken during that frame, plus the expected artifact when one exists):
{pairs}

Acceptable and NEVER a fail: minor blur, film grain, compression artifacts, darkened or blurred backgrounds, one intentional motion-blur transition frame, the styled captions themselves.
Judge ONLY the checklist above. Be strict: one weird frame fails the whole video; two or more clear said-vs-seen mismatches also fail it.

Respond with ONLY this JSON object, no markdown fences, no extra text:
{{"pass": true, "weird": [], "mismatches": [], "artifact_misses": [], "issues": [], "scores": {{"readability": 0, "framing": 0, "variety": 0, "edit_variety": 0, "completeness": 0}}}}
where pass is true/false (false whenever weird is non-empty OR mismatches has 2+ entries OR artifact_misses has ANY entry); weird is a list of {{"frame": <1-based frame number>, "issue": "<which checklist letter + short description>"}} covering EVERY checklist hit; mismatches is a list of {{"frame": <1-based frame number>, "words": "<the paired words>", "what_shown": "<short description of what the frame actually shows>"}} covering every CLEAR said-vs-seen mismatch (empty when none); artifact_misses is the same shape for every ARTIFACT LAW violation (an expected real artifact replaced by a mere portrait/filler); issues is a list of short overall problem descriptions (empty when passing); each score is an integer 0-10 (completeness = does the video SHOW the story's moments rather than talk over faces)."""


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
        try:
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        except subprocess.TimeoutExpired:
            log.warning("%s frame %d extraction timed out", prefix, i)
            continue
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

        def _artifact_at(t):
            """ARTIFACT LAW expectation for the shot under timestamp t —
            based on what the beat actually RESOLVED to (run #242: demanding
            a dead upstream artifact failed every honest fallback)."""
            for s in (edl or []):
                if s.get("start", 0) <= t < s.get("end", 0):
                    res = s.get("resolved")
                    # r106: when the beat RESOLVED to broll the real clip
                    # genuinely played — so say so as a FACT instead of asking
                    # the judge to prove it. A single still taken from a
                    # person talking to camera is indistinguishable from a
                    # portrait photo, and the judge cannot see motion, so the
                    # old wording made it flag four real TikToks as "a static
                    # portrait photo" on a video whose log shows all four
                    # playing full length. Asking for something unobservable
                    # produces confident wrong answers.
                    if res == "broll":
                        return (" | THIS FRAME IS A STILL TAKEN FROM THE REAL "
                                "VIDEO CLIP for this moment — the pipeline "
                                "played it. Do NOT call it a portrait or an "
                                "artifact_miss; a frame of someone talking on "
                                "camera looks exactly like a photo. Judge only "
                                "whether this footage BELONGS to these words")
                    # a clip was ordered and did NOT arrive: a photo stands in
                    # its place, and that IS the miss the law exists to catch
                    if res is None and s.get("clip_url"):
                        return (" | EXPECTED ARTIFACT: REAL VIDEO FOOTAGE of "
                                "this moment (a portrait photo here = "
                                "artifact_miss)")
                    if res == "receipt" or (res is None
                                            and s.get("shot_class")
                                            == "receipt"):
                        return (" | EXPECTED ARTIFACT: a real post card or "
                                "article screenshot from this story's "
                                "coverage (a same-story article whose "
                                "headline covers an ADJACENT fact is "
                                "acceptable, NOT a miss)")
                    break
            return ""

        pairs_txt = "\n".join(
            f'frame {i + 1}: "{_phrase_at(edl, t)[:160]}"{_artifact_at(t)}'
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
        text = vision_post(body, timeout=90, tag="JUDGE", strong_first=True)
        if not text:
            log.warning("judge unavailable on ALL rotation tiers; "
                        "delivering unjudged")
            return None
        if text.startswith("```"):       # belt-and-suspenders fence strip
            text = text.strip("`").strip()
            if text.lower().startswith("json"):
                text = text[4:].strip()
        try:
            verdict = json.loads(text)
        except Exception:  # noqa: BLE001 — run #241: a tier returned JSON
            verdict = json.loads(_json_slice(text))   # wrapped in prose
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
        # ARTIFACT LAW (2026-08-06, owner: "showing only their img at that
        # moment is not enough — the moment is what matters"): ONE verified
        # artifact replaced by a portrait/filler fails the video, enforced
        # deterministically regardless of the model's pass field.
        am = verdict.get("artifact_misses")
        am = [m for m in am if isinstance(m, dict)] if isinstance(am, list) \
            else []
        verdict["artifact_misses"] = am
        if am and verdict.get("pass") is True:
            verdict["pass"] = False
        log.info("vision judge: pass=%s weird=%s mismatches=%s "
                 "artifact_misses=%s scores=%s issues=%s",
                 verdict.get("pass"), verdict.get("weird"), mm, am,
                 verdict.get("scores"), verdict.get("issues"))
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
        # r37: the repo-staged feed (video-feed branch) FIRST — it needs no
        # network at all, so a blackholed runner IP cannot strand the job.
        feed_file = os.path.join(FEED_DIR, "feed.json") if FEED_DIR else ""
        if feed_file and os.path.isfile(feed_file):
            try:
                with open(feed_file, "rb") as f:
                    data = json.loads(f.read().decode("utf-8", "replace"))
                log.info("job feed from repo branch (generated %s)",
                         data.get("generated"))
            except Exception as exc:  # noqa: BLE001
                log.info("repo feed unreadable (%s); HTTP feed next",
                         str(exc)[:60])
                data = None
        for su in static_urls if data is None else []:
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
    if _CLIP_ARTIFACT_FRAMES:      # carousel: real frames of each clip beat
        cf = []
        for fp in _CLIP_ARTIFACT_FRAMES[:12]:
            try:
                with open(fp, "rb") as fh:
                    cf.append({"name": os.path.basename(fp),
                               "b64": base64.b64encode(fh.read())
                               .decode("ascii")})
            except Exception:  # noqa: BLE001
                pass
        if cf:
            body["clip_frames"] = cf
    log.info("delivering %s (%.1f MB as base64)", os.path.basename(mp4_path),
             len(b64) / 1024 / 1024)
    # r62: stage the drop-meta BEFORE the upload. Run #229 proved the r37
    # fallback had a hole: the watchdog killed a HUNG upload mid-attempt, so
    # the failure branch below never ran — the drop carried the mp4 but no
    # meta sidecar, and the bridge (correctly) refuses meta-less drops as
    # judge-rejects. Staging first means a kill at ANY point leaves a
    # complete, ingestable drop; confirmed success deletes the sidecar (and
    # the bridge's newer-video idempotency check backstops even that).
    meta_path = os.path.join(WORKDIR, f"drop-meta-{page_id}.json")
    try:
        with open(meta_path, "w", encoding="utf-8") as f:
            json.dump({"page_id": int(page_id), "slug": slug or "",
                       "mp4": os.path.basename(mp4_path),
                       "sheet": os.path.basename(sheet_path)
                                if sheet_path and os.path.isfile(sheet_path)
                                else None,
                       "report": dict(_RENDER_REPORT) if _RENDER_REPORT else None,
                       "reason": "staged-before-post"}, f)
    except Exception:  # noqa: BLE001
        pass
    # r107 THE UPLOAD STALL, FIXED AT THE ARITHMETIC. This loop was 4 attempts
    # x 2 engines x a 300s timeout plus backoff — up to 41 MINUTES — while the
    # watchdog kills the 'post' stage at 330 SECONDS. So whenever Hostinger's
    # WAF blackholed the runner (exactly when this hangs), the kill landed long
    # before the drop-branch fallback below could run: a finished, judge-passed
    # video was reported as a failed run and sat on the drop until someone
    # fetched it by hand. That is the recurring "upload problem".
    #
    # A delivery that is going to succeed connects in seconds; a blackholed one
    # never answers, so a long timeout buys nothing and costs everything. Two
    # attempts, short timeouts, brief backoff: worst case ~185s, comfortably
    # inside the 330s cap, so the fallback ALWAYS gets to run.
    _POST_TIMEOUT = int(os.environ.get("VIDEO_POST_TIMEOUT", "45"))
    last = None
    for attempt in range(1, 3):
        # engine 1: curl_cffi browser TLS (the pattern that dodges the WAF)
        try:
            from curl_cffi import requests as cffi
            r = cffi.post(RECEIVE_URL, json=body, impersonate="firefox",
                          timeout=_POST_TIMEOUT,
                          headers={"User-Agent": _BROWSER_UA})
            if r.status_code == 200 and r.json().get("ok"):
                log.info("posted video for page_id=%s", page_id)
                try:
                    os.remove(meta_path)   # r62: delivered — drop not needed
                except Exception:  # noqa: BLE001
                    pass
                return
            last = f"curl_cffi HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"curl_cffi: {e}"
        # engine 2: requests JSON
        try:
            r = requests.post(RECEIVE_URL, json=body, timeout=_POST_TIMEOUT,
                              headers={"User-Agent": _BROWSER_UA})
            ok = r.status_code == 200
            try:
                ok = ok and bool(r.json().get("ok", ok))
            except Exception:  # noqa: BLE001
                pass
            if ok:
                log.info("posted video for page_id=%s", page_id)
                try:
                    os.remove(meta_path)   # r62: delivered — drop not needed
                except Exception:  # noqa: BLE001
                    pass
                return
            last = f"requests HTTP {r.status_code} {r.text[:200]}"
        except Exception as e:  # noqa: BLE001
            last = f"requests: {e}"
        log.warning("post attempt %d/2 failed (%s); the drop branch is the "
                    "backstop, so this does not linger", attempt, last)
        time.sleep(5)
    # r37: a finished, judge-passed video must NEVER be discarded because this
    # runner drew a blackholed IP (run #154 rendered 18 minutes and binned it).
    # Leave the artifact + a meta sidecar in the workdir; the always-on drop
    # step pushes them to the video-drop branch and the server ingests from
    # there. The run stays green and the page is marked done.
    try:
        meta = {"page_id": int(page_id), "slug": slug or "",
                "mp4": os.path.basename(mp4_path),
                "sheet": os.path.basename(sheet_path)
                         if sheet_path and os.path.isfile(sheet_path) else None,
                "report": dict(_RENDER_REPORT) if _RENDER_REPORT else None,
                "reason": str(last)[:300]}
        mp = os.path.join(WORKDIR, f"drop-meta-{page_id}.json")
        with open(mp, "w", encoding="utf-8") as f:
            json.dump(meta, f)
        log.warning("HTTP delivery failed; artifact staged for the video-drop "
                    "branch (%s)", os.path.basename(mp))
        return
    except Exception:  # noqa: BLE001 — staging failed too: keep the old fatal
        raise RuntimeError(f"receive failed after retries: {last}")


# ============================================================================
# Main
# ============================================================================
def make_one(post, font_path):
    page_id = int(post["page_id"])
    slug = post.get("slug", "")
    # r135: emojis out BEFORE anything reads these — the caption font has no
    # emoji glyphs (page 259 shipped tofu boxes) and TTS can't speak them.
    hook = strip_emoji((post.get("hook") or "").strip())
    script = strip_emoji((post.get("script") or "").strip())
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
    apply_style(pick_style(page_id))   # r65: this video's A/B treatment
    _ACTIVE_VOICE[0] = pick_voice(page_id, grave)   # r132: per-video voice
    log.info("voice: %s%s", _ACTIVE_VOICE[0], " (grave register)" if grave else "")
    _set_stage("visuals", pid=page_id)
    # r28: this story's harvested platform clips (Twitch/TikTok/Kick/YouTube) —
    # the scene planner pulls these in as REAL MOVING footage matched to the
    # story, each fetched with its proper method (fetch_platform_clip).
    global _STORY_CLIPS, _STORY_CLIP_START, _STORY_CLIP_SRC
    _HOOK_CLIP[0] = None          # r57: per-story, not per-process
    _CLIP_FRAMES_DONE[0] = False
    _STORY_CLIPS = [c.get("url") for c in (post.get("clips") or [])
                    if isinstance(c, dict) and platform_of(c.get("url"))]
    # r45: carry the reporter's own timestamp for each embedded clip. Embedded
    # clips lead the feed list, so _STORY_CLIPS[0] is normally the money moment.
    _STORY_CLIP_START = {}
    _STORY_CLIP_SRC = set()
    for c in (post.get("clips") or []):
        if not isinstance(c, dict) or not c.get("url"):
            continue
        if int(c.get("start") or 0) > 0:
            _STORY_CLIP_START[c["url"]] = int(c["start"])
        if str(c.get("src") or "").startswith("http"):
            _STORY_CLIP_SRC.add(c["url"])
    if _STORY_CLIP_SRC:
        log.info("PROVENANCE: %d clip(s) were embedded by reporters in this "
                 "story's own articles; the footage gate cannot veto those",
                 len(_STORY_CLIP_SRC))
    # r70 CLIP HUNTER — the supply fix, measured: one clip yields 12-20 usable
    # frames of the actual event, an article yields ~1 photo (trafilatura found
    # ZERO images inside a real article body), and generic image search yields
    # robots. So when a story is short on footage, SEARCH for more using the
    # VISUAL DIRECTOR's event-shaped phrases — never a bare name, which is what
    # returned robots and gingerbread houses. Runs here, not on the server,
    # because Hostinger disables shell_exec. Fails closed: no plan, no search.
    _vp = post.get("visual_plan") or {}
    if (not _vp.get("skip")) and len(_STORY_CLIPS) < CLIP_HUNT_MIN:
        import shutil as _sh
        # r87 TOPICALITY GATE. YouTube answers EVERY query with something, so
        # a search that finds nothing real still returns two videos — and this
        # hunt used to accept them sight unseen (it did not even ask for the
        # title). Measured on the AI-actress story: of five director phrases,
        # one returned the actual video and the rest returned Amanda Bynes,
        # Paris Hilton and a courtroom clip, all of which would have been
        # downloaded as this story's footage. A result now has to carry a rare
        # word from the story in its title, the same test archive.org passes.
        _hunt_distinct = distinctive_words(
            post.get("title"),
            *[str(p.get("name") if isinstance(p, dict) else p or "")
              for p in (post.get("people") or [])])
        if _sh.which("yt-dlp") and _hunt_distinct:
            for _q in (_vp.get("clip_queries") or [])[:3]:
                if len(_STORY_CLIPS) >= CLIP_HUNT_MIN:
                    break
                try:
                    _r = subprocess.run(
                        ["yt-dlp", "--no-warnings", "--flat-playlist",
                         "--skip-download", "--print",
                         "%(id)s\t%(duration)s\t%(title)s",
                         f"ytsearch3:{_q}"],
                        capture_output=True, text=True, timeout=45)
                    for _ln in (_r.stdout or "").splitlines():
                        _pp = _ln.split("\t")
                        if len(_pp) < 1 or len(_pp[0]) < 6:
                            continue
                        try:
                            if int(float(_pp[1])) > 3600:
                                continue        # skip full streams
                        except (ValueError, IndexError):
                            pass
                        _ttl = _pp[2] if len(_pp) > 2 else ""
                        if not title_is_topical(_ttl, _hunt_distinct):
                            log.info("CLIP HUNT: dropped %r — no story word "
                                     "in the title", _ttl[:58])
                            continue
                        _u = "https://www.youtube.com/watch?v=" + _pp[0]
                        if _u not in _STORY_CLIPS:
                            _STORY_CLIPS.append(_u)
                            log.info("CLIP HUNT: found %s (%r) for %r",
                                     _pp[0], _ttl[:44], _q[:44])
                except Exception as _e:  # noqa: BLE001 — never fatal
                    log.info("clip hunt failed for %r (%s)", _q[:40], str(_e)[:60])
            log.info("CLIP HUNT: story now has %d clip(s)", len(_STORY_CLIPS))

    if _STORY_CLIP_START:
        log.info("MONEY MOMENT offsets from the source articles: %s",
                 {u.rsplit("=", 1)[-1]: s for u, s in _STORY_CLIP_START.items()})
    if len(_STORY_CLIPS) < CLIP_HUNT_MIN:
        # r33: the server harvested nothing for this story (story_vids=0 is why
        # the El Risitas video had no laugh in it). archive.org is reachable
        # from CI with no key, no cookies and no bot-wall — search it for the
        # subject before giving up on footage entirely.
        #
        # r75: this used to fire ONLY when the story had zero clips, and that
        # gate cost us page 415. The YouTube hunt found exactly one clip, which
        # was enough to look like "we have footage", so archive.org was skipped
        # — then that single clip failed to download and the render ended with
        # no footage at all. Found is not the same as usable. archive.org is now
        # a TOP-UP alongside the hunt, so one dud can never be our only option.
        terms = []
        for entry in (post.get("people") or [])[:2]:
            n = str(entry.get("name") if isinstance(entry, dict) else entry or "")
            if n:
                terms.append(n)
        t = str(post.get("title") or "").strip()
        if t:
            terms.append(" ".join(t.split()[:4]))
        for _au in archive_org_clips(terms):
            if _au not in _STORY_CLIPS:
                _STORY_CLIPS.append(_au)
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
    _TIMELINE_MODE[0] = bool(isinstance(shotlist, dict)
                             and (shotlist.get("meta") or {}).get("timeline"))
    if _TIMELINE_MODE[0]:
        log.info("TIMELINE MODE: deterministic beats, clip opportunism OFF")
        # run #243 ROOT CAUSE: per-person "recent video" thumbnails come from
        # UNVERIFIED channel guesses — 131's "Alex Cooper" channel was an
        # ads-course creator, "Alix Earle" a product reviewer, and those
        # strangers' faces rode beats wearing verified name tags. Under the
        # timeline contract only identity-verified imagery may ride: strip
        # every i.ytimg.com thumbnail from the job at this single choke
        # point, before any pool / person map / visual map is built.
        try:
            stripped = 0
            for pe in (post.get("people") or []):
                if isinstance(pe, dict):
                    if "i.ytimg.com" in str(pe.get("photo") or ""):
                        pe["photo"] = None
                        stripped += 1
                    old_n = len(pe.get("photos") or [])
                    pe["photos"] = [u for u in (pe.get("photos") or [])
                                    if "i.ytimg.com" not in str(u)]
                    stripped += old_n - len(pe["photos"])
            vis = post.get("visuals") or []
            vt = post.get("visual_titles") or []
            keep = [(v, (vt[i] if i < len(vt) else ""))
                    for i, v in enumerate(vis)
                    if "i.ytimg.com" not in str(v)]
            if len(keep) < len(vis):
                stripped += len(vis) - len(keep)
                post["visuals"] = [kv[0] for kv in keep]
                post["visual_titles"] = [kv[1] for kv in keep]
            # r87 OUR OWN COVER IS NOT EVIDENCE. The site hero is decoration
            # chosen by the image engine, and when that engine misses it misses
            # badly — page 192's hero for an AI-actress story is a photograph
            # of a hobby robot car, which under a timeline contract would open
            # the video on an object that has nothing to do with the events.
            # It is not deleted (on a thin story it may be all we have); it is
            # demoted behind every real artifact, so evidence always leads.
            _vis = post.get("visuals") or []
            _vt = post.get("visual_titles") or []
            _pairs = [(v, (_vt[i] if i < len(_vt) else ""))
                      for i, v in enumerate(_vis)]
            _covers = [p for p in _pairs if "/assets/covers/" in str(p[0])]
            if _covers and len(_covers) < len(_pairs):
                _rest = [p for p in _pairs if "/assets/covers/" not in str(p[0])]
                post["visuals"] = [p[0] for p in _rest + _covers]
                post["visual_titles"] = [p[1] for p in _rest + _covers]
                log.info("TIMELINE: site cover demoted behind %d real "
                         "artifact(s) — the hero is decoration, not evidence",
                         len(_rest))
                # r89: the same cover also arrives as post["image"], the HERO,
                # and that field is read separately — which is why demoting it
                # inside visuals[] was not enough and page 192 still OPENED on
                # the robot photo. Clear it only when a real artifact exists to
                # take its place; on a thin story the cover is still better
                # than a blank frame.
                if "/assets/covers/" in str(post.get("image") or ""):
                    post["image"] = _rest[0][0]
                    log.info("TIMELINE: hook image switched off the site cover "
                             "onto a real artifact (%s)", str(_rest[0][0])[:70])
            if stripped:
                log.info("TIMELINE: %d unverified channel thumbnail(s) "
                         "stripped from the job", stripped)
        except Exception as exc:  # noqa: BLE001
            log.warning("timeline thumbnail strip failed (%s)", exc)

    # v6: resolve the shotlist's visual_i references (real story images)
    visual_map = build_visual_map(post, page_id, pool, shotlist)

    # v4.5/r17: REAL evidence (post.receipts, idx order — receipt_i maps into
    # this dict). r17 BEIGE RETIRED: event entries arrive with url='' (the
    # server renders no event PNG anymore); only post/promo cards download
    # here. Events then resolve through resolve_event_receipts: clean article
    # screenshot > og:image report photo > subject photo (planner fallback).
    # No trim on card downloads: the cards' dark paper background must never
    # be shaved by the letterbox detector.
    _set_stage("receipts")
    receipt_paths = {}
    _rcpt_t0 = time.time()          # r41: wall-clock budget for the WHOLE stage
    recs = post.get("receipts")
    if isinstance(recs, list) and recs:
        # v6: cap raised 16 -> 20 (up to 10 events + 6 posts + the branded
        # PROMO card appended LAST — the cap must never cut the promo off).
        for i, u in enumerate(recs[:20]):
            if not (isinstance(u, str) and u.startswith("http")):
                continue                   # r17: event rows carry no PNG
            # r41 (runs #160/#162/#163 all watchdogged here): on a bitninja-
            # blackholed runner IP every genzhype-hosted card burns up to 90s
            # of connect timeouts — 20 cards vs a 150s stage cap. The feed
            # branch now STAGES these files so this is normally a local read;
            # the budget is the backstop when it is not.
            if time.time() - _rcpt_t0 > 100:
                log.info("receipts budget spent; %d card(s) skipped "
                         "(subject/og fallbacks cover them)", len(recs) - i)
                break
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
                if time.time() - _rcpt_t0 > 130:   # r41: same stage budget
                    return None
                return fetch_visual(
                    u, os.path.join(WORKDIR, f"receipt-og-{page_id}-{i}.jpg"))

            _set_stage("screenshots")
            receipt_paths, shot_n, og_n = resolve_event_receipts(
                meta, receipt_paths, _shooter, _og_fetch)
            log.info("event receipts: %d clean screenshot(s), %d og report "
                     "photo(s); the rest fall back to subject photos",
                     shot_n, og_n)

    _set_stage("tts")
    tts_begin()            # r57: start the voice stage's wall-clock budget
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

    _set_stage("compose")
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
    _set_stage("judge")
    verdict = vision_judge(out, hook, post.get("title", ""),
                           duration + TAIL_SECONDS, edl=LAST_EDL)
    if verdict is not None and verdict.get("pass") is not True:
        mism = verdict.get("mismatches") or []
        weird = verdict.get("weird") or []
        # r30 EYES ON THE REJECT: ship the filmstrip of the REJECTED render plus
        # the raw article screenshots that fed it, so the exact frame the judge
        # describes can be LOOKED at instead of reasoned about. Non-fatal, and
        # it runs before the replan/abandon branches so it always lands.
        try:
            _post_diag(page_id, "reject-sheet",
                       build_filmstrip(out, duration + TAIL_SECONDS,
                                       os.path.join(WORKDIR,
                                                    f"reject-{page_id}.jpg")))
            for sp in sorted(glob.glob(os.path.join(WORKDIR,
                                                    f"shot-{page_id}-*.png")))[:6]:
                _post_diag(page_id,
                           os.path.splitext(os.path.basename(sp))[0], sp)
        except Exception:  # noqa: BLE001
            pass
        # r29: surface WHY the judge rejected (Actions logs need repo admin to
        # read) so a rejection is diagnosable from the server heartbeat log.
        try:
            _hb_post({
                "token": INGEST_TOKEN, "action": "heartbeat",
                "page_id": _STAGE_PID, "stage": "JUDGE_FAIL", "elapsed": 0,
                "note": json.dumps({"weird": weird[:6], "mismatches": mism[:4],
                                    "issues": (verdict.get("issues") or [])[:4],
                                    "scores": verdict.get("scores")},
                                   ensure_ascii=False)[:780],
            })
        except Exception:
            pass
        # r29 SOFT-DELIVER — a strong video must SHIP rather than freeze the line
        # over one minor cosmetic frame. Ship when there are NO said-vs-seen
        # mismatches, at most ONE weird frame, that frame is a NON-critical type
        # (f = caption collision, g = ad-cluttered proof), and the judge's own
        # scores are good. The junk the owner rejects — a cut text, b sliced
        # face, c repetition, d dead frame, e context-mismatch / irrelevant
        # stock — is CRITICAL and never soft-passes.
        letters = {str((w or {}).get("issue", "")).strip()[:1].lower()
                   for w in weird}
        scores = verdict.get("scores") or {}
        soft = (not mism and len(weird) <= 1 and letters <= {"f", "g"}
                and int(scores.get("readability", 0) or 0) >= 6
                and int(scores.get("variety", 0) or 0) >= 5)
        if soft:
            log.warning(
                "SOFT-DELIVER page %s despite a minor judge note (%s, scores=%s)"
                " — shipping a strong video beats freezing the line.",
                page_id, weird[:1], scores)
        else:
            # HARD rejection. RELIABILITY VALVE: a rejected page must NEVER freeze
            # the factory — it sits first in the queue and blocks every story
            # behind it (page 13, then 470, stalled the line for 2.5 days). After
            # REPLAN_CAP attempts ABANDON it (mark done -> queue advances).
            prev = replan_count(page_id)
            if prev >= REPLAN_CAP:
                log.error(
                    "ABANDON page %s after %d attempts — judge still fails "
                    "(weird=%s mism=%s); marking done so the queue advances.",
                    page_id, prev, weird[:3], mism[:3])
                append_done(page_id)
                return                   # green run; next run renders the next page
            now = bump_replan(page_id)
            if len(mism) >= 2:
                # said-vs-seen: re-direct the plan for the next attempt.
                reasons = [
                    f"frame {m.get('frame')}: said "
                    f"'{str(m.get('words') or '')[:120]}' but showed "
                    f"{str(m.get('what_shown') or '')[:120]}"
                    for m in mism[:6]]
                request_replan(page_id, reasons)
            raise JudgeRejected(
                f"vision judge rejected page {page_id} (attempt {now}/{REPLAN_CAP}): "
                f"weird={weird[:3]} mism={mism[:3]} issues={verdict.get('issues')}")

    # r19: build + deliver the filmstrip (12 frames + spoken words) so the
    # operator's AI can SEE what the render shows vs says. Never fatal.
    _set_stage("filmstrip")
    sheet = build_filmstrip(out, duration + TAIL_SECONDS,
                            os.path.join(WORKDIR, f"sheet-{page_id}.jpg"))
    _set_stage("post")
    post_video(page_id, slug, out, sheet_path=sheet)
    append_done(page_id)
    _set_stage("done")


def main():
    if not INGEST_TOKEN:
        log.error("INGEST_TOKEN not set")
        return 2

    _start_heartbeat()          # r29: server-side stage tracing for hang diagnosis
    font_path = resolve_font()
    made = 0
    for _ in range(VIDEO_BATCH):
        done = read_done()
        try:
            post = fetch_next(done)
        except Exception as exc:  # noqa: BLE001
            log.error("fetch_next failed: %s", exc)
            try:                              # r34: say so out loud (see below)
                _hb_post({"token": INGEST_TOKEN, "action": "heartbeat",
                          "page_id": 0, "stage": "CRASH", "elapsed": 0,
                          "note": "fetch_next: %s: %s"
                                  % (type(exc).__name__, str(exc)[:200])})
            except Exception:  # noqa: BLE001
                pass
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
            # r34 CRASH REPORT: a driver death is invisible from outside — the
            # heartbeat thread dies with the process, so the log just STOPS and
            # the traceback sits in Actions logs that need repo admin to read.
            # Two renders were diagnosed by staring at silence. POST the
            # exception and the deepest frame in OUR file to the heartbeat log.
            try:
                tb = traceback.extract_tb(sys.exc_info()[2])
                mine = [f for f in tb if "video_maker" in (f.filename or "")]
                where = mine[-1] if mine else (tb[-1] if tb else None)
                note = "%s: %s" % (type(exc).__name__, str(exc)[:220])
                if where:
                    note += " @ %s:%s in %s()" % (
                        os.path.basename(where.filename), where.lineno,
                        where.name)
                _hb_post({"token": INGEST_TOKEN, "action": "heartbeat",
                          "page_id": int(post.get("page_id") or 0),
                          "stage": "CRASH", "elapsed": 0, "note": note})
            except Exception:  # noqa: BLE001
                pass
            # Do NOT mark done on failure — it will be retried next run.
            return 1
    log.info("done. made %d video(s)", made)
    return 0


if __name__ == "__main__":
    sys.exit(main())
