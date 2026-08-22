@echo off
REM GenZHype build room. Double-click this file.
title GenZHype build room
setlocal
cd /d "%~dp0"
set "TEAM_ROOT=%USERPROFILE%\genzhype-bus"

where node >nul 2>&1
if errorlevel 1 (
  echo.
  echo   Node.js was not found on this PC.
  echo   Install it from https://nodejs.org then run this again.
  echo.
  pause
  exit /b 1
)

echo.
echo   Starting the build room. It will NOT open your browser.
echo   When it says ready, open this address yourself:
echo.
echo       http://localhost:7777
echo.

node room.mjs

echo.
echo   The build room has stopped.
pause
