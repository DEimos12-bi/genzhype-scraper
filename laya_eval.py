"""Scored Laya benches: does its answer line up with a known truth?

Each laya-bench/<name>.json that carries "questions" is one bench:
  {"positive": <label that counts as good>, "questions": {q: {type, instructions}},
   "items": [{"label": ..., "state": {...}}]}
For every yes/no question the bench reports AUC - the chance that a randomly
picked GOOD item scores higher than a randomly picked BAD one. 0.50 is a coin
flip, 1.00 is perfect. AUC is used instead of accuracy because the classes are
unequal (54 ok / 35 bad) and it needs no threshold choice.

First benches (2026-09-24): the owner's own ok/bad verdicts on 89 videos, and
TikTok hooks from the top third vs the bottom third by views. The two AI video
judges scored 36% agreement / 0 of 35 bad videos caught on the same verdicts.
"""
import glob
import json
import os
import time
import traceback

from laya_run import say, to_plain

OUT = "laya-out"
os.makedirs(OUT, exist_ok=True)


def auc(scores, labels):
    pos = [s for s, lab in zip(scores, labels) if lab]
    neg = [s for s, lab in zip(scores, labels) if not lab]
    if not pos or not neg:
        return None
    wins = 0.0
    for p in pos:
        for n in neg:
            wins += 1.0 if p > n else (0.5 if p == n else 0.0)
    return round(wins / (len(pos) * len(neg)), 3)


def main():
    import laya
    t0 = time.time()
    router = laya.Router()
    say(f"model ready in {time.time() - t0:.1f}s")
    summary = {}
    for path in sorted(glob.glob("laya-bench/*.json")):
        bench = json.load(open(path, encoding="utf-8"))
        if not isinstance(bench, dict) or "questions" not in bench:
            continue
        name = os.path.splitext(os.path.basename(path))[0]
        qs = bench["questions"]
        rows = []
        tb = time.time()
        for it in bench["items"]:
            row = {"page_id": it.get("page_id"), "label": it["label"]}
            try:
                ans = to_plain(router.predict(it["state"], qs)).get("answers", {})
                row["p"] = {q: ans.get(q, {}).get("noul") for q in qs}
            except Exception:  # noqa: BLE001
                row["error"] = traceback.format_exc()[-600:]
            rows.append(row)
        ok_rows = [r for r in rows if "p" in r]
        labels = [r["label"] == bench["positive"] for r in ok_rows]
        per_q = {}
        for q in qs:
            sc = [r["p"][q] for r in ok_rows if r["p"].get(q) is not None]
            lb = [lab for r, lab in zip(ok_rows, labels) if r["p"].get(q) is not None]
            per_q[q] = auc(sc, lb)
        summary[name] = {"items": len(rows), "scored": len(ok_rows),
                         "errors": len(rows) - len(ok_rows),
                         "seconds": round(time.time() - tb, 1), "auc": per_q}
        say(f"{name}: {len(ok_rows)}/{len(rows)} scored in {summary[name]['seconds']}s  AUC {per_q}")
        with open(os.path.join(OUT, f"{name}.json"), "w", encoding="utf-8") as fh:
            json.dump({"summary": summary[name], "rows": rows}, fh, indent=1, ensure_ascii=False)
    with open(os.path.join(OUT, "summary.json"), "w", encoding="utf-8") as fh:
        json.dump(summary, fh, indent=2)


if __name__ == "__main__":
    try:
        main()
    except Exception:  # noqa: BLE001
        say("EVAL FAILED:\n" + traceback.format_exc())
