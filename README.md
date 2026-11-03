# NAMAA

**Smart monitoring for parks and green spaces in Makkah.**
Built for Tanmiathon 2026, the Makkah Region sustainable-development hackathon.

---

# 1. Project overview

## The problem

Parks in Makkah are drying out, irrigation runs on fixed schedules whether the soil needs water or not, and waste piles up before anyone notices. Problems are found by field rounds, usually after the damage is visible.

## The solution

NAMAA connects the parks to one live dashboard so problems are caught early:

- **Soil and water sensors** (ESP32) report soil moisture and water flow.
- **Satellite imagery** (Sentinel-2, NDVI) tracks how green each park is over time.
- **A camera with computer vision** (YOLO) detects waste.
- **A rules engine** turns those signals into alerts and a recommended action.
- **A map dashboard** shows every park's status, open alerts and maintenance tasks, and checks afterwards that each fix worked.

Goal: less wasted water, fewer dead plants, faster response, decisions based on data.

## The parts

| Part | Folder | Technology | Runs in | Address |
|---|---|---|---|---|
| **Database** | (Docker volume) | MySQL 8.4 | Docker | `127.0.0.1:3308` |
| **Back** | `backend/` | PHP 8.5, Laravel 13, Reverb (live updates) | Docker | http://localhost:8000 |
| **Front** | `frontend/` | TypeScript, React 19, Vite | Node on your machine | http://localhost:5173 |
| **AI** | `vision-service/` | Python 3.12, FastAPI (NDVI + waste detection) | Docker | http://localhost:8001 |
| **Hardware** | `firmware/` | C++ (Arduino) on ESP32 + sensors | The ESP32 board | |
| **Camera** | `tools/camera-capture/` | Python 3.12, OpenCV | Your machine | |

Also: `docs/` (API contract `api.md`, team workspace) and `data/` (seed and recorded data for the offline demo).

```
ESP32 sensors ──► Back (Laravel API) ──► Database (MySQL)
                       │
                       └──► Front (map dashboard, live updates)
Camera ──────► AI (waste detection) ──► Back
Sentinel-2 ──► AI (NDVI) ─────────────► Back
```

## Ports

| Port | Used by |
|---|---|
| 8000 | Back (API) |
| 8080 | Back (live updates, WebSocket) |
| 8001 | AI |
| 5173 | Front |
| 3308 | Database |

MySQL uses 3308 so it never clashes with a MySQL already on your machine (3306 / 3307). Every port can be changed in the root `.env`.

## What you need

- **Docker Desktop** (Windows: WSL2 backend). Open it and wait until it says *running*.
- **Node.js 22 LTS**: https://nodejs.org
- **Git** and **VS Code**

PHP, MySQL and Python run inside Docker; you do not install them.

> Keep the project **outside OneDrive** (for example `C:\dev\NAMAA-proj`). OneDrive syncs thousands of small files and makes everything slow or broken.

---

# 2. First-time setup

Do the parts in this order (each one needs the one before). Commands run from the project folder unless they start with `cd`.

## 2.1 Settings

```bash
cp .env.example .env    # your own settings file (never committed)
```

Open the root `.env` (next to `docker-compose.yml`) and set `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD`. Leave `APP_KEY` empty for now.

