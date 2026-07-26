#!/usr/bin/env python3
"""Offline test for enforce_visual_variety (r33).

Case 1 replays the delivered El Risitas video: 18 scenes, one photo under 15 of
them, a pool with alternatives. Before r33 that shipped; it must not now.

Run:  python3 tools/variety_test.py
"""
import os
import re
import sys
import types

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(os.path.dirname(HERE), "video_maker.py")

# Pull the function out of the driver without importing it (the module needs
# numpy/requests/moviepy at import time; this test must run anywhere).
src = open(SRC, encoding="utf-8").read()
m = re.search(r"\ndef enforce_visual_variety\(.*?\n(?=\ndef )", src, re.S)
if not m:
    print("FAIL: enforce_visual_variety not found in video_maker.py")
    sys.exit(1)
mod = types.ModuleType("variety")
mod.__dict__["VISUAL_MAX_SHARE"] = 0.34
mod.__dict__["log"] = types.SimpleNamespace(info=lambda *a, **k: None)
exec(m.group(0), mod.__dict__)
enforce = mod.enforce_visual_variety


def share(scenes):
    counts = {}
    for s in scenes:
        if s.get("path") and s.get("type") != "broll":
            counts[s["path"]] = counts.get(s["path"], 0) + 1
    total = sum(counts.values())
    top, n = max(counts.items(), key=lambda kv: kv[1])
    return top, n, total, len(counts)


def case(name, scenes, alts, want_max_share):
    before = share(scenes)
    enforce(scenes, alts)
    top, n, total, distinct = share(scenes)
    ok = (n / float(total)) <= want_max_share + 1e-9
    print("%-42s before %d/%d -> after %d/%d (%d distinct)  %s"
          % (name, before[1], before[2], n, total, distinct,
             "PASS" if ok else "FAIL"))
    return ok


ok = True

# 1. the shipped El Risitas shape: one photo under 15 of 18 still scenes
scenes = [{"path": "risitas.jpg", "type": "photo"} for _ in range(15)]
scenes += [{"path": "wiki-card.png", "type": "receipt"},
           {"path": "dexerto-card.png", "type": "receipt"},
           {"path": "twitch-stock.jpg", "type": "photo"}]
ok &= case("el-risitas 15/18 on one photo", scenes,
           ["risitas.jpg", "risitas2.jpg", "risitas3.jpg", "risitas4.jpg",
            "wiki-card.png", "dexerto-card.png", "twitch-stock.jpg"], 0.40)

# 2. genuinely thin pool: nothing to swap to, must not crash or loop
scenes = [{"path": "only.jpg", "type": "photo"} for _ in range(8)]
enforce(scenes, ["only.jpg"])
print("%-42s survived a one-image pool                 PASS"
      % "thin pool (no alternatives)")

# 3. already varied: must not churn a good plan
scenes = [{"path": "a.jpg", "type": "photo"}, {"path": "b.jpg", "type": "photo"},
          {"path": "c.jpg", "type": "photo"}, {"path": "d.jpg", "type": "photo"},
          {"path": "a.jpg", "type": "photo"}, {"path": "b.jpg", "type": "photo"}]
snapshot = [s["path"] for s in scenes]
enforce(scenes, ["a.jpg", "b.jpg", "c.jpg", "d.jpg"])
unchanged = snapshot == [s["path"] for s in scenes]
print("%-42s %s" % ("already-varied plan left alone",
                    "PASS" if unchanged else "FAIL (churned a good plan)"))
ok &= unchanged

# 4. broll scenes are footage, not stills — never re-pointed
scenes = [{"path": "clip.mp4", "type": "broll"} for _ in range(6)]
scenes += [{"path": "p.jpg", "type": "photo"}]
enforce(scenes, ["p.jpg", "q.jpg"])
kept = all(s["path"] == "clip.mp4" for s in scenes if s["type"] == "broll")
print("%-42s %s" % ("broll left untouched", "PASS" if kept else "FAIL"))
ok &= kept

sys.exit(0 if ok else 1)
