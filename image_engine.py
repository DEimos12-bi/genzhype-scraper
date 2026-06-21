#!/usr/bin/env python3
"""
GenZHype | IMAGE ENGINE  (external, GitHub Actions — like scraper.py / competitor_watcher.py).

A real editorial image pipeline assembled from the OSS tools you uploaded. For each drama
that is still a branded card it:
  1. SOURCE    candidate photos of the people  — Openverse (CC-licensed) + Wikimedia + the
               creator's own YouTube channel (identity-safe) + (optional) Bing via icrawler.
  2. SAFE      drop nsfw/gore                   — NudeNet.
  3. IDENTITY  keep only the RIGHT person       — deepface.verify() vs a trusted reference.
               THIS is what stops the "wrong Ben Schneider (a folk musician)" defamation bug.
  4. RELEVANCE pick what fits the story + mood  — open_clip image<->text similarity.
  5. QUALITY   pick the cleanest                — NIMA (image-quality-assessment).
  6. CROP      face-centered 1200x630 webp      — face box (deepface) -> cover crop.
  7. DELIVER   POST the finished hero           — /api/img_ingest.php (identity_ok=true).

Heavy models load lazily and degrade gracefully: if one can't load on the runner, its signal
is skipped, never crashing the run. Publishes ONLY when identity is confirmed — otherwise the
drama stays a clean card (safe by default).

Env: IMG_BASE (https://genzhype.com), INGEST_TOKEN, YOUTUBE_KEY, OPENVERSE_TOKEN(optional),
     MAX_DRAMAS(default 6), USE_BING(0/1).
requirements: see requirements_image.txt
"""
import io, os, sys, json, time, base64, hashlib, re, urllib.request, urllib.parse

# Prevent the classic TensorFlow + PyTorch + onnxruntime segfault (multiple OpenMP runtimes
# in one process — "OMP: Error #15" / exit 139). Must be set BEFORE any ML import.
os.environ.setdefault("KMP_DUPLICATE_LIB_OK", "TRUE")
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "3")
os.environ.setdefault("TF_ENABLE_ONEDNN_OPTS", "0")

# ---- config ----
BASE   = os.environ.get("IMG_BASE", "https://genzhype.com").rstrip("/")
TOKEN  = os.environ.get("INGEST_TOKEN", "")
YT_KEY = os.environ.get("YOUTUBE_KEY", "")
MAXN   = int(os.environ.get("MAX_DRAMAS", "6"))
USE_BING = os.environ.get("USE_BING", "0") == "1"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0"

import socket as _s                       # force IPv4 (no IPv6 on runners)
_g = _s.getaddrinfo; _s.getaddrinfo = lambda *a, **k: [x for x in _g(*a, **k) if x[0] == _s.AF_INET]

# ---- http (browser-TLS, like the scraper) ----
try:
    from curl_cffi import requests as _cffi
    def http_get(url, timeout=20):
        r = _cffi.get(url, impersonate="firefox", timeout=timeout, headers={"User-Agent": UA}); r.raise_for_status(); return r.content
    def http_json(url, timeout=20):
        r = _cffi.get(url, impersonate="firefox", timeout=timeout, headers={"User-Agent": UA, "Accept": "application/json"}); r.raise_for_status(); return r.json()
    def http_post(url, body, timeout=120):
        r = _cffi.post(url, json=body, impersonate="firefox", timeout=timeout, headers={"User-Agent": UA}); r.raise_for_status(); return r.json()
except Exception:
    def http_get(url, timeout=20):
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        with urllib.request.urlopen(req, timeout=timeout) as r: return r.read()
    def http_json(url, timeout=20):
        return json.loads(http_get(url, timeout).decode("utf-8", "replace"))
    def http_post(url, body, timeout=120):
        req = urllib.request.Request(url, data=json.dumps(body).encode(), headers={"Content-Type": "application/json", "User-Agent": UA})
        with urllib.request.urlopen(req, timeout=timeout) as r: return json.loads(r.read().decode())

def log(*a): print(*a, file=sys.stderr, flush=True)

# ---- lazy model singletons (load once, degrade if unavailable) ----
_M = {}
def clip_model():
    if "clip" not in _M:
        try:
            import torch, open_clip
            model, _, prep = open_clip.create_model_and_transforms("ViT-B-32", pretrained="laion2b_s34b_b79k")
            tok = open_clip.get_tokenizer("ViT-B-32"); model.eval()
            _M["clip"] = (model, prep, tok, torch)
        except Exception as e:
            log("  clip unavailable:", e); _M["clip"] = None
    return _M["clip"]

