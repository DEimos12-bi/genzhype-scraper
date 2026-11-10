# GenZHype video system v3

One story in, one finished TikTok video out (1080x1920, over a minute), with nobody in the loop. It replaces the planning
of v2 (one text request, white cards) with a director that looks at its footage, and keeps the parts that already worked.

```
python v3/make.py work/<name> --url https://genzhype.com/<lane>/<story>/     # the owner's PC
python v3/feed.py https://genzhype.com/<lane>/<story>/                       # hand the story to the GitHub maker
python v3/auto.py --max 2                                                    # the daily run: the newest untried stories, by itself
```

## The steps (each is one file; a stopped run continues where it stopped)

| step | file | what it does |
|---|---|---|
| material | `material.py` | reads the page: its text, the posts it cites (text, pictures, videos, through X's public embed data), the text of the outlets in its Sources list, and on a meme page the meme itself: the GIPHY GIFs and the TikTok / YouTube posts it links (their public embed data) |
| plan | `director.py` (+ `memes.py`) | three small jobs instead of one: a WRITER writes five openings and the script (words only); a second reader, asked as the viewer, picks the opening; a PICTURE EDITOR says what is on screen for each line. On a meme page the meme's own pictures are fetched and looked at by the picture model first, so the words fit the pictures. Then code checks every rule, including how the script SOUNDS (long sentences, article words, no turn); a second AI reading checks every statement against the material; two repair rounds, the best version is kept; a plan that still breaks a hard rule is refused |
| voice | `tts.py` | one take per line with word timings (edge-tts); everything is timed from the spoken words |
| footage | `footage.py` | the posts' own videos and pictures and the page's own pictures (a meme's examples); a video game's official trailer from its Steam store page (exact name only); YouTube searches for the rest; neutral stock (blurred only) if that leaves too little |
| eyes | `eyes.py` | a picture model looks at a sheet of every clip: what each moment shows, how good it is, where the subject sits, whether it is on topic |
| receipts | `receipts.py` | the posts shown as proof, photographed from X's embed page with the boxes of the words to highlight |
| site | `site.py` | the story's page photographed as a PC, a tablet and a phone see it; the closing shows the three devices with the real page |
| cut | `shots.py` | footage under every second, cut on spoken words, best moments first, none twice; a person's clip is shown sharp only on a line that names that person |
| layout | `render.py layout` + `check.py` | reads where every graphic sits; two graphics on one spot or a graphic outside the free area are repaired |
| gate | `check.py` | refuses a video under 61 s, with footage missing, with too little to show, or about a death, an arrest, sexual violence or a minor (that one waits for the owner: `--approved`) |
| frames | `render.py frames` | the picture, drawn in a headless browser (`comp.html`, `engine.js`, `build.js`), in batches |
| sound | `audio.py` | voice + music and effects made in code, placed on the same words as the graphics |
| pack | `make.py` | `out/`: the mp4, `cover.jpg`, `post.txt` (caption, hashtags, pinned comment, script, proof, footage credits), `report.json` |

## Two formats

- **Story** (`/gaming/`, `/drama/` pages): footage under every second, the proof on screen, a vote.
- **Meme** (`/meme/` pages): the story format, but the video shows the meme itself the whole time. Its examples (GIFs
  as looping clips, posts as their preview pictures) carry every line, shown whole (never cropped), a new one about
  every three seconds; at most two cards, no stock footage. An example that shows something else, or a real person as
  its subject, is not used (`"memes": {"real_people": false}` in `config.json`; a GIF of a stranger is never used).
- **Slang** (`/slang/` pages): a game in motion graphics with no footage, modelled on the hand-made glaze video
  (`slang.js`, `check_slang` in `director.py`): three texts to pick from with a 3-2-1 and the reveal, the meaning on a
  dictionary card, the forms, a quick round with a meter, the origin on a timeline, a real quote, a final one-word
  choice. The word's theme (one colour, one emoji) gives the look: drips, wipes and emoji bursts.

## The montage, sentence by sentence

A story or meme video is cut the way these videos are cut by hand (`beats.py`): every SENTENCE of the script is a beat
with its own picture, chosen by the picture editor for what that sentence names and cut on its first spoken word.
- The picture model says where each subject of a picture is (`subjects`: s1, s2... with a position), so a beat can be
  `close` on the one the words name, `pan` from one to another, or show `two` of them in two labelled windows; a clip
  can `play` sharp or sit `under` a card; `whole` shows the whole picture.
- Graphics belong to a beat as well: they land on a word of that sentence and leave with its picture. A `name` graphic
  says who or what is on screen, low, without covering it; a card (quote, rows, receipt, blocks) on a sharp picture
  sits low and the subject is kept above it; a lyric shown line by line can run over two sentences.
- A sentence too short to read (under 0.8 s) shares the picture of the next one; the same picture framed the same way
  for two sentences in a row plays on as one shot.
- A meme page also gets moving GIFs of the meme from GIPHY's search (YouTube and TikTok often refuse a download).

## What is fixed by rule, not left to the AI

- Footage under every second; a video that is mostly one clip is refused. No white cards.
- Every kind of graphic has its own place (`build.js`), inside the part of the screen TikTok leaves free; text that is too
  wide is made smaller, never cut. The layout is then measured, not assumed.
- Quotes must be words a source contains; a highlighted phrase must be in the post; spoken numbers are shown as digits.
- A claim about wrongdoing must say who says it. A sensitive story waits for the owner.
- A clip found by search is trusted by its own title, never by the words that were searched.

## Settings: `config.json`

The AI models and their order (`ai.text` writes, `ai.reader` checks what was written and picks the opening, `ai.vision`
looks at pictures), the voice, the length, how much footage is enough, the layout
limits. Keys come from the environment: `GEMINI_API_KEY`, `GROQ_API_KEY`, `NVIDIA_API_KEY` (or `AI_PROBE_NVIDIA`),
`OPENROUTER_API_KEY`, `PEXELS_API_KEY`; also `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` if a stronger director is wanted
(add e.g. `"anthropic/<model>"` at the front of `ai.text`). On the owner's PC `localenv.py` reads them from his local
copy of the site's config; nothing is printed or written.

## Where it runs

- **The owner's PC**: everything, including YouTube footage searches. Needs Python with `edge-tts numpy pillow yt-dlp`,
  ffmpeg, and a headless browser (`local_browser_python` in `config.json` names the Python that has CloakBrowser).
- **GitHub (`v3-maker`)**: everything except YouTube (it refuses GitHub's machines; no cookies are used to get around
  that). Stories arrive on the `v3-feed` branch, results leave on `v3-drop` and as a run artifact.

Nothing here posts anything.

## When the AI is slow or out of quota

Free quotas run out (about ten requests a video). A model that fails is passed over for 15 minutes, the next one in
`ai.text` / `ai.vision` answers, and the plan step keeps its progress after every answer (`plan_progress.json`), so a
stopped run continues instead of starting again. If no model answers the repair round twice, the last plan stands and
what it left open is written in `report.json`. A script that comes out a little short is spoken a little slower.
