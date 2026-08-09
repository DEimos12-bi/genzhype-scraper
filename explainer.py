#!/usr/bin/env python3
"""EXPLAINER (r89) — renders one narrated video that explains how the video
system finds pictures and clips, for the owner.

WHY IT LOOKS THE WAY IT DOES. The owner is a visual learner and has already
told us twice that pages of text and one-off diagrams do not land. So this is
built on a single teaching device: ONE map of the machine that stays on screen
the whole time, with a spotlight that moves to the part being spoken about.
Nothing appears and disappears; you always see where you are in the whole.

Everything is drawn with Pillow — no stock images, no downloads, no fonts to
find at runtime beyond DejaVu, which the runner already has. That means this
renders the same way every time and cannot fail on a network hiccup.

Narration is edge-tts, the same voice the real videos use, so the length of
each scene is decided by how long the sentence actually takes to say — never a
guessed duration that leaves the viewer reading ahead or waiting.
"""
import asyncio
import json
import os
import subprocess
import sys

from PIL import Image, ImageDraw, ImageFont

W, H = 1920, 1080
BG = (18, 20, 25)
INK = (245, 246, 248)
DIM = (128, 136, 148)
RED = (200, 16, 46)
GREEN = (63, 164, 99)
AMBER = (216, 176, 106)
BOX = (32, 36, 44)
BOXON = (44, 50, 62)

FONT_DIR = "/usr/share/fonts/truetype/dejavu"
OUT = os.environ.get("EXPLAINER_OUT", "explainer.mp4")
VOICE = os.environ.get("EXPLAINER_VOICE", "en-US-AndrewMultilingualNeural")


def font(size, bold=True):
    name = "DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf"
    return ImageFont.truetype(os.path.join(FONT_DIR, name), size)


# ---------------------------------------------------------------- THE MAP
# Left column = where a beat comes from. Middle = the ladders we walk down.
# Right = the checks. Each entry: (key, x, y, w, h, title, sub)
STEPS = [
    ("script",   80,  120, 420, 110, "THE SCRIPT",        "one sentence = one beat"),
    ("beat",     80,  270, 420, 110, "ONE BEAT",          "for drama: one dated event"),

    ("clip1",   600,  120, 620,  96, "1. Reporter's embed", "the video inside our source article"),
    ("clip2",   600,  236, 620,  96, "2. YouTube search",   "director's phrase + title check"),
    ("clip3",   600,  352, 620,  96, "3. archive.org",      "old footage, no key needed"),

    ("pic1",    600,  520, 620,  86, "4. Article screenshot", "a picture of the page itself"),
    ("pic2",    600,  622, 620,  86, "5. The article's photo", "its og:image"),
    ("pic3",    600,  724, 620,  86, "6. Photo of the person", "who the story is about"),
    ("pic4",    600,  826, 620,  86, "7. Our own cover",       "last resort"),

    ("cards",  1320,  120, 520, 130, "EVIDENCE CARDS",  "real tweets, checked with X"),
    ("gates",  1320,  300, 520, 130, "THE GATES",       "identity, topic, provenance"),
    ("judge",  1320,  480, 520, 130, "THE JUDGE",       "looks at 12 finished frames"),
    ("out",    1320,  660, 520, 130, "THE VIDEO",       "or a rejection, with reasons"),
]
BY_KEY = {s[0]: s for s in STEPS}

ARROWS = [
    ("script", "beat"),
    ("beat", "clip1"),
    ("clip1", "clip2"), ("clip2", "clip3"),
    ("clip3", "pic1"),
    ("pic1", "pic2"), ("pic2", "pic3"), ("pic3", "pic4"),
    ("clip1", "gates"),
    ("cards", "judge"), ("gates", "judge"), ("judge", "out"),
]


