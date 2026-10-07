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
| material | `material.py` | reads the page: its text, the posts it cites (text, pictures, videos, through X's public embed data) and the outlets' text |
| plan | `director.py` | the AI writes the script and what is on screen for each line; code checks every rule; a second AI reading checks every statement against the material; two repair rounds; a plan that still breaks a hard rule is refused |
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

## What is fixed by rule, not left to the AI

- Footage under every second; a video that is mostly one clip is refused. No white cards.
- Every kind of graphic has its own place (`build.js`), inside the part of the screen TikTok leaves free; text that is too
  wide is made smaller, never cut. The layout is then measured, not assumed.
- Quotes must be words a source contains; a highlighted phrase must be in the post; spoken numbers are shown as digits.
- A claim about wrongdoing must say who says it. A sensitive story waits for the owner.
- A clip found by search is trusted by its own title, never by the words that were searched.

## Settings: `config.json`

The AI models and their order (`ai.text`, `ai.vision`), the voice, the length, how much footage is enough, the layout
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