def nude_detector():
    if "nude" not in _M:
        try:
            from nudenet import NudeDetector; _M["nude"] = NudeDetector()
        except Exception as e:
            log("  nudenet unavailable:", e); _M["nude"] = None
    return _M["nude"]

def nima_scorer():
    # idealo image-quality-assessment is TF/Keras; if it won't load we fall back to a sharpness proxy
    if "nima" not in _M:
        _M["nima"] = None     # optional; quality proxy used if absent (kept simple for v1)
    return _M["nima"]

# ---- helpers ----
from PIL import Image
def load_img(b):
    try: return Image.open(io.BytesIO(b)).convert("RGB")
    except Exception: return None

def is_nsfw(b):
    det = nude_detector()
    if not det: return False
    try:
        import tempfile
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as f: f.write(b); p = f.name
        res = det.detect(p); os.unlink(p)
        bad = {"FEMALE_BREAST_EXPOSED","FEMALE_GENITALIA_EXPOSED","MALE_GENITALIA_EXPOSED","ANUS_EXPOSED","BUTTOCKS_EXPOSED"}
        return any((d.get("class") in bad and d.get("score",0) > 0.5) for d in (res or []))
    except Exception: return False

def same_person(cand_bytes, ref_bytes):
    """deepface.verify -> True if same person. The identity gate."""
    try:
        from deepface import DeepFace
        import tempfile, numpy as np
        paths = []
        for b in (cand_bytes, ref_bytes):
            t = tempfile.NamedTemporaryFile(suffix=".jpg", delete=False); t.write(b); t.close(); paths.append(t.name)
        out = DeepFace.verify(paths[0], paths[1], model_name="ArcFace", detector_backend="retinaface", enforce_detection=True)
        for p in paths: os.unlink(p)
        return bool(out.get("verified"))
    except Exception as e:
        return None        # couldn't decide (no face detected / model down) -> caller treats as "unverified"

def clip_scores(img, story, mood):
    c = clip_model()
    if not c: return 0.5, 0.5
    model, prep, tok, torch = c
    try:
        with torch.no_grad():
            im = prep(img).unsqueeze(0)
            texts = [f"a clear editorial photo for a news story about {story}",
                     f"a {mood} mood photograph of a person"]
            t = tok(texts)
            imf = model.encode_image(im); tf = model.encode_text(t)
            imf /= imf.norm(dim=-1, keepdim=True); tf /= tf.norm(dim=-1, keepdim=True)
            sims = (imf @ tf.T).squeeze(0).tolist()
        return float(sims[0]), float(sims[1])      # relevance, mood-fit
    except Exception: return 0.5, 0.5

def quality_proxy(img):
    """Cheap NIMA stand-in for v1: variance-of-Laplacian sharpness, 0..1."""
    try:
        import numpy as np
        g = np.asarray(img.convert("L"), dtype="float32")
        lap = np.abs(np.gradient(g)[0]).var() + np.abs(np.gradient(g)[1]).var()
        return max(0.0, min(1.0, lap / 4000.0))
    except Exception: return 0.5

def face_crop_webp(b, w=1200, h=630):
    """Crop to a face-centered 16:9 hero webp. Uses deepface's detector for the face box."""
    img = load_img(b)
    if not img: return None
    cx, cy = img.width/2, img.height*0.42
    try:
        from deepface import DeepFace
        import tempfile, numpy as np
        t = tempfile.NamedTemporaryFile(suffix=".jpg", delete=False); t.write(b); t.close()
        faces = DeepFace.extract_faces(t.name, detector_backend="retinaface", enforce_detection=False); os.unlink(t.name)
        if faces:
            fa = max(faces, key=lambda f: f.get("facial_area",{}).get("w",0)).get("facial_area",{})
            cx = fa.get("x",0) + fa.get("w",0)/2; cy = fa.get("y",0) + fa.get("h",0)/2
    except Exception: pass
    scale = max(w/img.width, h/img.height)
    nw, nh = int(img.width*scale), int(img.height*scale)
    img2 = img.resize((nw, nh))
    left = int(min(max(cx*scale - w/2, 0), nw - w)); top = int(min(max(cy*scale - h/2, 0), nh - h))
    crop = img2.crop((left, top, left+w, top+h))
    out = io.BytesIO(); crop.save(out, "WEBP", quality=86); return out.getvalue()

