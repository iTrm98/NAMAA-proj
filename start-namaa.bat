@echo off
setlocal
title NAMAA - start
rem Starts everything for NAMAA: Docker Desktop, Database + Back + AI,
rem VS Code, the dashboard, and the browser.
rem Works from wherever the project folder is (it uses its own location).

cd /d "%~dp0"

echo.
echo [1/5] Docker Desktop
docker info >nul 2>&1
if %errorlevel%==0 goto docker_ready

if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" (
    start "" "%ProgramFiles%\Docker\Docker\Docker Desktop.exe"
) else (
    echo     Docker Desktop not found in Program Files. Start it yourself.
)

echo     Waiting for Docker to be ready...
set /a tries=0
:wait_docker
timeout /t 3 /nobreak >nul
docker info >nul 2>&1
if %errorlevel%==0 goto docker_ready
set /a tries+=1
if %tries% geq 60 (
    echo     Docker did not start within 3 minutes.
    echo     Open Docker Desktop, wait until it says running, then run this file again.
    pause
    exit /b 1
)
goto wait_docker

:docker_ready
echo     Docker is ready.

echo.
echo [2/5] Database + Back + AI
if not exist ".env" (
    echo     No .env file. Run:  cp .env.example .env   then fill it in. See README 2.1.
    pause
    exit /b 1
)
docker compose up -d
if errorlevel 1 (
    echo     docker compose failed. Check the messages above.
    pause
    exit /b 1
)

echo.
echo [3/5] VS Code
start "" code "%~dp0."

echo.
echo [4/5] Dashboard (Front)
if not exist "frontend\package.json" (
    echo     frontend\package.json not found: the React app is not created yet. See README 2.4.
    goto done
)
if not exist "frontend\node_modules" (
    start "NAMAA - Front" /d "%~dp0frontend" cmd /k "npm install && npm run dev"
) else (
    start "NAMAA - Front" /d "%~dp0frontend" cmd /k "npm run dev"
)

echo.
echo [5/5] Browser
timeout /t 6 /nobreak >nul
start "" http://localhost:5173

:done
echo.
echo NAMAA is running.
echo   Dashboard   http://localhost:5173
echo   API         http://localhost:8000
echo   AI          http://localhost:8001/health
echo   Database    127.0.0.1:3308
echo.
echo To stop: run stop-namaa.bat
timeout /t 10
