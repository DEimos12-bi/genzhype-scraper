"""Laya bench on GitHub's machines (owner-approved 2026-09-24).

Laya (github.com/NandhaKishorM/laya, PyPI "laya", Apache-2.0, by Convai
Innovations) is a fast classifier: text + a typed question -> a decision with
a calibrated probability. It is NOT a code analyser (512-1,024 token window).

First job: the `guard` preset (prompt injection / jailbreak / leak) on
laya-bench/inputs.json - 6 known attacks and 12 clean texts, 10 of them real
captions and titles from our own pipeline - so its answers can be SCORED
against a known truth before anything in the pipeline relies on it.

Runs in a job with read-only repo access and no secrets (see laya.yml).
The package is days old and changes daily, so the API is probed defensively:
whatever it returns is recorded raw, and a failure writes the package's public
names so the next run can be corrected from evidence, not guesses.
"""
import json
import os
import sys
import time
import traceback

OUT = "laya-out"
os.makedirs(OUT, exist_ok=True)
_log = open(os.path.join(OUT, "run.log"), "a", encoding="utf-8")   # append: laya_eval.py imports this module


def say(*parts):
    line = " ".join(str(p) for p in parts)
    print(line, flush=True)
    _log.write(line + "\n")
    _log.flush()


def to_plain(obj, depth=0):
    """Any result object -> JSON-able data, so the raw answer is kept."""
    if depth > 5:
        return repr(obj)
    if obj is None or isinstance(obj, (str, int, float, bool)):
        return obj
    if isinstance(obj, dict):
        return {str(k): to_plain(v, depth + 1) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [to_plain(v, depth + 1) for v in obj]
    for name in ("model_dump", "to_dict", "_asdict", "dict"):
        fn = getattr(obj, name, None)
        if callable(fn):
            try:
                return to_plain(fn(), depth + 1)
            except Exception:  # noqa: BLE001
                pass
    if hasattr(obj, "__dict__"):
        return {k: to_plain(v, depth + 1) for k, v in vars(obj).items()
                if not k.startswith("_")}
    return repr(obj)


def main():
    t0 = time.time()
    results = {"items": [], "errors": []}
    try:
        import laya
    except Exception:  # noqa: BLE001
        say("IMPORT FAILED:\n" + traceback.format_exc())
        results["errors"].append(traceback.format_exc())
        return results
    results["version"] = getattr(laya, "__version__", "?")
    say("laya", results["version"], "| python", sys.version.split()[0])
    say("public names:", [n for n in dir(laya) if not n.startswith("_")])

    try:
        router = laya.Router()
        questions = laya.guard_questions()
    except Exception:  # noqa: BLE001
        say("SETUP FAILED:\n" + traceback.format_exc())
        results["errors"].append(traceback.format_exc())
        return results
    results["setup_seconds"] = round(time.time() - t0, 1)
    results["guard_questions"] = to_plain(questions)
    say(f"model ready in {results['setup_seconds']}s")
    say("guard questions:", json.dumps(results["guard_questions"])[:1500])

    items = json.load(open("laya-bench/inputs.json", encoding="utf-8"))
    for it in items:
        t = time.time()
        row = {"text": it["text"], "expect": it["expect"], "src": it.get("src", "")}
        try:
            answer = router.predict({"prompt": it["text"]}, questions)
            row["seconds"] = round(time.time() - t, 2)
            row["result"] = to_plain(answer)
            say(f"{row['seconds']:5.2f}s  expect={it['expect']:9s}  {it['text'][:70]!r}")
        except Exception:  # noqa: BLE001
            row["error"] = traceback.format_exc()
            say("PREDICT FAILED:", row["error"][-800:])
        results["items"].append(row)
    results["total_seconds"] = round(time.time() - t0, 1)
    say("done in", results["total_seconds"], "s")
    return results


if __name__ == "__main__":
    out = main()
    with open(os.path.join(OUT, "results.json"), "w", encoding="utf-8") as fh:
        json.dump(out, fh, indent=2, ensure_ascii=False)