# ---- sources ----
def wikimedia_ref(name, story=""):
    """A TRUSTED reference photo of the person from Wikidata/Wikimedia (or None). Rejects a
    famous NAMESAKE in another field (Ben Schneider the folk musician) so we never verify
    candidates against the wrong person."""
    try:
        q = http_json("https://www.wikidata.org/w/api.php?action=wbsearchentities&format=json&language=en&type=item&limit=5&search=" + urllib.parse.quote(name))
        results = q.get("search") or []
        if not results: return None
        top = results[0]
        desc = (top.get("description") or "").lower()
        mismatch = any(w in desc for w in ["musician","singer","songwriter","guitarist","drummer","band","composer","footballer","football player","basketball","baseball","cricketer","politician","senator","governor","novelist","author","painter","economist","scientist","physician","astronaut"])
        creatorish = any(w in desc for w in ["youtuber","streamer","internet","influencer","content creator","twitch","social media","online","personality","gamer","tiktok","podcaster","media"])
        sl = (name + " " + story).lower()
        if mismatch and not creatorish and not any(w in sl for w in ["music","song","album","rap","concert","band","sport","politic","film","movie","novel","paint","science"]):
            log(f"  wikimedia: '{name}' resolves to a non-creator namesake ({desc}) -> skipping reference")
            return None
        ent = top.get("id")
        if not ent: return None
        d = http_json(f"https://www.wikidata.org/w/api.php?action=wbgetclaims&format=json&property=P18&entity={ent}")
        img = d.get("claims",{}).get("P18",[{}])[0].get("mainsnak",{}).get("datavalue",{}).get("value")
        if not img: return None
        fn = img.replace(" ", "_"); md5 = hashlib.md5(fn.encode()).hexdigest()
        url = f"https://upload.wikimedia.org/wikipedia/commons/{md5[0]}/{md5[0:2]}/{urllib.parse.quote(fn)}"
        return {"url": url, "credit": f"Photo via Wikimedia Commons", "credit_url": ""}
    except Exception: return None

def openverse_candidates(name, n=6):
    """CC-licensed photos of the person (Openverse hosted API). Returns [{url,credit,credit_url}]."""
    out = []
    try:
        url = ("https://api.openverse.org/v1/images/?q=" + urllib.parse.quote(name)
               + "&license_type=all&mature=false&page_size=" + str(n))
        j = http_json(url, 25)
        for r in (j.get("results") or [])[:n]:
            u = r.get("url");  cre = r.get("creator") or "Openverse"
            if u: out.append({"url": u, "credit": f"Photo: {cre} ({r.get('license','cc')}), via Openverse",
                              "credit_url": r.get("foreign_landing_url") or ""})
    except Exception as e: log("  openverse:", e)
    return out

def youtube_channel_candidates(name, n=6):
    """Identity-safe frames from the person's OWN channel uploads + the channel avatar."""
    if not YT_KEY: return []
    out = []
    try:
        s = http_json("https://www.googleapis.com/youtube/v3/search?part=snippet&type=channel&maxResults=3&q=" + urllib.parse.quote(name) + "&key=" + YT_KEY)
        items = s.get("items") or []
        if not items: return []
        cid = items[0]["id"]["channelId"]; av = items[0]["snippet"]["thumbnails"]["high"]["url"]
        out.append({"url": av, "credit": f"Via {name} on YouTube", "credit_url": f"https://www.youtube.com/channel/{cid}"})
        ch = http_json(f"https://www.googleapis.com/youtube/v3/channels?part=contentDetails&id={cid}&key={YT_KEY}")
        up = ch["items"][0]["contentDetails"]["relatedPlaylists"]["uploads"]
        pl = http_json(f"https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults={n}&playlistId={up}&key={YT_KEY}")
        for it in pl.get("items", []):
            vid = it["snippet"].get("resourceId",{}).get("videoId"); th = it["snippet"].get("thumbnails",{}).get("high",{})
            if vid and th.get("width",480) >= th.get("height",360):
                out.append({"url": f"https://i.ytimg.com/vi/{vid}/maxresdefault.jpg", "credit": f"Via {name} on YouTube",
                            "credit_url": f"https://www.youtube.com/watch?v={vid}"})
    except Exception as e: log("  youtube:", e)
    return out

def bing_candidates(name, n=6):
    if not USE_BING: return []
    try:
        from icrawler.builtin import BingImageCrawler
        import tempfile, glob
        d = tempfile.mkdtemp()
        BingImageCrawler(storage={"root_dir": d}).crawl(keyword=name + " portrait", max_num=n)
        return [{"url": "file://" + p, "credit": "Via web search", "credit_url": ""} for p in glob.glob(d + "/*")]
    except Exception as e: log("  bing:", e); return []

