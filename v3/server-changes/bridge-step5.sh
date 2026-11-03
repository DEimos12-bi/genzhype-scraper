
# ---- 5. the reliable clock (2026-11-03): start the GitHub maker while yesterday's pages still need videos, start the
#         posters once an hour in the posting window, and e-mail the day's count once a day (app/video_daily_watch.php)
W=/home/u219414635/domains/genzhype.com/app/video_daily_watch.php
MAIN=/home/u219414635/genzhype-repo
poke() {   # poke <file>... : one commit on main touching the given .social files; GitHub starts the matching workflows
  ( cd "$MAIN" && timeout 120 git pull -q --rebase origin main \
    && for f in "$@"; do date -u +%FT%TZ > ".social/$f"; git add ".social/$f"; done \
    && git -c user.name=video-bridge -c user.email=bridge@genzhype commit -qm "bridge: start $*" \
    && timeout 90 git push -q origin HEAD:main && echo "started: $*" ) \
    || { echo "start failed: $*"; ( cd "$MAIN" && git rebase --abort 2>/dev/null; git reset -q --hard origin/main 2>/dev/null; true ); }
}
if "$PHP" "$W" poke 2>/dev/null | grep -q '^POKE'; then poke v3_poke; fi
if "$PHP" "$W" posters 2>/dev/null | grep -q '^POKE'; then poke buffer_poke yt_poke; fi
"$PHP" "$W" report >/dev/null 2>&1 || true
