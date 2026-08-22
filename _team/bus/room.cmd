@echo off
REM GenZHype build room - double-click this, then open http://localhost:7777
title GenZHype build room
set TEAM_ROOT=C:\Users\hp\genzhype-bus
cd /d "%~dp0"
start "" http://localhost:7777
node room.mjs
echo.
echo The build room stopped. Press any key to close.
pause >nul
