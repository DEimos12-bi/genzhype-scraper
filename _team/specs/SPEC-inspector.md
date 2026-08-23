# Spec: The Inspector (organ 11) — nothing false becomes a frame

Built 2026-08-23. Live. Driver: video 700 burned "JAN 1, 2024" as a dated receipt.

## What it does today

| Check | Catches | On failure |
|---|---|---|
| `insp_date_precision()` | a day the source never gave ("as early as 2024" -> 2024-01-01) | downgrade the label to month/year — never a day |
| `insp_chip()` / `insp_year()` | `date(strtotime('2024-00-00'))` = **"Nov 30, 2023"** — a fabricated, plausible date | render the honest partial label, or NO chip at all |
| framing enforcement | the voiceover rewriter stripping alleged/reportedly from unproven claims | fall back to the gate-verified description |

## The law

**Fail safe, never loud.** A failed check renders *nothing* rather than something wrong.
It may drop a field or a shot; it may never crash a tick, and it must never become a
second publish gate. *A false-positive Inspector is worse than no Inspector* — proven on
day one: a bare `around` matched "turned around" and would have destroyed a correct date
(42 hits, 33 wrong). Markers now only count when they point at a year or month: 42 -> 9.

## Not a guardrail store

The publish gate, the alleged/reportedly definition (`FR_FRAMING_RX`), CC0-only audio and
the dignity rules live in code elsewhere and are not tunable from here. The Inspector
*enforces* them on the video path; it cannot soften them.

## Evidence (live server, PHP 8.2)

```
2024-00-00  ->  strtotime: Nov 30, 2023   insp_chip: 2024
2024-08-00  ->  strtotime: Jul 31, 2024   insp_chip: Aug 2024
0000-00-00  ->  strtotime: Nov 30, -0001  insp_chip: (no chip)
2026-08-20  ->  strtotime: Aug 20, 2026   insp_chip: Aug 20, 2026
```

Unit tests: 11/11 precision (incl. 3 real false positives), 8/8 chip.

## Still open (audit backlog, not yet built)

Receipt/source text mismatch · index drift between `receipts[]` and `receipt_meta[]` ·
shotlist word anchors exceeding the script · gravity recomputed only at script time.