def fetch_bytes(u):
    if u.startswith("file://"):
        try:
            with open(u[7:], "rb") as f: return f.read()
        except Exception: return b""
    try: return http_get(u, 20)
    except Exception: return b""

# ---- per-drama pipeline ----
_ROLE = re.compile(r'^(lego\s+)?(youtuber|streamer|influencer|tiktoker|twitch streamer|rapper|singer|comedian|actor|actress|content creator)\s+', re.I)
_STOP = re.compile(r"['’]s\b|:|\bvs\.?\b|\b(discusses|dies|died|announces|files|rejects|sparks|arrest|abuse|faces|responds|addresses|slams|quits|leaves|leaving|accused|denies|apologizes|sues|clarifies|breaks|reveals|admits|confirms|after|amid|and|over|gets|goes|calls|hits)\b", re.I)
def best_person(item):
    """Extract the story's CENTRAL person from the title (the noisy work-list hint is ignored)."""
    t = (item.get("title", "") or "").strip()
    t = _ROLE.sub("", t)                       # drop a leading role descriptor ("Lego YouTuber ...")
    t = _STOP.split(t)[0]                       # cut at the first action word / possessive / colon
    name = " ".join(t.strip(" -–—").split()[:3])
    return name[:60] if len(name) >= 2 else (item.get("title", "")[:40])

def process(item):
    title, summary, mood = item["title"], item.get("summary",""), item.get("mood","neutral")
    person = best_person(item)
    log(f"#{item['page_id']} {title[:48]} | person={person} | mood={mood}")

    # reference for identity: Wikimedia first; else the channel avatar (must contain a face)
    ref = wikimedia_ref(person, title + " " + summary)
    ref_bytes = fetch_bytes(ref["url"]) if ref else b""
    if not ref_bytes:
        yt = youtube_channel_candidates(person, 1)
        if yt:
            rb = fetch_bytes(yt[0]["url"])
            # only a real face can be a reference (a logo avatar can't verify anyone)
            if rb and same_person(rb, rb) is not None: ref_bytes = rb
    if not ref_bytes:
        log("  no face reference -> cannot verify identity, staying a card"); return None

    # gather candidates from all sources
    cands = openverse_candidates(person) + youtube_channel_candidates(person) + bing_candidates(person)
    if not cands: log("  no candidates"); return None

    scored = []
    for c in cands[:14]:
        b = fetch_bytes(c["url"])
        if len(b) < 4000: continue
        if is_nsfw(b): continue
        idok = same_person(b, ref_bytes)
        if idok is not True: continue            # IDENTITY GATE — only the verified-right person
        img = load_img(b)
        if not img: continue
        rel, moodfit = clip_scores(img, title, mood)
        q = quality_proxy(img)
        score = 0.5*rel + 0.25*moodfit + 0.25*q
        scored.append((score, c, b))
    if not scored:
        log("  no candidate verified as the right person -> card"); return None
    scored.sort(key=lambda x: x[0], reverse=True)
    best_score, best_c, best_b = scored[0]
    hero = face_crop_webp(best_b)
    if not hero: log("  crop failed"); return None
    log(f"  -> PUBLISH ({best_c['credit']}) score={best_score:.2f}")
    return {"page_id": item["page_id"], "slug": item.get("slug"), "image_b64": base64.b64encode(hero).decode(),
            "credit": best_c["credit"], "credit_url": best_c.get("credit_url",""), "identity_ok": True,
            "scores": {"final": round(best_score,3)}}

def main():
    if not TOKEN: log("missing INGEST_TOKEN"); return 1
    wl = http_json(f"{BASE}/api/img_worklist.php?token={urllib.parse.quote(TOKEN)}&limit={MAXN}")
    items = wl.get("items", [])[:MAXN]
    log(f"worklist: {len(items)} dramas need an image")
    delivered = 0
    for it in items:
        try:
            r = process(it)
        except Exception as e:
            log(f"  #{it.get('page_id')} crashed: {e}"); continue
        if not r: continue
        # deliver each image AS SOON as it's ready, so a later crash never loses earlier wins
        try:
            res = http_post(f"{BASE}/api/img_ingest.php", {"token": TOKEN, "items": [r]})
            log("  delivered:", json.dumps(res)); delivered += 1
        except Exception as e:
            log("  deliver failed:", e)
    log(f"done; published {delivered} image(s)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
