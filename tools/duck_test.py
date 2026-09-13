#!/usr/bin/env python3
"""GenZHype | duck_test (r137) — offline proof that _duck_to_vo rides the bed.

Builds a synthetic voice (1s speech-tone, 1s pause, 1s speech-tone) and a
constant bed tone, runs the duck, and asserts the bed under speech is
~DUCK_DB quieter than the bed in the pause. Pure pydub generators — no
ffmpeg, no network, no repo state; runs anywhere pydub+numpy exist.
Failure = nonzero exit; the CI reliability rule is that sound never ships
unproven."""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from pydub import AudioSegment  # noqa: E402
from pydub.generators import Sine  # noqa: E402

import video_maker as vm  # noqa: E402


def rms_db(seg):
    return seg.dBFS if seg.dBFS != float("-inf") else -120.0


def main():
    speech = Sine(440).to_audio_segment(duration=1000, volume=-18).set_frame_rate(44100)
    pause = AudioSegment.silent(duration=1000, frame_rate=44100)
    vo = speech + pause + speech
    bed = (Sine(220).to_audio_segment(duration=1500, volume=-24)
           .set_frame_rate(44100)) * 2            # 3s constant bed

    for channels in (1, 2):
        b = bed.set_channels(channels)
        out, sp_frac = vm._duck_to_vo(b, vo)
        assert len(out) == len(b), f"ch{channels}: length changed {len(out)}!={len(b)}"
        assert out.channels == channels, f"ch{channels}: channels changed"
        # middle 500ms of each speech region, and a pause window that starts
        # clear of the deliberate 450ms release ramp (slow recover = design)
        under_speech = rms_db(out[250:750])
        under_speech2 = rms_db(out[2250:2750])
        under_pause = rms_db(out[1400:1900])
        dip = under_pause - under_speech
        dip2 = under_pause - under_speech2
        print(f"ch{channels}: speech {under_speech:.1f} / {under_speech2:.1f} dBFS, "
              f"pause {under_pause:.1f} dBFS -> dips {dip:.1f} / {dip2:.1f} dB "
              f"(target {vm.DUCK_DB:.0f}, speech {sp_frac*100:.0f}%)")
        for d in (dip, dip2):
            assert vm.DUCK_DB - 1.5 <= d <= vm.DUCK_DB + 2.0, \
                f"ch{channels}: dip {d:.1f}dB outside tolerance around {vm.DUCK_DB}dB"
        assert under_pause >= rms_db(b[1400:1900]) - 0.8, \
            f"ch{channels}: pause bed lost level ({under_pause} vs original)"
    # short-VO guard: ducking a bed with no meaningful voice = unchanged
    out_short, sp = vm._duck_to_vo(bed, vo[:30])
    assert out_short is bed and sp == 0.0, "short-vo guard failed"
    print("duck_test: PASS (mono+stereo dips within tolerance, guards hold)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