def wrap(draw, text, fnt, maxw):
    words, lines, cur = text.split(), [], ""
    for w in words:
        t = (cur + " " + w).strip()
        if draw.textlength(t, font=fnt) <= maxw:
            cur = t
        else:
            if cur:
                lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def draw_map(highlight=(), banner="", note="", note_colour=INK):
    """The whole machine, with `highlight` keys lit up."""
    im = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(im)

    f_h1 = font(40)
    f_box = font(27)
    f_sub = font(20, bold=False)
    f_sect = font(19)
    f_note = font(30)

    d.text((80, 44), "HOW A VIDEO FINDS ITS PICTURES AND CLIPS", font=f_h1, fill=INK)

    d.text((600, 92), "IF THERE IS A CLIP, USE A CLIP", font=f_sect, fill=GREEN)
    d.text((600, 492), "NO CLIP? THEN A PICTURE, IN THIS ORDER", font=f_sect, fill=AMBER)

    # arrows first so boxes sit on top
    for a, b in ARROWS:
        ax, ay, aw, ah = BY_KEY[a][1], BY_KEY[a][2], BY_KEY[a][3], BY_KEY[a][4]
        bx, by, bw, bh = BY_KEY[b][1], BY_KEY[b][2], BY_KEY[b][3], BY_KEY[b][4]
        lit = a in highlight and b in highlight
        col = RED if lit else (52, 58, 70)
        if abs(ax - bx) < 6:                      # vertical
            d.line([(ax + aw // 2, ay + ah), (bx + bw // 2, by)], fill=col, width=3)
        else:                                     # horizontal-ish
            d.line([(ax + aw, ay + ah // 2), (bx, by + bh // 2)], fill=col, width=3)

    for key, x, y, w, h, title, sub in STEPS:
        on = key in highlight
        d.rounded_rectangle([x, y, x + w, y + h], radius=12,
                            fill=BOXON if on else BOX,
                            outline=RED if on else (52, 58, 70), width=4 if on else 2)
        d.text((x + 20, y + 18), title, font=f_box, fill=INK if on else DIM)
        for i, ln in enumerate(wrap(d, sub, f_sub, w - 40)[:2]):
            d.text((x + 20, y + 52 + i * 24), ln, font=f_sub, fill=DIM)

    if banner:
        d.rectangle([0, H - 150, W, H], fill=(10, 11, 14))
        for i, ln in enumerate(wrap(d, banner, f_note, W - 160)[:2]):
            d.text((80, H - 126 + i * 40), ln, font=f_note, fill=note_colour)
    if note:
        d.text((80, H - 190), note, font=font(22), fill=AMBER)
    return im


def title_card(line1, line2):
    im = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(im)
    d.text((80, 380), line1, font=font(86), fill=INK)
    for i, ln in enumerate(wrap(d, line2, font(36, bold=False), W - 200)[:3]):
        d.text((80, 520 + i * 52), ln, font=font(36, bold=False), fill=DIM)
    d.rectangle([80, 340, 320, 350], fill=RED)
    return im


# ------------------------------------------------------------------ SCRIPT
# Each scene: (narration, highlighted keys, banner, optional amber footnote)
SCENES = [
    ("Here is the whole system on one screen. It stays here the entire time, "
     "and I will light up the part I am talking about.",
     [], "", ""),

    ("It starts with the script. Every sentence is one beat.",
     ["script"], "Every sentence is one beat", ""),

    ("For a drama story, one beat is one dated event. That matters, because it "
     "means a clip is matched to its own event by a lookup, not by a guess.",
     ["script", "beat"], "One beat = one dated event", ""),

    ("Now, for every single beat, we go looking. First choice is always a clip.",
     ["beat", "clip1"], "For every beat: try a clip first", ""),

    ("Choice one. The video the reporter put inside the article we built this "
     "beat from. This is the best source we have, because a journalist already "
     "decided that video belongs to this story.",
     ["clip1"], "1. The clip inside our own source article",
     "This is where 8 of page 192's clips came from"),

    ("That is why those clips can no longer be thrown out. Until today, a "
     "checker looked at one frame and vetoed both of that story's clips as "
     "off topic. A reporter's decision beats a guess about one frame.",
     ["clip1", "gates"], "Provenance beats a guess", ""),

    ("Choice two. We search YouTube using a phrase the Visual Director wrote. "
     "But YouTube answers every question with something, even when it has "
     "nothing.",
     ["clip2"], "2. Search YouTube", ""),

    ("So a result only counts if a rare word from the story appears in its "
     "title. Without that rule, we measured four searches out of five bringing "
     "back a wrong celebrity or a courtroom clip.",
     ["clip2", "gates"], "The title must carry a story word",
     "Dropped: Amanda Bynes, Paris Hilton, a courtroom clip"),

    ("Choice three is archive dot org, for older footage.",
     ["clip3"], "3. archive.org", ""),

    ("If none of those gave us a clip, only then do we fall back to a still "
     "picture, and there is a strict order.",
     ["clip3", "pic1"], "No clip? Then a picture, in order", ""),

    ("First, a screenshot of the article itself. Real, dated, and it shows the "
     "reader where the fact came from.",
     ["pic1"], "4. A screenshot of the article", ""),

    ("Then the article's own photo. Then a picture of the person the story is "
     "about.",
     ["pic2", "pic3"], "5 and 6. The report photo, then the person", ""),

    ("Last, our own cover image. Last, because when our picture engine misses, "
     "it misses badly. The cover on the AI actress story is a photograph of a "
     "hobby robot car.",
     ["pic4"], "7. Our cover — last resort",
     "That robot was opening the video until today"),

    ("Separately from all of that: if the story quotes a post, we ask X whether "
     "that post is real, and draw it as a card. If X will not confirm it, "
     "there is no card. A fake card cannot be made.",
     ["cards"], "Real posts, confirmed by X itself", ""),

    ("Slang, meme and gaming pages have no events and no reporters, so they "
     "skip the clip ladder entirely and use their own picture hunt. Everything "
     "after that is the same.",
     ["pic1", "pic2", "pic3"], "Slang, meme and gaming: pictures only", ""),

    ("Then the gates. They ask: is this the right person, is this the right "
     "topic, and do we know where it came from.",
     ["gates"], "The gates", ""),

    ("And at the end, the judge looks at twelve finished frames and can throw "
     "the whole video away. That is what caught the country singer.",
     ["judge"], "The judge sees the finished frames",
     "It rejected page 192 twice, correctly"),

    ("So when a video comes out thin, it is never mysterious. One of those "
     "boxes returned nothing, or returned junk. That is the only thing that "
     "ever goes wrong.",
     ["clip1", "clip2", "clip3", "pic1", "pic2", "pic3", "pic4"],
     "A thin video = one box returned nothing", ""),
]


async def say(text, out_mp3):
    import edge_tts
    c = edge_tts.Communicate(text, VOICE, rate="+5%")
    await c.save(out_mp3)


def main():
    from moviepy import AudioFileClip, ImageClip, concatenate_videoclips

    os.makedirs("explainer_work", exist_ok=True)
    clips = []

    intro = title_card("How the video system works",
                       "Where every picture and every clip on screen comes from, "
                       "and why a video sometimes comes out thin.")
    p = "explainer_work/intro.png"
    intro.save(p)
    a = "explainer_work/intro.mp3"
    asyncio.run(say("This explains how the video system finds its pictures and "
                    "its clips, and why a video sometimes comes out thin.", a))
    ac = AudioFileClip(a)
    clips.append(ImageClip(p).with_duration(ac.duration + 0.6).with_audio(ac))

    for i, (line, keys, banner, note) in enumerate(SCENES):
        img = draw_map(highlight=keys, banner=banner, note=note)
        p = f"explainer_work/s{i:02d}.png"
        img.save(p)
        a = f"explainer_work/s{i:02d}.mp3"
        asyncio.run(say(line, a))
        ac = AudioFileClip(a)
        # a beat of silence after each sentence so the eye can follow the light
        clips.append(ImageClip(p).with_duration(ac.duration + 0.7).with_audio(ac))
        print(f"scene {i}: {ac.duration:.1f}s — {banner or 'map'}", flush=True)

    video = concatenate_videoclips(clips, method="chain")
    video.write_videofile(OUT, fps=24, codec="libx264", audio_codec="aac",
                          preset="medium", threads=4, logger=None)
    print(f"WROTE {OUT} — {video.duration:.0f}s, {len(clips)} scenes")


if __name__ == "__main__":
    main()