This root `.env` is the only settings file for the Database, Back and AI. Two others appear later: `backend/.env` (Laravel's own, overridden by the root file, ignore it) and `frontend/.env` (dashboard settings, step 2.4).

## 2.2 Database

```bash
docker compose build        # build the Back and AI images (a few minutes the first time)
docker compose up -d mysql  # download MySQL 8.4 and start it
docker compose ps mysql     # wait until STATUS shows (healthy)
```

On its first start, MySQL creates the database `namaa` and the user `namaa` with the passwords from `.env`. The data lives in a Docker volume, so it survives restarts.

Connect with DBeaver, TablePlus or the VS Code MySQL extension:

| Setting | Value |
|---|---|
| Host | `127.0.0.1` |
| Port | `3308` |
| User | `namaa` |
| Password | `DB_PASSWORD` from `.env` |
| Database | `namaa` |

The tables are created by the Back in the next step.

## 2.3 Back

Check: does `backend/` contain a file called `artisan`? Do **A or B**, not both.

### A. `backend/` is empty: create the Laravel app (once for the whole project)

```bash
docker compose run --rm backend sh -c "composer create-project laravel/laravel /tmp/app --no-interaction && cp -a /tmp/app/. /var/www/html/ && ls /var/www/html"   # create Laravel in backend/
docker compose run --rm backend php artisan key:generate --show   # print an app key
```

Paste the key (starts with `base64:`) into `APP_KEY=` in the root `.env`, save, then:

```bash
docker compose up -d                                           # start Back, live updates and AI
docker compose exec backend php artisan install:broadcasting   # live updates: choose "Laravel Reverb", answer "No" to Node packages
docker compose exec backend php artisan migrate                # create the tables
```

Commit the new `backend/` files so nobody has to do A again.

### B. `backend/artisan` exists: install the app

```bash
docker compose run --rm backend composer install                  # install the PHP libraries
docker compose run --rm backend php artisan key:generate --show   # print an app key
```

Paste the key (starts with `base64:`) into `APP_KEY=` in the root `.env`, save, then:

```bash
docker compose up -d                              # start Back, live updates and AI
docker compose exec backend php artisan migrate   # create the tables
```

**Check:** http://localhost:8000 shows the Laravel page.

## 2.4 Front

Check: does `frontend/` contain a `package.json`? Do **A or B**, not both.

### A. No `package.json`: create the React app (once for the whole project)

```bash
cd frontend
npm create vite@latest . -- --template react-ts   # create React + TypeScript here
```

Choose **Ignore files and continue**, and **No** to "Install with npm and start now?". Then:

```bash
npm install             # install the libraries
cp .env.example .env    # dashboard settings (API address, live-update key)
npm run dev             # start the dashboard
```

Commit the new `frontend/` files so nobody has to do A again.

### B. `package.json` exists: install the app

```bash
cd frontend
cp .env.example .env    # dashboard settings (API address, live-update key)
npm install             # install the libraries
npm run dev             # start the dashboard
```

**Check:** http://localhost:5173 shows the dashboard. `Ctrl+C` stops it; `cd ..` goes back.

## 2.5 AI

Already running after `docker compose up -d` in step 2.3.

**Check:** http://localhost:8001/health shows `{"status":"ok"}`.

The heavy libraries (satellite NDVI, YOLO waste detection) are off to keep the build fast. To turn one on, uncomment its block in `vision-service/requirements.txt`, then:

```bash
docker compose build vision   # rebuild with the new libraries
docker compose up -d vision   # restart the AI service
```

## 2.6 Hardware (ESP32 sensor node)

1. Install the Arduino IDE and the ESP32 board package.
2. Create the node's settings file:

```bash
cp firmware/secrets.h.example firmware/secrets.h   # never committed
```

3. In `firmware/secrets.h` set: Wi-Fi name and password, `SERVER_HOST` (your computer's local IP, from `ipconfig`), `SERVER_PORT` = 8000, `NODE_ID`, `NODE_TOKEN`.
4. Allow port 8000 in the Windows firewall.
5. Upload `firmware/node-sensor/` to the ESP32.

The ESP32 and your computer must be on the same network (a phone hotspot or small router works best; public Wi-Fi often blocks devices). Test by opening `http://<your-IP>:8000` from a phone on that network.

## 2.7 Camera (USB webcam)

Docker cannot see a USB webcam, so this runs on the computer the camera is plugged into.

```bash
cd tools/camera-capture
py -3.12 -m venv .venv            # private Python environment (Mac/Linux: python3.12 -m venv .venv)
.venv\Scripts\activate            # turn it on (Mac/Linux: source .venv/bin/activate)
pip install -r requirements.txt   # install OpenCV
```

The capture script (`capture.py`) is not written yet.

---

# 3. Quick start

Open Docker Desktop, then:

```bash
docker compose up -d     # Database + Back + AI
cd frontend
npm run dev              # Front
```

Open http://localhost:5173.

**Stop:**

```bash
docker compose down      # stop (data is kept)
```

**After `git pull`:**

```bash
docker compose run --rm backend composer install
docker compose exec backend php artisan migrate
cd frontend && npm install
```

**Useful:**

```bash
docker compose ps                                   # what is running
docker compose logs -f backend                      # backend output (also: vision, mysql)
docker compose restart backend                      # restart one part
docker compose exec backend php artisan <command>   # any Laravel command
docker compose down -v                              # stop and DELETE the database
```

After editing the root `.env`, run `docker compose up -d` (a restart does not re-read it).

---

# 4. Troubleshooting

| Problem | Fix |
|---|---|
| `ERR_CONNECTION_REFUSED` | That part is not running. `docker compose ps`; for the dashboard, `npm run dev`. |
| `Could not open input file: artisan` | The Laravel app does not exist yet. Do 2.3 A. |
| `npm error ENOENT ... package.json` | Not inside `frontend/`, or the React app does not exist yet. Do 2.4 A. |
| `ubuntu.sock: no such file or directory` | Docker Desktop > Settings > Resources > WSL Integration > enable your distro > Apply & restart. |
| `port is already allocated` | Another program uses that port. Change it in `.env`, then `docker compose up -d`. |
| Database client: `Access denied` | The database was created earlier with other passwords. `docker compose down -v`, `docker compose up -d`, then `migrate`. |
| DBeaver: `Public Key Retrieval is not allowed` | Driver properties: `allowPublicKeyRetrieval=true`, `useSSL=false`. |
| `required variable ... is missing` | The root `.env` is missing or incomplete. |
| CORS error in the browser | The backend must allow `http://localhost:5173`. |
| Everything slow | The project is inside OneDrive. Move it to `C:\dev`. |

When asking for help, share `docker compose ps` and `docker compose logs --tail 30 <part>`.

---

# 5. Working together

- `main` must always work. Branch (`feat/your-feature`), merge through a pull request someone else reviews.
- Never commit secrets: `.env`, `frontend/.env`, `backend/.env` and `firmware/secrets.h` are ignored by Git.
- Large files (model weights, satellite images, videos) stay out of the repo; share a link.
- Changes to the data sent between sensor, server and dashboard go into `docs/api.md` first.
- Mark every park, sensor and reading as `real` or `simulated`.

| Part | Owner |
|---|---|
| Back + Database | |
| Front | |
| AI | |
| Hardware | |
| Camera | |
