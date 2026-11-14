"""Every word said in the clips of duo/store, with its start and end time (faster-whisper, word timestamps)."""
import glob, json, os, subprocess
from faster_whisper import WhisperModel
out = 'v3/work/duo/out'; os.makedirs(out, exist_ok=True)
model = WhisperModel('medium.en', device='cpu', compute_type='int8')
res = {}
for mp4 in sorted(glob.glob('duo/store/*.mp4')):
    meta = json.load(open(mp4[:-4] + '.json')) if os.path.isfile(mp4[:-4] + '.json') else {}
    wav = mp4[:-4] + '.wav'
    subprocess.run(['ffmpeg', '-y', '-v', 'error', '-i', mp4, '-ac', '1', '-ar', '16000', wav], check=True)
    segs, info = model.transcribe(wav, language='en', word_timestamps=True, vad_filter=False, beam_size=5, condition_on_previous_text=False)
    words, lines = [], []
    for s in segs:
        lines.append({'s': round(s.start, 2), 'e': round(s.end, 2), 'text': s.text.strip()})
        for w in s.words or []:
            words.append({'w': w.word.strip(), 's': round(w.start, 2), 'e': round(w.end, 2), 'p': round(w.probability, 2)})
    res[os.path.basename(mp4)] = {'author': meta.get('author'), 'title': meta.get('title'), 'lines': lines, 'words': words}
    print('==', os.path.basename(mp4), meta.get('author'))
    for l in lines: print('  %5.2f-%5.2f  %s' % (l['s'], l['e'], l['text']))
json.dump(res, open(out + '/transcripts.json', 'w'), indent=1)
