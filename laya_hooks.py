"""r190 HOOK LEARNING LOOP - the learning half, on GitHub's machines.

Input  laya-feed/feed.json (pushed by the server, app/laya_hooks.php):
         train: posted hooks + label = how the video did (rank within each
                platform, averaged over the platforms it went to, 0..1)
         score: the hooks of videos still waiting (current + alternatives)
Output laya-out/hooks/report.json   what was learned and how well it tests
       laya-out/hooks/scores.json   a 0..1 score for every waiting hook
       laya-out/hooks/features.json cache, so old hooks are not re-read

WHY A SMALL MODEL ON TOP OF LAYA, NOT A RETRAINED LAYA: with ~100 labelled
hooks, retraining a 421M-parameter model would memorise them. Laya answers a
few questions about each hook (would it stop the scroll? does it create
curiosity? is it specific?), plain text facts are added (length, a number, a
question mark, capitals), and a logistic regression learns which of those
actually go with views. It is tested only on hooks it did not train on
(repeated 5-fold cross-validation) and replaces Laya's raw "stops the scroll"
answer ONLY if it tests clearly better. Zero-shot baseline measured
2026-09-24: AUC 0.653 on TikTok top vs bottom third.
"""
import json
import os
import re
import statistics
import time
import traceback

from laya_run import say, to_plain

FEED = "laya-feed/feed.json"
PREV = "laya-prev/hooks/features.json"
OUT = "laya-out/hooks"
MARGIN = 0.02          # the model must beat the zero-shot baseline by this much
QUESTIONS = {
    "stops_scroll": {"type": "noul", "instructions": "Would the on-screen hook `hook` make a Gen Z viewer stop scrolling on TikTok?"},
    "curiosity": {"type": "noul", "instructions": "Does `hook` create curiosity or tension that makes you need the answer?"},
    "specific": {"type": "noul", "instructions": "Does `hook` name a specific person, number or event, rather than make a vague claim?"},
}
TEXT_FEATURES = ["n_words", "has_digit", "has_question", "caps_ratio", "has_money"]


def text_features(h):
    letters = [c for c in h if c.isalpha()]
    return {
        "n_words": len(h.split()),
        "has_digit": 1.0 if re.search(r"\d", h) else 0.0,
        "has_question": 1.0 if "?" in h else 0.0,
        "caps_ratio": (sum(c.isupper() for c in letters) / len(letters)) if letters else 0.0,
        "has_money": 1.0 if re.search(r"[$£€]|\bmillion\b|\bk\b", h, re.I) else 0.0,
    }


def auc(scores, labels):
    pos = [s for s, lab in zip(scores, labels) if lab]
    neg = [s for s, lab in zip(scores, labels) if not lab]
    if not pos or not neg:
        return None
    wins = sum(1.0 if p > n else 0.5 if p == n else 0.0 for p in pos for n in neg)
    return wins / (len(pos) * len(neg))


def main():
    if not os.path.exists(FEED):
        say("hook loop: no feed on the laya-feed branch yet; nothing to learn")
        return
    os.makedirs(OUT, exist_ok=True)
    feed = json.load(open(FEED, encoding="utf-8"))
    train, pending = feed.get("train", []), feed.get("score", [])
    cache = json.load(open(PREV, encoding="utf-8")) if os.path.exists(PREV) else {}
    say(f"hook loop: {len(train)} labelled hooks, {len(pending)} to score, {len(cache)} cached")

    import laya
    router = laya.Router()
    t0 = time.time()
    todo = {h for h in [r["hook"] for r in train] + [r["hook"] for r in pending] if h not in cache}
    for h in sorted(todo):
        try:
            ans = to_plain(router.predict({"hook": h}, QUESTIONS)).get("answers", {})
            cache[h] = {q: ans.get(q, {}).get("noul") for q in QUESTIONS}
        except Exception:  # noqa: BLE001
            say("laya failed on", repr(h[:60]), traceback.format_exc()[-300:])
    say(f"read {len(todo)} new hook(s) in {time.time() - t0:.0f}s")
    json.dump(cache, open(os.path.join(OUT, "features.json"), "w", encoding="utf-8"), ensure_ascii=False)

    names = list(QUESTIONS) + TEXT_FEATURES

    def vec(h):
        f = dict(cache.get(h) or {})
        f.update(text_features(h))
        return [float(f.get(n) if f.get(n) is not None else 0.5) for n in names]

    rows = [r for r in train if r["hook"] in cache]
    median = statistics.median(r["label"] for r in rows)
    X = [vec(r["hook"]) for r in rows]
    y = [1 if r["label"] >= median else 0 for r in rows]
    zero = [cache[r["hook"]]["stops_scroll"] for r in rows]
    report = {"date": feed.get("generated"), "n_train": len(rows), "median_label": median,
              "zero_shot_auc": round(auc(zero, y), 3), "features": names}

    import numpy as np
    from sklearn.linear_model import LogisticRegression
    from sklearn.model_selection import RepeatedStratifiedKFold
    from sklearn.pipeline import make_pipeline
    from sklearn.preprocessing import StandardScaler

    Xa, ya = np.array(X), np.array(y)
    fold_aucs, fold_zero = [], []
    for tr, te in RepeatedStratifiedKFold(n_splits=5, n_repeats=10, random_state=7).split(Xa, ya):
        m = make_pipeline(StandardScaler(), LogisticRegression(C=0.5, max_iter=500))
        m.fit(Xa[tr], ya[tr])
        fold_aucs.append(auc(list(m.predict_proba(Xa[te])[:, 1]), list(ya[te])))
        fold_zero.append(auc([zero[i] for i in te], list(ya[te])))
    report["model_cv_auc"] = round(float(np.mean(fold_aucs)), 3)
    report["zero_shot_cv_auc"] = round(float(np.mean(fold_zero)), 3)
    use_model = report["model_cv_auc"] >= report["zero_shot_cv_auc"] + MARGIN
    report["scorer"] = "learned model" if use_model else "zero-shot stops_scroll"

    final = make_pipeline(StandardScaler(), LogisticRegression(C=0.5, max_iter=500)).fit(Xa, ya)
    lr = final.named_steps["logisticregression"]
    report["weights"] = {n: round(float(w), 3) for n, w in zip(names, lr.coef_[0])}
    say(f"tested on unseen hooks: model AUC {report['model_cv_auc']} vs zero-shot "
        f"{report['zero_shot_cv_auc']} -> scorer = {report['scorer']}")
    say("what the model learned (positive = goes with more views):", report["weights"])

    scores = []
    for r in pending:
        if r["hook"] not in cache:
            continue
        s = (float(final.predict_proba(np.array([vec(r["hook"])]))[0, 1]) if use_model
             else float(cache[r["hook"]]["stops_scroll"] or 0.0))
        scores.append({**r, "score": round(s, 3)})
    json.dump(scores, open(os.path.join(OUT, "scores.json"), "w", encoding="utf-8"), indent=1, ensure_ascii=False)
    json.dump(report, open(os.path.join(OUT, "report.json"), "w", encoding="utf-8"), indent=2)
    say(f"scored {len(scores)} waiting hook(s)")


if __name__ == "__main__":
    try:
        main()
    except Exception:  # noqa: BLE001
        say("HOOK LOOP FAILED:\n" + traceback.format_exc())
