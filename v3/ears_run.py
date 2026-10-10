"""The speech model, run in the voice's own Python (the one that has faster-whisper): hears the first seconds of each
file and writes the words with their times back into the jobs file. One model load for all files.
usage: ears_run.py <jobs.json>   jobs: [{"file": path, "max_s": 90}]  ->  each gets "lang" and "words": [[word, start, end]]"""
import json
import os
import subprocess
import sys
import tempfile

from faster_whisper import WhisperModel


def main(jobs_file):
    jobs = json.load(open(jobs_file, encoding='utf-8'))
    model = WhisperModel(os.environ.get('V3_EARS_MODEL', 'base'), device='cpu', compute_type='int8')
    for j in jobs:
        wav = os.path.join(tempfile.gettempdir(), 'ears_%d.wav' % os.getpid())
        subprocess.run(['ffmpeg', '-y', '-v', 'error', '-i', j['file'], '-t', str(j.get('max_s', 90)), '-vn', '-ac', '1', '-ar', '16000', wav], check=False, timeout=120)
        words, lang = [], ''
        if os.path.isfile(wav) and os.path.getsize(wav) > 8000:
            segs, info = model.transcribe(wav, word_timestamps=True, vad_filter=True, beam_size=3)
            lang = info.language if info.language_probability >= 0.6 else ''
            for s in segs:
                for w in s.words or []:
                    t = w.word.strip()
                    if t and w.probability >= 0.25:
                        words.append([t, round(float(w.start), 2), round(float(w.end), 2)])
            os.remove(wav)
        j['lang'], j['words'] = lang, words
        json.dump(jobs, open(jobs_file, 'w', encoding='utf-8'), ensure_ascii=False)
    print(len(jobs))


if __name__ == '__main__':
    main(sys.argv[1])
