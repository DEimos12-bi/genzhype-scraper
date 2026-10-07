"""v2 MAKE: one story's plan -> one finished video, on the GitHub runner.
usage: make.py <workdir>   (<workdir>/plan.json + assets/ in; <workdir>/out/<slug>.mp4, cover.jpg, post.txt, plan.json out)
Steps: voice (tts.py) -> receipts (receipts.py) -> shots (shots.py) -> frames (render.py) -> sound (audio.py) -> ffmpeg."""
import json
import os
import shutil
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))


def run(cmd, label):
    t = time.time()
    print('== %s: %s' % (label, ' '.join(cmd)), flush=True)
    r = subprocess.run(cmd)
    print('== %s done in %.0fs (exit %d)' % (label, time.time() - t, r.returncode), flush=True)
    if r.returncode != 0:
        raise SystemExit('%s failed' % label)


def post_text(plan, out_mp4, dur):
    p = plan.get('post', {})
    tags = ' '.join('#' + t.lstrip('#') for t in p.get('hashtags', []))
    facts = '\n'.join('- %s: %s' % (f.get('claim', ''), f.get('source', '')) for f in p.get('facts', []))
    credits = '\n'.join('- %s: %s' % (k, a.get('credit', '')) for k, a in plan['assets'].items() if a.get('credit'))
    special = plan.get('special', {})
    return ('TIKTOK POST — %s (%s format)\nFile: %s (1080x1920, %.1f s) · Cover: cover.jpg\n\nCAPTION\n%s\n%s\n\nPINNED COMMENT (post it yourself right after upload)\n%s\n\nLINK IN BIO must point to\n%s\n\nTHE DIRECTOR\'S CHOICES\n- Format: %s (%s)\n- Special moment: %s (%s)\n- First second: %s + "%s"\n- Proof: "%s" (%s)\n- Ending: %s\n\nWHERE EACH FACT COMES FROM\n%s\n\nFOOTAGE (credited on screen)\n%s\n- Voice: edge-tts (Andrew). Music and sound effects: made in code for this video, nothing licensed.\n%s'
            % (plan.get('title', ''), plan.get('format', ''), os.path.basename(out_mp4), dur, p.get('caption', ''), tags, p.get('pinned', ''), p.get('link') or plan.get('url', ''),
               plan.get('format', ''), plan.get('why_format', ''), special.get('id', ''), special.get('why', ''), plan.get('first_second', {}).get('asset', ''), plan.get('first_second', {}).get('claim', ''),
               plan.get('proof', {}).get('line', ''), plan.get('proof', {}).get('source', ''), json.dumps(plan.get('ending', {}), ensure_ascii=False), facts, credits,
               ('\nWAITS FOR THE OWNER: %s\n' % plan['sensitive']['why']) if plan.get('sensitive', {}).get('flag') else ''))


def main():
    work = os.path.abspath(sys.argv[1])
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    for f in ('comp.html', 'engine.js'):
        shutil.copy(os.path.join(HERE, f), os.path.join(work, f))
    shutil.copytree(os.path.join(HERE, 'fonts'), os.path.join(work, 'fonts'), dirs_exist_ok=True)
    py = sys.executable
    run([py, os.path.join(HERE, 'tts.py'), work], 'voice')
    run([py, os.path.join(HERE, 'receipts.py'), work], 'receipts')
    run([py, os.path.join(HERE, 'shots.py'), work], 'shots')
    run([py, os.path.join(HERE, 'render.py'), work, 'frames'], 'frames')
    run([py, os.path.join(HERE, 'audio.py'), work], 'sound')
    comp = json.load(open(os.path.join(work, 'comp.json'), encoding='utf-8'))
    out = os.path.join(work, 'out'); os.makedirs(out, exist_ok=True)
    slug = (plan.get('url', '').rstrip('/').split('/')[-1] or str(plan.get('page_id', 'video')))[:60]
    mp4 = os.path.join(out, 'genzhype-%s.mp4' % slug)
    run(['ffmpeg', '-y', '-v', 'error', '-framerate', str(comp['fps']), '-i', os.path.join(work, 'frames', 'f%05d.jpg'), '-i', os.path.join(work, 'mix.wav'),
         '-c:v', 'libx264', '-preset', 'medium', '-crf', '19', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-level', '4.1', '-movflags', '+faststart',
         '-c:a', 'aac', '-b:a', '192k', '-shortest', mp4], 'assemble')
    # the cover: the frame where the first stamp has landed
    cover_t = 1.1
    run(['ffmpeg', '-y', '-v', 'error', '-ss', '%.2f' % cover_t, '-i', mp4, '-frames:v', '1', '-q:v', '2', os.path.join(out, 'cover.jpg')], 'cover')
    dur = float(subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp4], capture_output=True, text=True).stdout.strip() or 0)
    open(os.path.join(out, 'post.txt'), 'w', encoding='utf-8').write(post_text(plan, mp4, dur))
    json.dump(plan, open(os.path.join(out, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    if os.path.exists(os.path.join(work, 'render_errors.txt')):
        shutil.copy(os.path.join(work, 'render_errors.txt'), out)
    print('DONE', mp4, '%.1fs' % dur, flush=True)


if __name__ == '__main__':
    main()
