# NAMAA

**Smart monitoring for parks and green spaces in Makkah.**
Built for Tanmiathon 2026, the Makkah Region sustainable-development hackathon.

---

## What is NAMAA?

Parks in Makkah are drying out, irrigation runs on fixed schedules whether the soil needs water or not, and waste piles up before anyone notices. Today, problems are found by field rounds, usually after the damage is visible.

NAMAA connects the parks to one live dashboard so problems are caught early:

- **Soil and water sensors** (ESP32) report soil moisture and water flow every few seconds.
- **Satellite imagery** (Sentinel-2, NDVI) tracks how green each park is over time.
- **A camera with computer vision** (YOLO) detects waste.
- **A rules engine** turns those signals into alerts and a recommended action ("soil dry, flow normal: check the sprinkler line").
- **A map dashboard** shows every park's status, the open alerts and the maintenance tasks, and verifies afterwards that each fix actually worked.

The goal: less wasted water, fewer dead plants, faster response, and decisions based on data instead of guesswork.

---

## How it is built

| Part | Folder | Technology | Runs in | Address |
|---|---|---|---|---|
| **Back** | `backend/` | PHP 8.5, Laravel 13, MySQL 8.4, Reverb (live updates) | Docker | http://localhost:8000 |
| **Front** | `frontend/` | TypeScript, React 19, Vite | Node on your machine | http://localhost:5173 |
| **AI** | `vision-service/` | Python 3.12, FastAPI (NDVI + waste detection) | Docker | http://localhost:8001 |
| **Node** | `firmware/` | C++ (Arduino) on ESP32 | The ESP32 board | |
| **Camera** | `tools/camera-capture/` | Python 3.12, OpenCV | Your machine | |

Other folders: `docs/` (API contract in `api.md`, team workspace), `data/` (seed and recorded data for the offline demo).

How the data flows:

```
ESP32 sensors ──► Back (Laravel API + MySQL) ──► Front (map dashboard, live)
Camera ──► AI (waste detection) ──► Back
Sentinel-2 ──► AI (NDVI) ──► Back
```

Ports used: **8000** API, **8080** live updates (WebSocket), **8001** AI, **5173** dashboard, **3008** MySQL. MySQL is on 3008 instead of 3306 so it never clashes with a MySQL already on your machine. All ports can be changed in `.env`.

---

## Before you start

Install once:

- **Docker Desktop** (Windows: WSL2 backend, the default). Open it and wait until it says *running*.
- **Node.js 22 LTS**: https://nodejs.org
- **Git** and **VS Code**

You do **not** need PHP, MySQL or Python on your machine: Docker runs them.

> Keep the project **outside OneDrive** (for example `C:\dev\NAMAA-proj`). OneDrive tries to sync thousands of small files and makes everything slow or broken.

---

## First-time setup

Do these steps once, in order. All commands run from the project folder unless a step says `cd`.

### Step 1: Open the project

```bash
git clone https://github.com/iTrm98/NAMAA-proj.git   # download the code
cd NAMAA-proj                                        # enter the project folder
code .                                               # open it in VS Code
```

### Step 2: Create your settings file

```bash
cp .env.example .env    # make your own copy of the settings (never committed)
```

Open `.env` and set `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` to anything you like. Leave `APP_KEY` empty for now.

### Step 3: Set up the Back

```bash
docker compose build                                            # build the PHP and Python images (takes a few minutes the first time)
docker compose up -d mysql                                      # start the database
docker compose run --rm backend composer install                # install Laravel's PHP libraries
docker compose run --rm backend php artisan key:generate --show # print a new app key
```

Copy the printed key (it starts with `base64:`) into the `APP_KEY=` line in `.env` and save. Then:

```bash
docker compose up -d                                  # start everything in Docker (Back + AI)
docker compose exec backend php artisan migrate       # create the database tables
```

Open http://localhost:8000. You should see the Laravel page.

<details>
<summary><b>Only if <code>backend/</code> is empty</b> (the Laravel app was never created)</summary>

Use these instead of the `composer install` line above:

```bash
docker compose run --rm backend sh -c "composer create-project laravel/laravel /tmp/app --no-interaction && cp -a /tmp/app/. /var/www/html/"   # create a new Laravel app inside backend/
```

Then continue with `key:generate`, paste the key, `docker compose up -d`, and run this before `migrate`:

```bash
docker compose exec backend php artisan install:broadcasting   # add live updates; choose "Laravel Reverb", answer "No" to Node packages
```

Commit the new files when it works.
</details>

### Step 4: Set up the Front

```bash
cd frontend             # go into the dashboard folder
cp .env.example .env    # dashboard settings (API address, live-update key)
npm install             # install React and the other libraries
npm run dev             # start the dashboard
```

Open http://localhost:5173. You should see the dashboard. Press `Ctrl+C` to stop it, then `cd ..` to go back.

<details>
<summary><b>Only if <code>frontend/</code> has no <code>package.json</code></b> (the React app was never created)</summary>

Use this before `npm install`:

```bash
npm create vite@latest . -- --template react-ts   # create a React + TypeScript app in this folder
```

Choose **Ignore files and continue**, and answer **No** to "Install with npm and start now?". Commit the new files when it works.
</details>

