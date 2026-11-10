"""Run by scenes.py in the tools' own Python (the one that has PySceneDetect): the seconds where a clip cuts, as one
line of JSON. The adaptive detector compares each frame with the ones around it, so a fight's flashes and a fast
camera are not taken for cuts; its floor is lowered because game footage is often dark.  usage: scenes_run.py <clip>"""
import json
import sys

from scenedetect import AdaptiveDetector, detect


def sec(tc):
    s = getattr(tc, 'seconds', None)
    return float(s) if s is not None and not callable(s) else float(tc.get_seconds())


if __name__ == '__main__':
    found = detect(sys.argv[1], AdaptiveDetector(adaptive_threshold=2.4, min_scene_len=10, min_content_val=8))
    print(json.dumps([round(sec(a), 2) for a, _ in found][1:]))
