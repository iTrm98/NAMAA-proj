@echo off
setlocal
title NAMAA - stop
rem Stops everything NAMAA started: the dashboard window, the containers,
rem Docker Desktop and the WSL virtual machine. Your data is kept.

cd /d "%~dp0"

echo.
echo [1/4] Dashboard (Front)
taskkill /F /T /FI "WINDOWTITLE eq NAMAA - Front*" >nul 2>&1
echo     Closed.

echo.
echo [2/4] Database + Back + AI
docker info >nul 2>&1
if %errorlevel%==0 (
    docker compose down
) else (
    echo     Docker is not running, nothing to stop.
)

echo.
echo [3/4] Docker Desktop
taskkill /F /IM "Docker Desktop.exe" >nul 2>&1
taskkill /F /IM "com.docker.backend.exe" >nul 2>&1
taskkill /F /IM "com.docker.build.exe" >nul 2>&1
taskkill /F /IM "docker-agent.exe" >nul 2>&1
timeout /t 3 /nobreak >nul
echo     Closed.

echo.
echo [4/4] WSL virtual machine (frees the memory used by VmmemWSL)
wsl --shutdown
timeout /t 3 /nobreak >nul
wsl -l -v

echo.
echo Everything is stopped. The database is kept.
echo Run start-namaa.bat to start again.
pause
