"""Laya, REPORT-ONLY (owner 2026-10-01, step 3): two text questions, answered blind, logged next to the real decision
on the server (app/laya_shadow.php). It decides nothing.

Reads .social/laya-shadow/feed.json (the server's export: items with a `kind`, a `ref_id` and a `state`; the real
decision is NOT in the feed). Asks each item its kind's question (feed["questions"][kind], bench format) and writes
laya-out/shadow/answers.json: {"made_at", "answers": [{"kind", "ref_id", "yes", "confidence"}], "errors": [...]}.
The publish job pushes laya-out to the laya-drop branch; genzhype-laya-bridge.sh brings it to the server.
"""
import json
import os
import sys
import time
import traceback

FEED = ".social/laya-shadow/feed.json"
OUT = "laya-out/shadow"


def say(*parts):
    print(*parts, flush=True)


def to_plain(obj, depth=0):
    if depth > 6:
        return str(obj)
    if isinstance(obj, dict):
        return {str(k): to_plain(v, depth + 1) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [to_plain(v, depth + 1) for v in obj]
    if isinstance(obj, (str, int, float, bool)) or obj is None:
        return obj
    if hasattr(obj, "__dict__"):
        return to_plain(vars(obj), depth + 1)
    return str(obj)


def main():
    os.makedirs(OUT, exist_ok=True)
    out = {"made_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "answers": [], "errors": []}
    if not os.path.isfile(FEED):
        say("no shadow feed; nothing to ask")
        return out
    feed = json.load(open(FEED, encoding="utf-8"))
    questions = feed.get("questions", {})
    items = feed.get("items", [])
    say(f"{len(items)} item(s) to ask")
    try:
        import laya
        t0 = time.time()
        router = laya.Router()
        say(f"model ready in {time.time() - t0:.1f}s")
    except Exception:  # noqa: BLE001
        out["errors"].append(traceback.format_exc()[-800:])
        say("SETUP FAILED:\n" + out["errors"][-1])
        return out
    t0 = time.time()
    for n, it in enumerate(items, 1):
        qs = questions.get(it.get("kind"), {})
        if not qs:
            continue
        qname = next(iter(qs))
        try:
            ans = to_plain(router.predict(it["state"], qs)).get("answers", {}).get(qname, {})
            p = ans.get("noul")
            if p is None:
                raise ValueError(f"no noul answer: {json.dumps(ans)[:200]}")
            p = float(p)
            out["answers"].append({"kind": it["kind"], "ref_id": it["ref_id"], "yes": p >= 0.5, "confidence": round(max(p, 1 - p), 3), "p_yes": round(p, 3)})
        except Exception:  # noqa: BLE001
            out["errors"].append({"ref_id": it.get("ref_id"), "error": traceback.format_exc()[-400:]})
        if n % 25 == 0:
            say(f"{n}/{len(items)} asked, {time.time() - t0:.0f}s")
        if time.time() - t0 > 1500:   # the job has 30 minutes: the rest waits for the next feed
            out["errors"].append({"stopped": f"time limit after {n} items"})
            break
    say(f"done: {len(out['answers'])} answer(s), {len(out['errors'])} error(s) in {time.time() - t0:.0f}s")
    return out


if __name__ == "__main__":
    result = main()
    with open(os.path.join(OUT, "answers.json"), "w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=1, ensure_ascii=False)
