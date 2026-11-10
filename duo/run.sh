#!/bin/bash
# The duo test run (called by the workflow from the repository root). duo/mode: "plan" = the machine gathers and writes
# its own plan, nothing is rendered; anything else = a full video. When duo/plan.json exists it is THE plan (directed
# by hand from the machine's own plan of the same material): the machine still gathers, looks and renders everything.
cd v3 || exit 1
W=work/duo
mkdir -p "$W" store/clips
cp ../duo/material.json "$W/material.json"
cp ../duo/store/* store/clips/ 2>/dev/null && echo "duo: $(ls ../duo/store | wc -l) file(s) put in the clip store (fetched on the server: TikTok refuses this machine)"
MODE=$(cat ../duo/mode 2>/dev/null || echo full)
echo "duo: mode $MODE, style $V3_STYLE"
timeout 1500 python make.py "$W" --steps material,investigate,gather; echo "duo: gather ended with code $?"
if [ "$MODE" = "plan" ]; then
  timeout 1500 python make.py "$W" --steps plan; echo "duo: plan ended with code $?"
  exit 0
fi
if [ -f ../duo/plan.json ]; then
  cp ../duo/plan.json "$W/plan.json"; mkdir -p "$W/assets"; cp ../duo/assets/* "$W/assets/" 2>/dev/null
  echo "duo: the directed plan is used ($(python -c "import json;p=json.load(open('$W/plan.json'));print(len(p['lines']),'lines,',len(p.get('takes') or []),'takes')"))"
fi
t0=$(date +%s); rc=3; n=0
while [ $rc -eq 3 ] && [ $n -lt 10 ] && [ $(( $(date +%s) - t0 )) -lt 5400 ]; do
  timeout 3000 python make.py "$W"; rc=$?; n=$((n+1))
  echo "duo: make.py call $n ended with code $rc after $(( $(date +%s) - t0 )) s"
  ls "$W"/out/*.mp4 >/dev/null 2>&1 && break
done
