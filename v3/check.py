"""THE CHECKS, as code. A video that fails one is repaired when the repair is mechanical, refused otherwise.
  layout(work)  reads layout.json (render.py layout): two graphics on the same spot, a graphic under the captions, a
                graphic outside the free part of the screen. The repair removes the smaller of the two from the plan
                (a caption is never removed) and the layout is read again, two rounds at most.
  gate(work)    before the frames are drawn: long enough, footage under every second, a sharp first shot, proof shown,
                nothing waiting for the owner.
usage: check.py <work folder> layout|gate"""
import json
import os
import re
import sys

import ai

CFG = ai.CONFIG


def inter(a, b):
    w = min(a['x'] + a['w'], b['x'] + b['w']) - max(a['x'], b['x'])
    h = min(a['y'] + a['h'], b['y'] + b['h']) - max(a['y'], b['y'])
    return w * h if w > 0 and h > 0 else 0


def layout_issues(work):
    samples = json.load(open(os.path.join(work, 'layout.json'), encoding='utf-8'))
    x0, y0, x1, y1 = CFG['checks']['safe_box']
    issues = {}
    for s in samples:
        r = s['rects']
        for i in range(len(r)):
            a = r[i]
            if a['w'] * a['h'] < 4:                              # an empty helper element is nothing on screen
                continue
            if a['key'] != 'caption' and (a['x'] < x0 or a['y'] < y0 or a['x'] + a['w'] > x1 or a['y'] + a['h'] > y1):
                issues.setdefault(('out', a['key'], ''), s['t'])
            for j in range(i + 1, len(r)):
                b = r[j]
                if inter(a, b) > CFG['checks']['overlap_px'] and not (a['key'].startswith(('site.', 'slang.')) and b['key'].startswith(('site.', 'slang.'))):      # the closing's devices overlap on purpose
                    issues.setdefault(('over', a['key'], b['key']), s['t'])
    return [{'kind': k[0], 'a': k[1], 'b': k[2], 't': t} for k, t in issues.items()]


def drop(plan, key):
    """Removes one graphic from the plan by its key (L<line>.<overlay>, L<line>.r<row>, L<line>.tr). False if it must stay."""
    m = re.fullmatch(r'L(\d+)\.(\d+|r\d+|b\d+|tr)', key)
    if not m:
        return False
    ln, what = plan['lines'][int(m.group(1))], m.group(2)
    if what == 'tr':
        for o in ln['overlays']:
            o.pop('translate', None)
    elif what[0] == 'r':
        for o in ln['overlays']:
            if o['k'] == 'rows' and len(o['rows']) > int(what[1:]):
                o['rows'].pop()                                 # the last row goes: the list stays in order
    elif what[0] == 'b':
        return False
    else:
        ln['overlays'][int(what)]['drop'] = True
    return True


def repair_layout(work, log=print):
    issues = layout_issues(work)
    if not issues:
        return 0
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    order = {'stamp': 3, 'tag': 2, 'chip': 1, 'sub': 1}
    done = set()
    for it in issues:
        cands = [k for k in (it['b'], it['a']) if k and k != 'caption']          # the later one first
        def weight(k):
            m = re.fullmatch(r'L(\d+)\.(\d+)', k)
            return order.get(plan['lines'][int(m.group(1))]['overlays'][int(m.group(2))]['k'], 5) if m else 0
        for k in sorted(cands, key=weight):
            if k in done or drop(plan, k):
                if k not in done:
                    log('  layout: %s %s %s at %.1fs -> removed %s' % (it['a'], 'is outside the free area' if it['kind'] == 'out' else 'sits on', it['b'], it['t'], k))
                done.add(k)
                break
        else:
            log('  layout: %s / %s at %.1fs could not be repaired' % (it['a'], it['b'], it['t']))
    json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    comp = json.load(open(os.path.join(work, 'comp.json'), encoding='utf-8'))
    comp['plan']['lines'] = plan['lines']
    json.dump(comp, open(os.path.join(work, 'comp.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    open(os.path.join(work, 'comp.js'), 'w', encoding='utf-8').write('window.COMP = ' + json.dumps(comp, ensure_ascii=False) + ';')
    return len(issues)


def gate(work, allow_sensitive=False):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    comp = json.load(open(os.path.join(work, 'comp.json'), encoding='utf-8'))
    why = []
    if comp['end'] < CFG['length']['min_s']:
        why.append('%.1f s long: under %d s' % (comp['end'], CFG['length']['min_s']))
    if plan.get('format') == 'slang':                          # drawn, not filmed: the footage rules do not apply
        if plan.get('sensitive', {}).get('flag') and not allow_sensitive:
            why.append('WAITS FOR THE OWNER: the page mentions "%s" (run again with --approved once he has read the plan)' % plan['sensitive']['why'])
        return why
    film = sum(s['t1'] - s['t0'] for s in comp['shots'] if s['mode'] in 'FBCS')
    if film / comp['end'] < CFG['footage']['min_share']:
        why.append('footage under only %.0f%% of the video' % (100 * film / comp['end']))
    if comp['shots'][0]['mode'] not in 'FCS':
        why.append('the first shot is not sharp footage')
    sharp = sum(s['t1'] - s['t0'] for s in comp['shots'] if s['mode'] in 'FC' or (s['mode'] == 'S' and s.get('dim', 0) < 0.2))
    if sharp < 12:
        why.append('only %.0f s of sharp footage: the story has too little to show' % sharp)
    if len({s['asset'] for s in comp['shots']}) < 2:
        why.append('one single clip or picture for the whole video')
    if plan.get('sensitive', {}).get('flag') and not allow_sensitive:
        why.append('WAITS FOR THE OWNER: the story mentions "%s" (run again with --approved once he has read the plan)' % plan['sensitive']['why'])
    return why


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    if sys.argv[2] == 'layout':
        print('layout issues:', json.dumps(layout_issues(sys.argv[1]), ensure_ascii=False))
    else:
        print('gate:', gate(sys.argv[1]) or 'passes')