### Step 5: Check the AI service

It already started with `docker compose up -d` in step 3.

Open http://localhost:8001/health. You should see `{"status":"ok"}`.

The heavy AI libraries (satellite NDVI, YOLO waste detection) are switched off to keep the first build fast. To turn one on, uncomment its block in `vision-service/requirements.txt`, then run:

```bash
docker compose build vision   # rebuild the AI image with the new libraries
docker compose up -d vision   # restart it
```

### Step 6 (hardware only): Sensor node

```bash
cp firmware/secrets.h.example firmware/secrets.h   # your node's settings (never committed)
```

In `firmware/secrets.h`, set your Wi-Fi name and password, `SERVER_HOST` (your computer's local IP, from `ipconfig`) and `SERVER_PORT` = 8000. Then upload `firmware/node-sensor/` to the ESP32 from the Arduino IDE (with the ESP32 board package installed).

The ESP32 and your computer must be on the same network: a phone hotspot or a small router works best. Allow port 8000 in the Windows firewall. To test, open `http://<your-IP>:8000` from a phone on the same network.

### Step 7 (camera only): Webcam capture

Docker cannot see a USB webcam, so this part runs directly on the computer the camera is plugged into.

```bash
cd tools/camera-capture
py -3.12 -m venv .venv            # create a private Python environment (Mac/Linux: python3.12 -m venv .venv)
.venv\Scripts\activate            # turn it on (Mac/Linux: source .venv/bin/activate)
pip install -r requirements.txt   # install OpenCV
```

The capture script (`capture.py`) is not written yet.

---

## Fast start (every time you come back)

Open Docker Desktop, then:

```bash
docker compose up -d    # start Back + AI in the background
cd frontend
npm run dev             # start the dashboard (leave this terminal open)
```

| Open | Address |
|---|---|
| Dashboard | http://localhost:5173 |
| API | http://localhost:8000 |
| AI service | http://localhost:8001/health |

**After pulling new code** (`git pull`), install anything teammates added:

```bash
docker compose run --rm backend composer install      # new PHP libraries
docker compose exec backend php artisan migrate       # new database tables
cd frontend && npm install                            # new dashboard libraries
```

**When you are done:**

```bash
docker compose down     # stop everything (your data is kept)
```

---

## Useful commands

### Back

```bash
docker compose logs -f backend                              # watch the backend's output (Ctrl+C to leave)
docker compose restart backend                              # restart it
docker compose exec backend php artisan migrate             # apply new database changes
docker compose exec backend php artisan make:model Park -m  # create a model and its migration
docker compose exec backend php artisan tinker              # Laravel console
docker compose exec backend composer require vendor/package # add a PHP library
```

PHP lives inside Docker, so Laravel commands always start with `docker compose exec backend`.

To browse the database with DBeaver or TablePlus: host `127.0.0.1`, port `3008`, user and password from `.env`, database `namaa`.

### Front (inside `frontend/`)

```bash
npm run dev               # start the dashboard
npm install package-name  # add a library
npm run build             # check that it builds for production
```

### AI

```bash
docker compose logs -f vision   # watch the AI service's output
docker compose restart vision   # restart it
```

Python code changes reload automatically while it runs.

### Everything

```bash
docker compose ps        # what is running
docker compose down      # stop everything, keep the data
docker compose down -v   # stop everything and DELETE the database
```

After editing `.env`, run `docker compose up -d` again. A plain restart does not read the new values.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Browser says `ERR_CONNECTION_REFUSED` | That part is not running. Check `docker compose ps`; for the dashboard, make sure `npm run dev` is running. |
| `npm error ENOENT ... package.json` | You are not inside `frontend/`, or the React app was never created (see step 4). |
| `ubuntu.sock: no such file or directory` | Docker Desktop > Settings > Resources > WSL Integration > turn on your distro > Apply & restart. |
| `port is already allocated` | Another program uses that port. Change it in `.env`, then `docker compose up -d`. |
| Backend cannot connect to the database | MySQL is still starting. Wait 20 seconds and try again. |
| `required variable ... is missing` | `.env` is missing or incomplete. Run `cp .env.example .env` and fill it in. |
| CORS error in the browser console | The backend must allow requests from `http://localhost:5173`. |
| Everything is very slow | The project is inside OneDrive. Move it to `C:\dev`. |
| Strange errors in scripts or config files | Line-ending problem. Keep the `.gitattributes` file; it forces the right format. |

When asking for help, share the output of `docker compose ps` and `docker compose logs --tail 30 <service>`.

---

## Working together

- `main` must always work. Create a branch (`feat/your-feature`), then merge it through a pull request that someone else reviews.
- Never commit secrets: `.env`, `frontend/.env` and `firmware/secrets.h` are already ignored by Git.
- Large files (AI model weights, satellite images, videos) stay out of the repo. Share a link instead.
- Changing what data moves between the sensor, the server and the dashboard? Update `docs/api.md` first and tell the team.
- Mark every park, sensor and reading as `real` or `simulated`, so the demo never presents test data as real measurements.

| Part | Owner |
|---|---|
| Back | |
| Front | |
| AI | |
| Node | |
| Camera | |
