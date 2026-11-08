# NAMAA

**Smart monitoring for parks and green spaces in Makkah.**
Built for Tanmiathon 2026, the Makkah Region sustainable-development hackathon.

---

# 1. Project overview

## The problem

Parks in Makkah are drying out, irrigation runs on fixed schedules whether the soil needs water or not, and waste piles up before anyone notices. Problems are found by field rounds, usually after the damage is visible.

## The solution

NAMAA connects the parks to one live dashboard so problems are caught early:

- **Soil and water sensors** (ESP32-S3) report soil moisture, water flow, temperature and humidity, and switch a small irrigation pump.
- **Satellite imagery** (Sentinel-2, NDVI) tracks how green each park is over time.
- **A camera** (ESP32-S3-CAM) watches a park corner; the server fetches its photos and computer vision (YOLO) detects waste.
- **A rules engine** turns those signals into alerts and a recommended action.
- **A map dashboard** shows every park's status, open alerts and maintenance tasks, and checks afterwards that each fix worked.

Goal: less wasted water, fewer dead plants, faster response, decisions based on data.

## The parts

| Part | Folder | Technology | Runs in | Address |
|---|---|---|---|---|
| **Database** | (Docker volume) | MySQL 8.4 | Docker | `127.0.0.1:3308` |
| **Back** | `backend/` | PHP 8.5, Laravel 13, Reverb (live updates) | Docker | http://localhost:8000 |
| **Front** | `frontend/` | TypeScript, React 19, Vite · Arabic and English (RTL) | Node on your machine | http://localhost:5173 |
| **AI** | `vision-service/` | Python 3.12, FastAPI (NDVI + waste detection) | Docker | http://localhost:8001 |
| **Sensor nodes** | `firmware/node-sensor/` | C++ (Arduino) on ESP32-S3-DevKitC-1 | Model A, Model B | |
| **Camera** | `firmware/node-cam/` | Stock CameraWebServer on ESP32-S3-CAM | Camera board | `http://<camera-IP>` |

Also: `data/` (`data/seed/parks.json`: the five demo parks; `data/recorded/`: recorded data for the offline demo).

```
Sensor nodes (ESP32-S3) ──POST readings──► Back (Laravel API) ──► Database (MySQL)
Camera (ESP32-S3-CAM) ◄──GET /capture────┤
                                         ├──► AI: waste detection (YOLO), NDVI (Sentinel-2)
                                         └──► Front (map dashboard, live updates)
```

The Back is the centre: sensor nodes post to it, it fetches camera photos, calls the AI service, stores everything and pushes updates to the dashboard.

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

Two demo settings live there too:

| Setting | Default | Meaning |
|---|---|---|
| `DEMO_PASSWORD` | `password` | Password of the four demo accounts the seed creates (see 2.3). Change it before showing the demo on a shared network. |
| `DEMO_MODE` | `false` | `true`: the boards report every 5 s instead of 30 s. The server sends the interval in every reply, so no re-flashing is needed; run `docker compose up -d` after changing it. |

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
docker compose up -d                                     # start Back, live updates and AI
docker compose exec backend php artisan migrate --seed   # NEW machine only: create the tables and the demo data
```

`--seed` is only for a **new machine with an empty database**. It builds the demo: one company, five Makkah parks (two real ones for the boards, three simulated ones with a week of history), alerts, tasks and NDVI values. On a machine that already has data, run `migrate` without `--seed`. To rebuild the demo from scratch: `docker compose exec backend php artisan migrate:fresh --seed` (this **deletes everything and creates new node tokens**, so every board must be re-flashed afterwards).

**Demo accounts** (password: `DEMO_PASSWORD` from the root `.env`, default `password`):

| Email | Role | Opens |
|---|---|---|
| `admin@namaa.test` | Admin (company staff) | `/admin`: map and park pages |
| `worker1@namaa.test` | Worker (Ahmed) | `/worker` |
| `worker2@namaa.test` | Worker (Khalid) | `/worker` |
| `municipality@namaa.test` | Municipality (read-only, all companies) | `/municipality` |

**Node tokens:** the seed prints one token per sensor node (Model A, Model B and the three simulated nodes) once in the terminal and saves the same table to `backend/storage/app/private/node-tokens.txt`. That file is ignored by Git; never copy a token into a tracked file. The tokens go into `firmware/secrets.h` (step 2.6).

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
cp .env.example .env    # dashboard settings (API address, live-update key, map key)
npm install             # install the libraries
npm run dev             # start the dashboard
```

**Map key (optional):** for the satellite basemap, create a free key at https://cloud.maptiler.com ("API keys") and put it in `frontend/.env` as `VITE_MAPTILER_KEY=...` (never in a tracked file). Without a key the map uses the OpenStreetMap street map. Restart `npm run dev` after editing `frontend/.env`.

**Check:** http://localhost:5173 shows the sign-in page. Sign in with a demo account (2.3). Arabic is the default; the header has a language switch (Arabic RTL / English) and a dark/light switch. `Ctrl+C` stops it; `cd ..` goes back.

If the page stays blank after `npm install` added new libraries, stop `npm run dev` and start it again.

