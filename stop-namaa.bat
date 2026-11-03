@echo off
setlocal
title NAMAA - stop
rem Stops Database + Back + AI. Your data is kept.

cd /d "%~dp0"

echo Stopping Database + Back + AI...
docker compose down

echo.
echo Stopped. The data is kept.
echo The dashboard runs in its own window ("NAMAA - Front"): close it or press Ctrl+C there.
pause
