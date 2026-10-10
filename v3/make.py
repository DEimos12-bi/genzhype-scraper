"""VIDEO SYSTEM v3: one story in, one finished TikTok video out, with nobody in the loop.
    python make.py <work folder> --url https://genzhype.com/...      (the owner's PC: reads the public page)
    python make.py <work folder>                                     (a material.json is already in the folder: the server feed)
Steps, each one a file in this folder; a run can be stopped and started again, finished steps are kept:
    material -> gather (gather.py: every piece the page gives, fetched and looked at BEFORE a word is written) -> plan (director.py: the casting, the
    script, the pictures) -> footage (footage.py: the hunts) -> eyes (eyes.py) -> repair (a sentence whose footage never came is dropped) -> voice (tts.py) -> receipts (receipts.py)
    -> site (site.py: the page on a PC, a tablet and a phone, for the closing) -> cut (shots.py) -> layout (render.py + check.py, repaired) -> gate (check.py) -> frames (render.py, in batches)
    -> sound (audio.py) -> pack (mp4, cover.jpg, post.txt, report.json in <work>/out)
Options: --steps a,b,c  only these steps      --from STEP  this step and the ones after it      --approved  the owner read
the plan of a sensitive story      --budget N  seconds a step may spend before it stops to be run again (frames, footage)
Nothing is posted anywhere. Exit code 0 = video made, 2 = refused (the reason is printed and written to report.json),
3 = a step stopped at its time budget: run the same command again."""
import json
import os
import shutil
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import ai          # noqa: E402
import localenv    # noqa: E402

STEPS = ['material', 'gather', 'plan', 'footage', 'eyes', 'repair', 'voice', 'receipts', 'site', 'cut', 'layout', 'gate', 'frames', 'sound', 'pack']
BATCH = 450


def say(*a):
    print(*a, flush=True)


def browser_python():
    """The Python that can open a browser: this one, or on the owner's PC the one named in config.json (CloakBrowser lives there)."""
    alt = ai.CONFIG.get('local_browser_python', '')
    return alt if alt and os.path.isfile(alt) and not os.environ.get('GITHUB_ACTIONS') else sys.executable


def py(script, *args, timeout=None):
    exe = browser_python() if script in ('render.py', 'receipts.py', 'site.py') else sys.executable
    try:
        r = subprocess.run([exe, os.path.join(HERE, script)] + [str(a) for a in args], timeout=timeout)
    except subprocess.TimeoutExpired:                          # what it had finished is saved: the same command goes on from there
        print('%s did not finish in %d s and was stopped' % (script, timeout), flush=True)
        return 3
    return r.returncode


def done(work, step):
    f = {'material': 'material.json', 'plan': 'plan.json', 'voice': 'timeline.json', 'cut': 'comp.json', 'sound': 'mix.wav'}.get(step)
    if f:
        return os.path.isfile(os.path.join(work, f))
    marks = json.load(open(os.path.join(work, 'steps.json'))) if os.path.isfile(os.path.join(work, 'steps.json')) else {}
    return bool(marks.get(step))


def mark(work, step, value=True):
    p = os.path.join(work, 'steps.json')
    marks = json.load(open(p)) if os.path.isfile(p) else {}
    marks[step] = value
    json.dump(marks, open(p, 'w'))