## 2.5 AI

Already running after `docker compose up -d` in step 2.3.

**Check:** http://localhost:8001/health shows `{"status":"ok"}`.

The satellite NDVI libraries are on (`POST /ndvi`, called only by the Back). YOLO waste detection is still off to keep the build fast. To turn a block on, uncomment it in `vision-service/requirements.txt`, then:

```bash
docker compose build vision   # rebuild with the new libraries
docker compose up -d vision   # restart the AI service
```

## 2.6 Hardware

| Node | Board | What is wired | Code |
|---|---|---|---|
| **Model A** (irrigated park) | ESP32-S3-DevKitC-1 #1 | soil, DHT11, YF-S401 flow, relay + pump, water level (optional), LCD (optional) | `firmware/node-sensor/` |
| **Model B** (monitoring park) | ESP32-S3-DevKitC-1 #2 | soil, DHT11 | same sketch, different `NODE_ID` |
| Spare | ESP32-S3-DevKitC-1 #3 | nothing; pre-flashed with Model A | same sketch |
| **Camera** | GOOUUU ESP32-S3-CAM v1.5 (OV3660) | built-in camera | stock CameraWebServer |

**Once:**

1. Install the Arduino IDE and the board package **esp32 by Espressif Systems**.
2. Libraries: **DHT sensor library**, **Adafruit Unified Sensor**, **ArduinoJson**, **LiquidCrystal_I2C** (WiFi and HTTPClient come with the board package).
3. Allow port 8000 in the Windows firewall.
4. Put the laptop and all boards on one **2.4 GHz** network (a phone hotspot; the ESP32-S3 has no 5 GHz). Test by opening `http://<laptop-IP>:8000` from a phone on that network.

### Sensor nodes (Model A and B)

Arduino IDE settings: board **ESP32S3 Dev Module**, Flash Size **16MB**, PSRAM **OPI PSRAM**, **USB CDC On Boot: Enabled**. Upload through the port marked COM.

| Pin | Part |
|---|---|
| GPIO4 | Soil moisture (analog) |
| GPIO5 | HW-038 water level (analog, optional) |
| GPIO16 | DHT11 data |
| GPIO17 | YF-S401 flow pulses, through a 10K + 20K voltage divider (5 V to 3.3 V) |
| GPIO18 | HW-482 relay (pump) |
| GPIO8 / GPIO9 | LCD1602 I2C SDA / SCL (optional) |

Analog reads only on GPIO1 to GPIO10 (ADC1); ADC2 does not work with Wi-Fi on. Avoid GPIO0, 3, 45, 46 (boot), 19, 20 (USB), 35 to 37 (PSRAM), 43, 44 (serial), 38 and 48 (RGB LED). The pump runs from its own battery pack through the relay, never from the board.

```bash
cp firmware/secrets.h.example firmware/secrets.h   # never committed
```

In `secrets.h` set the Wi-Fi name and password, `SERVER_HOST` (the laptop's IP, from `ipconfig`), `SERVER_PORT` = 8000, `NODE_ID` and `NODE_TOKEN`. Take `NODE_ID` and `NODE_TOKEN` from `backend/storage/app/private/node-tokens.txt` (Model A = node 1, Model B = node 3 in a fresh seed; check the file). Upload `firmware/node-sensor/` once per board with its own values.

### Camera (ESP32-S3-CAM)

See `firmware/node-cam/README.md`. In short: open **File > Examples > ESP32 > Camera > CameraWebServer**, choose `CAMERA_MODEL_ESP32S3_EYE`, set the Wi-Fi, upload through the **TTL (CH340)** port, then read its IP in the Serial Monitor and put it in the root `.env`:

```bash
CAMERA_URL=http://192.168.43.50   # the IP the Serial Monitor shows
```

Run `docker compose up -d` after editing `.env`. Check by opening `http://<camera-IP>/capture` in the browser: you should see a photo.

The sensor sketch in `node-sensor/` is not written yet.

---

# 3. Quick start

**Windows, one click:** double-click `start-namaa.bat` in the project folder. It starts Docker Desktop (and waits for it), the Database, Back and AI, opens VS Code, starts the dashboard in its own window, and opens the browser. `stop-namaa.bat` stops everything: the dashboard window, the containers, Docker Desktop and the WSL virtual machine (frees its memory). The data is kept.

To run it automatically when Windows starts: press `Win+R`, type `shell:startup`, and put a shortcut to `start-namaa.bat` in that folder.

**By hand:** open Docker Desktop, then:

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
docker compose exec backend php artisan migrate   # never --seed here: it would duplicate the demo
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
| Database client: `Access denied` | The database was created earlier with other passwords. `docker compose down -v` (deletes the data), `docker compose up -d`, then `migrate --seed` (new node tokens: re-flash the boards). |
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
- Changes to the data sent between sensor, server and dashboard are agreed with the team before they are built.
- Mark every park, sensor and reading as `real` or `simulated`.

| Part | Owner |
|---|---|
| Back + Database | |
| Front | |
| AI | |
| Sensor nodes | |
| Camera | |