def refuse(work, why):
    os.makedirs(os.path.join(work, 'out'), exist_ok=True)
    json.dump({'made': False, 'why': why, 'ai': ai.LOG}, open(os.path.join(work, 'out', 'report.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    say('REFUSED:', '; '.join(why) if isinstance(why, list) else why)
    sys.exit(2)


def frames(work, budget):
    """Draws and encodes the picture in batches (a batch is deleted once it is a video segment: little disk is used)."""
    comp = json.load(open(os.path.join(work, 'comp.json'), encoding='utf-8'))
    total, t0 = comp['frames'], time.time()
    seg = os.path.join(work, 'segments'); os.makedirs(seg, exist_ok=True)
    for a in range(0, total, BATCH):
        b, out = min(total, a + BATCH), os.path.join(seg, 's%05d.mp4' % a)
        if os.path.isfile(out):
            continue
        if budget and time.time() - t0 > budget:
            return False
        shutil.rmtree(os.path.join(work, 'frames'), ignore_errors=True)
        if py('render.py', work, 'frames', a, b) != 0:
            refuse(work, 'the picture could not be drawn (frames %d to %d): see render_errors.txt' % (a, b))
        subprocess.run(['ffmpeg', '-y', '-v', 'error', '-framerate', '30', '-start_number', str(a), '-i', os.path.join(work, 'frames', 'f%05d.jpg'), '-frames:v', str(b - a),
                        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-pix_fmt', 'yuv420p', '-r', '30', out + '.tmp.mp4'], check=True)
        os.replace(out + '.tmp.mp4', out)
        shutil.rmtree(os.path.join(work, 'frames'), ignore_errors=True)
        say('frames %d-%d of %d done (%.0f s so far)' % (a, b, total, time.time() - t0))
    return True


def pack(work):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    comp = json.load(open(os.path.join(work, 'comp.json'), encoding='utf-8'))
    out = os.path.join(work, 'out'); os.makedirs(out, exist_ok=True)
    slug = (plan['url'].rstrip('/').split('/')[-1] or 'video')[:70]
    mp4 = os.path.join(out, 'genzhype-%s.mp4' % slug)
    seg = os.path.join(work, 'segments')
    lst = os.path.join(seg, 'list.txt')
    open(lst, 'w').write(''.join("file '%s'\n" % f for f in sorted(os.listdir(seg)) if f.endswith('.mp4')))
    subprocess.run(['ffmpeg', '-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', lst, '-i', os.path.join(work, 'mix.wav'), '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
                    '-movflags', '+faststart', '-shortest', mp4], check=True)
    stamp_t = next((c[0] for c in comp['cues']['impact'] if c[0] > 0.3), 1.5) + 0.6          # the cover: the first big word, once it has landed
    subprocess.run(['ffmpeg', '-y', '-v', 'error', '-ss', '%.2f' % stamp_t, '-i', mp4, '-frames:v', '1', '-q:v', '2', os.path.join(out, 'cover.jpg')], check=True)
    dur = float(subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp4], capture_output=True, text=True).stdout.strip() or 0)
    post, used = plan.get('post') or {}, sorted({s['asset'] for s in comp['shots']})
    credits = '\n'.join('- %s: %s  %s' % (k, plan['assets'][k].get('credit') or 'stock footage (Pexels), blurred', plan['assets'][k].get('page', '')) for k in used if k in plan['assets']) \
        or '- none: this video is drawn (motion graphics), no outside footage'
    proof = '\n'.join('- %s' % (o.get('t') or 'the post of ' + json.load(open(os.path.join(work, 'material.json'), encoding='utf-8'))['posts'][int(o['post'][4:])]['url'])
                      for l in plan['lines'] for o in l['overlays'] if o['k'] in ('quote', 'receipt') and not o.get('drop'))
    open(os.path.join(out, 'post.txt'), 'w', encoding='utf-8').write(
        'VIDEO   %s   (%.1f s, 1080x1920)\nPAGE    %s\n\nCAPTION\n%s\n\nHASHTAGS\n%s\n\nPINNED COMMENT\n%s\n\nTHE SCRIPT\n%s\n\nPROOF SHOWN\n%s\n\nFOOTAGE\n%s\n\nVoice: edge-tts. Music and effects: made in code for this video.\nWritten by %s; clips looked at by the picture model before cutting. Nobody checked this video: watch it once before posting.\n'
        % (os.path.basename(mp4), dur, plan['url'], post.get('caption', ''), ' '.join('#' + str(t).lstrip('#') for t in post.get('hashtags', [])), post.get('pinned', ''),
           '\n'.join(l['text'] for l in plan['lines']), proof or '- (none)', credits, plan.get('model', '?')))
    report = {'made': True, 'file': os.path.basename(mp4), 'seconds': round(dur, 1), 'words': plan.get('words'), 'shots': len(comp['shots']), 'assets_used': used,
              'director_model': plan.get('model'), 'opening': plan.get('opening'), 'fact_checked': plan.get('fact_checked'), 'unsupported_left': plan.get('unsupported_left'), 'left_open': plan.get('left_open', []), 'sensitive': plan.get('sensitive'),
              'ai_calls': [json.loads(x) for x in open(os.environ['V3_AI_LOG'], encoding='utf-8')] if os.path.isfile(os.environ.get('V3_AI_LOG', '')) else []}
    json.dump(report, open(os.path.join(out, 'report.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    shutil.copy(os.path.join(work, 'plan.json'), os.path.join(out, 'plan.json'))
    say('DONE %s  %.1f s' % (mp4, dur))


def main():
    args = sys.argv[1:]
    work = os.path.abspath(args[0])
    opt = lambda name: args[args.index(name) + 1] if name in args else None
    os.makedirs(work, exist_ok=True)
    localenv.load()
    os.environ['V3_AI_LOG'] = os.path.join(work, 'ai_log.jsonl')
    os.environ['V3_AI_STRIKES'] = os.path.join(os.path.dirname(os.path.abspath(work)), 'ai_strikes.json')   # one file for every run: a model that is out stays aside in the next run too
    steps = STEPS
    if opt('--steps'):
        steps = [s for s in STEPS if s in opt('--steps').split(',')]
    elif opt('--from'):
        steps = STEPS[STEPS.index(opt('--from')):]
        for s in steps:
            mark(work, s, False)
    budget = float(opt('--budget')) if opt('--budget') else None
    forced = bool(opt('--steps') or opt('--from'))
    for f in ('comp.html', 'engine.js', 'engine.css', 'build.js', 'slang.js'):
        shutil.copy(os.path.join(HERE, f), os.path.join(work, f))
    shutil.copytree(os.path.join(HERE, 'fonts'), os.path.join(work, 'fonts'), dirs_exist_ok=True)
    began = time.time()
    for step in steps:
        if not forced and done(work, step):
            continue
        if budget and time.time() - began > budget:            # a caller with a time limit: stop between steps, the same command continues
            say('STOPPED at the time budget before "%s": run the same command again' % step); sys.exit(3)
        t = time.time()
        say('== %s' % step)
        if step == 'material':
            if not os.path.isfile(os.path.join(work, 'material.json')):
                if not opt('--url'):
                    refuse(work, 'no material.json in the folder and no --url given')
                if py('material.py', opt('--url'), work, timeout=180) != 0:
                    refuse(work, 'the page could not be read')
        elif step == 'gather':
            rc = py('gather.py', work, *([budget] if budget else []), timeout=900)
            if rc == 3:
                say('STOPPED at the time budget: run the same command again'); sys.exit(3)
            if rc != 0:
                refuse(work, 'the material could not be gathered (see the lines above)')
            mark(work, step)
        elif step == 'plan':
            rc = py('director.py', work, *([budget] if budget else []), timeout=900)
            if rc == 3:
                say('STOPPED at the time budget: run the same command again'); sys.exit(3)
            if rc != 0:
                why = os.path.join(work, 'plan_refused.txt')
                refuse(work, open(why, encoding='utf-8').read() if os.path.isfile(why) else 'the director gave no usable plan (see the lines above)')
        elif step == 'voice':
            rc = py('tts.py', work, *([max(budget, 60)] if budget else []), timeout=900)
            if rc == 3:
                say('STOPPED at the time budget: run the same command again'); sys.exit(3)
            if rc != 0:
                refuse(work, 'the voice could not be made')
        elif step in ('footage', 'eyes', 'repair', 'receipts') and json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8')).get('format') == 'slang':
            say('(the slang format is drawn: no footage, nothing to look at, no posts to photograph)'); mark(work, step)
        elif step == 'footage':
            rc = py('footage.py', work, *([max(budget, 75)] if budget else []))      # a download needs room: under 40 s left it would never start one
            if rc == 3:
                say('STOPPED at the time budget: run the same command again'); sys.exit(3)
            if rc != 0:                                        # a crash is not "done": it is said, and the step is run again next time
                refuse(work, 'the footage step failed (exit %d): see the lines above' % rc)
            mark(work, step)
        elif step == 'eyes':
            py('eyes.py', work, timeout=900); mark(work, step)
        elif step == 'repair':
            py('repair.py', work, timeout=300); mark(work, step)
        elif step == 'receipts':
            py('receipts.py', work, timeout=400); mark(work, step)
        elif step == 'site':
            py('site.py', work, timeout=300); mark(work, step)
        elif step == 'cut':
            if py('shots.py', work, timeout=1500) != 0:
                refuse(work, 'no footage to cut: nothing usable was fetched for this story')
        elif step == 'layout':
            import check
            for round_ in range(3):
                if py('render.py', work, 'layout', timeout=600) != 0:
                    refuse(work, 'the layout could not be read: see render_errors.txt')
                n = check.repair_layout(work, say)
                say('layout check %d: %s' % (round_ + 1, '%d problems repaired' % n if n else 'clean'))
                if not n:
                    break
            mark(work, step)
        elif step == 'gate':
            import check
            why = check.gate(work, allow_sensitive='--approved' in args)
            if why:
                refuse(work, why)
            mark(work, step)
        elif step == 'frames':
            if not frames(work, budget):
                say('STOPPED at the time budget: run the same command again'); sys.exit(3)
            mark(work, step)
        elif step == 'sound':
            if py('audio.py', work, timeout=600) != 0:
                refuse(work, 'the sound could not be mixed')
        elif step == 'pack':
            pack(work); mark(work, step)
        say('== %s done in %.0f s' % (step, time.time() - t))


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main()
