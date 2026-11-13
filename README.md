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
| **Sensor nodes** | `firmware/node-sensor/` | C++ (PlatformIO, Arduino framework) on a classic ESP32 DevKit or ESP32-S3-DevKitC-1 | Model A, Model B | |
| **Camera** | `firmware/node-cam/` | Logitech C920 in the browser (main); optional ESP32 camera with stock CameraWebServer | Laptop / camera board | `http://<camera-IP>` |
| **Tools** | `tools/` | Python and PowerShell helpers: fake board, USB bridge, network check, firewall | Laptop | |

Also: `data/` (`data/seed/parks.json`: the five demo parks; `data/recorded/`: recorded data for the offline demo).

```
Sensor nodes (ESP32) ────POST readings──► Back (Laravel API) ──► Database (MySQL)
Camera (C920 in the browser) ──frames───►┤
Camera (ESP32-CAM) ◄─────GET /capture────┤
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
| `DEMO_PASSWORD` | `password` | Password of the four demo accounts the seed creates (see 2.3). It must match the password the accounts were seeded with; the one-click demo buttons log in with it. |
| `DEMO_MODE` | `false` | `true`: fast timings (readings every 5 s, short response times, ...), the one-click demo buttons and the `/admin/demo` page (see 3.2). The server sends the interval in every reply, so no re-flashing is needed; run `docker compose up -d` after changing it. |

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

**Node tokens:** the seed prints one token per sensor node (Model A, Model B and the three simulated nodes) once in the terminal and saves the same table to `backend/storage/app/private/node-tokens.txt`. That file is ignored by Git; never copy a token into a tracked file. The tokens go onto the boards over serial (`set node <id> <token>`) or into `firmware/node-sensor/include/secrets.h` (step 2.6 and `firmware/README.md`).

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
| **Model A** (irrigated park) | ESP32 DevKit #1 (classic ESP32-WROOM-32 or ESP32-S3-DevKitC-1 N16R8) | soil, DHT11, flow, relay + pump, water level (optional), LCD (optional) | `firmware/node-sensor/` (PlatformIO) |
| **Model B** (monitoring park) | ESP32 DevKit #2 | soil, DHT11 | same firmware, different node id and token |
| **Camera 1** | Logitech C920 on the laptop (main) | browser camera, from the admin Hardware page | none |
| Camera (optional) | ESP32-S3-CAM / ESP32-CAM | built-in camera | stock CameraWebServer (`firmware/node-cam/`) |

The full board guide is **[firmware/README.md](firmware/README.md)**: how to tell a classic ESP32 from an S3, the pin maps (from `firmware/node-sensor/include/config.h`), wiring per sensor, installing PlatformIO, flashing, every serial command, soil calibration, troubleshooting and which parts work as-is.

In short:

```powershell
pip install platformio
cd firmware\node-sensor
pio run -e esp32 -t upload -t monitor     # classic DevKit; -e esp32s3 for the S3
```

Then in the serial monitor: `set wifi1 <ssid> <pass>`, `set host 192.168.137.1`, `set node <id> <token>`, `reboot`, `show`. Take the id and token from `backend/storage/app/private/node-tokens.txt` (Model A = node 1, Model B = node 3 in a fresh seed; check the file). Instead of serial you can copy `firmware/node-sensor/include/secrets.h.example` to `include/secrets.h` (git-ignored) before flashing.

The network (hotspot, firewall) and the order of steps are in **3.6 Hardware bring-up**. The ESP32 camera board is described in `firmware/node-cam/README.md`; its address goes into `CAMERA_URL` in the root `.env` (then `docker compose up -d`).

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

## 3.1 Background services

Besides the API, three containers keep the system running. If one is down, `docker compose up -d` starts it again.

| Container | Role | If it stops |
|---|---|---|
| `scheduler` | Every minute: the simulator (readings for the simulated parks), the offline check, the pump safety cut-off, the task verification, the task escalation and the camera capture (only when `CAMERA_URL` is set). Each command repeats inside the minute (every 5 to 10 s in demo mode). Daily (Makkah time): satellite NDVI at 03:10, camera photo clean-up at 03:30. | Simulated parks stop reporting; offline alerts, the safety cut-off, verification, escalation and camera frames stop. Done tasks stay "Waiting". |
| `queue` | Background jobs (satellite NDVI, one job per park). | NDVI stops updating. |
| `reverb` | Live updates to the browsers (WebSocket, port 8080): readings, alerts, tasks, pump. | The pages still refresh every minute; the "Live" dot turns amber. |

## 3.2 Demo mode

Demo mode makes everything fast enough to show live. It also turns on the demo helpers below. **Never leave it on outside a demo**: it lets anyone sign in with one click.

| | Normal | Demo mode |
|---|---|---|
| Readings (boards and simulator) | every 30 s | every 5 s |
| Node offline | after 5 min | after 30 s |
| Offline alert ready for a task | after 30 min | after 2 min |
| Pump safety cut-off | 15 min | 3 min |
| Verification after Done | 10 min of normal readings (gives up after 60 min) | 1 min (gives up after 3 min) |
| Response time: urgent / high / normal / low | 1 h / 6 h / 48 h / 7 d | 2 / 4 / 6 / 10 min |
| Camera frame (with `CAMERA_URL`) | every 5 min | every 10 s |
| Litter keeps coming back (3 times) / litter not collected | within 1 h / after 24 h | within 3 min / after 3 min |

A task that is not started by its response time goes up one priority level (and gets a new response time); an urgent one is marked **Overdue**. Admins get a notice each time. Tasks already in progress are not escalated. In demo mode the park page opens on the **1 hour** chart (24 hours otherwise).

Turn it on for one session without editing `.env` (PowerShell):

```powershell
$env:DEMO_MODE='true'; docker compose up -d --force-recreate backend queue scheduler reverb
```

Back to normal:

```powershell
Remove-Item Env:DEMO_MODE; docker compose up -d --force-recreate backend queue scheduler reverb
```

(Or set `DEMO_MODE=true` in the root `.env` and run `docker compose up -d`.)

In demo mode:

- **Login page:** under a "Demo" divider, **Enter as Admin** and **Enter as Worker** sign in with the seeded accounts in one click (open the worker in a second browser profile or a phone).
- **`/admin/demo`** (the "Demo" link in the admin menu): one button per scenario, each with what the audience will see. Scenarios only touch the **simulated** parks; real parks are never changed.
- The same scenarios from the command line:

```bash
docker compose exec backend php artisan namaa:scenario reset     # back to the start of the story: Al Adel dry (pump off, manual), forced faults ended, open alerts and tasks of the simulated parks cleared; healthy parks keep their state (add --full to reset them too)
docker compose exec backend php artisan namaa:scenario dry       # Al Adel below its minimum: high-priority dry alert after 2 readings
docker compose exec backend php artisan namaa:scenario heat      # Al Adel at about 42 °C: the open dry alert becomes heat stress (urgent), no second alert
docker compose exec backend php artisan namaa:scenario escalate  # the newest open, not started task reaches its due time: priority up (or Overdue) within 10 s
docker compose exec backend php artisan namaa:scenario leak      # Al Aziziyah: water flows with the pump off, urgent leak alert within 3 readings
docker compose exec backend php artisan namaa:scenario blockage  # Al Adel's pump runs but the flow drops to a third: blockage alert after 3 readings
docker compose exec backend php artisan namaa:scenario battery   # Al Adel's battery drops to 15 %: low battery alert after 2 readings
docker compose exec backend php artisan namaa:scenario offline   # Al Masfalah (Sim 4, second company; Sim 2 as fallback) stops reporting: grey after 30 s, ready for a task after 2 min
docker compose exec backend php artisan namaa:scenario litter    # Sim Cam (the simulated camera on Al Adel) "sees" the photos in data/samples/litter/: litter alert after 2 frames
docker compose exec backend php artisan namaa:scenario recover   # ends heat, leak, blockage, low battery and the offline sensor; a running litter feed switches to data/samples/clean/
```

One-time demo setup and data tools (all idempotent or read-only; deletions always with `--dry-run` first):

```bash
docker compose exec backend php artisan namaa:sim:camera                  # creates "Sim Cam" (simulated camera on Al Adel), the only target for demo frames
docker compose exec backend php artisan namaa:demo:second-company         # "Al Safwa Gardens Co. (demo)" (admin2@, worker3@) with its own simulated park, Al Masfalah Park
docker compose exec backend php artisan namaa:parks:audit                 # compares every park outline with OpenStreetMap green areas (overlap, verdict); changes nothing
docker compose exec backend php artisan namaa:parks:export                # writes the parks (outlines, areas, data sources, nodes) back to data/seed/parks.json (via storage/app, see its output)
docker compose exec backend php artisan namaa:cleanup:false-offline --dry-run
docker compose exec backend php artisan namaa:cleanup:test-camera --dry-run
```

Park outlines: the admin park page shows where the outline and the area come from (**Data sources**), and **Edit outline** opens a satellite map to trace the green area. The area is computed from the outline, and the satellite data (NDVI) is recomputed after a save ("Recomputing satellite data…").

**Water saved** (impact page) compares NAMAA's pump time with a fixed schedule of 2 × 20 min a day (an assumption, `config/namaa.php` → `water_baseline`), over measured time only, for parks with automatic irrigation. The ⓘ next to the figure explains the method; the figure settles after the first day.

**Battery** and **blockage** are field-version alerts: they run on the **simulated parks only** (the real boards have no battery reading and no expected flow yet), and the UI labels them "Simulated".

The demo page also has a **System health** panel (the `namaa:doctor` checks, every 10 s), a **Camera** card (laptop webcam or photo upload, see 3.3) and a **Satellite data** card ("Fetch satellite data now", see 3.4).

A typical run: `reset` → the dry alert appears (high) → `heat` → the same alert becomes heat stress (urgent) → the admin creates a task for a worker → `escalate` → the task shows **Overdue** and the admin gets a notice → the worker taps Start, Pump on, then Done → after about a minute of normal readings the task shows **Verified ✓** on both screens. Finish with `recover` and `reset`.

## 3.3 Camera and litter

- **Camera 1** (King Abdullah Park) is a browser camera: the Logitech C920 on the laptop sends a frame from the admin Hardware page every 5 minutes (10 s in demo mode), see 3.6. A camera node in `url` mode is polled instead: with `CAMERA_URL` set in the root `.env` (or its own address on the Hardware page; an ESP32 camera, e.g. `http://192.168.137.50`), the scheduler takes a frame from `CAMERA_URL/capture` every 5 minutes (10 s in demo mode). People are blurred **before** the photo is saved (private disk, `camera/{node}/{date}/`), then the vision service looks for litter (YOLO). Photos older than 7 days are deleted every night, except those attached to an alert.
- **Litter** (normal) after 2 litter frames in a row; resolved after 2 clean frames. It becomes **Repeated litter** (high) when litter comes back 3 times within an hour, when one frame shows 5 or more items, or when it is not collected for 24 hours. A litter task is verified by clean frames.
- The park page shows the latest frame with boxes and the last 6 frames; alerts and the worker's task show the frame that raised them; the map popup shows the item count.
- **Stage fallback** (no camera board or no Wi-Fi): the **Camera** card on `/admin/demo` sends a frame from the laptop webcam every 10 s, or one uploaded photo, to a **simulated** camera (Sim Cam on Al Adel, from `namaa:sim:camera`). Both go through the same pipeline. The real Camera 1 refuses demo frames (422): it only takes frames from its own Hardware page card. The `litter` scenario plays the photos in `data/samples/litter/` (and `recover` the ones in `data/samples/clean/`); this folder is not in Git, so add about 10 JPEG photos of each kind before the demo.
- The model is COCO YOLO (`yolo26n`), downloaded when the vision image is built. A park-trained model in `vision-service/models/litter.pt` is used instead when present (see `vision-service/training/README.md`). Ultralytics is AGPL-3.0 (see `vision-service/README.md`).

## 3.4 Satellite (NDVI)

- Every night at 03:10 (Makkah time) the queue fetches the last 10 days of Sentinel-2 NDVI for each park outline. It needs the internet.
- First fill or catch-up: `docker compose exec backend php artisan namaa:ndvi --days=90 --sync` (about 2 minutes for 5 parks). Without `--sync` the jobs go to the queue. `--park=3` does one park.
- **Green cover dropping** (low) when the newest value is 10 % or more below the 4-week average (at least 3 values); it resolves within 5 %.
- The map has a **Status / Vegetation (NDVI)** switch; the park page has a vegetation chart (source: Sentinel-2, Copernicus).
- Park outlines come from OpenStreetMap: `docker compose exec backend php artisan namaa:parks:outlines` lists the candidates, `--write` saves them. Run `namaa:ndvi --days=90 --sync --park=<id>` afterwards for the changed parks.

## 3.5 Impact and the municipality view

- **`/admin/impact`**: water used against a fixed irrigation schedule, alerts by priority and category, tasks done, % verified by the sensors, median time to fix, escalated and overdue tasks, for the last 7 or 30 days, with a row per park. Figures of simulated parks are labelled as such.
- **`/municipality`** (the `municipality` account): every company read-only: the same figures, a row per company, the map with both layers and all open alerts, updated live. `/municipality/parks/<id>` is the park page without any button that changes something.

## 3.6 Hardware bring-up

Board details (pins, wiring, flashing, serial commands) are in [firmware/README.md](firmware/README.md); this section is the laptop and network side.

### Network

- **Main: the laptop's Windows Mobile Hotspot.** Settings > Network & internet > Mobile hotspot: on, band **2.4 GHz** (ESP32 boards have no 5 GHz). The laptop is then always `192.168.137.1`, which is the boards' default `SERVER_HOST`.
- **Backup: a phone hotspot.** Put the laptop and the boards on the phone's hotspot (the boards try `wifi2` when `wifi1` fails; set it with `set wifi2 <ssid> <pass>`). The laptop gets a new address there: read it with `ipconfig` and give it to each board over serial with `set host <laptop-ip>`, then `reboot`. Back on the laptop hotspot: `set host 192.168.137.1`.
- **Check the network** (no admin needed). Lists the laptop's addresses (marks the Mobile Hotspot), tests `http://<ip>:8000/api/health` and port 8080, and checks the firewall rules, with a fix for each FAIL:

  ```powershell
  powershell -ExecutionPolicy Bypass -File tools\check-network.ps1
  ```

- **Open the firewall** once (run PowerShell **as administrator**): inbound rules "NAMAA backend" (TCP 8000) and "NAMAA reverb" (TCP 8080) on the Private profile. Safe to run again; it prints how to remove the rules.

  ```powershell
  powershell -ExecutionPolicy Bypass -File tools\open-firewall.ps1
  ```

  It does **not** open port 5173 (the worker app on a phone, below); add that rule by hand only if you need it.

### Order: bench first, then the real node

1. **Test bench** (once, idempotent): creates the hidden test park "Workshop (test bench)" with nodes Bench A (sensor, pump, flow), Bench B (sensor) and Bench Cam (browser camera). New tokens are printed once and appended to `backend/storage/app/private/node-tokens.txt`.

   ```powershell
   docker compose exec backend php artisan namaa:bench:setup
   ```

   Test parks are hidden from the map, park list, impact, municipality and demo scenarios; on the admin **Hardware** page (`/admin/hardware`) switch on **Show test bench** to see them.
2. **Fake board** against a bench node (Python 3.10+, no installs). Use the node id that `namaa:bench:setup` printed; `--token-file` reads the token from the token file without printing it:

   ```powershell
   python tools\fake-board\fake_board.py --node-id <bench-a-id> --token-file
   python tools\fake-board\fake_board.py --node-id <bench-a-id> --token-file --dry --offline-after 3 --resume 60
   ```

   On the Hardware page the card goes online and shows the last payload live. This proves the backend, Reverb and the page before any board is involved. All options: `tools/README.md`.
3. **Real board on a bench node.** Flash it, then set Wi-Fi, host and the bench node's id and token over serial (`firmware/README.md`, section 5). Check its card: online, RSSI, firmware version, connection "Wi-Fi". Calibrate the soil probe (**Use as dry** / **Use as wet**) and try **Test relay 10 s** with the pump on its own batteries.
4. **Real node.** When the bench works, give the board the real node's id and token (`set node <id> <token>`, `reboot`): Model A or Model B. Do not press **New token** on a real node unless its token is lost: the board must then be updated.

### USB bridge (backup data path)

If no Wi-Fi works, the board reports over its USB cable through the laptop. On the board's serial console type `mode usb` (or it switches by itself after 10 failed Wi-Fi rounds), close the serial monitor, then:

```powershell
pip install -r tools\usb-bridge\requirements.txt
python tools\usb-bridge\usb_bridge.py --auto --token-file      # or --port COM5
```

The bridge posts each reading with the node's token and `via: "usb"` and sends the reply (pump, interval) back to the board. The Hardware page shows the connection as "USB bridge". `mode wifi` on the board switches back.

### Logitech C920 as Camera 1

Camera 1 (King Abdullah Park) is a browser camera. On the demo laptop open the admin **Hardware** page at `http://localhost:5173` (browsers allow the camera only on `localhost` or HTTPS, not on `http://<ip>:5173`), find Camera 1's card, press **Use this computer's camera** and pick the C920 if several cameras are listed. The page shows a preview and sends a frame every 10 s in demo mode (5 min otherwise) through the full pipeline (people blurred, litter detection, alerts). Keep that tab open and visible: background tabs slow down their timers. The optional ESP32 camera is in `firmware/node-cam/README.md`.

### Worker app on a phone

To open the worker app at `http://<laptop-ip>:5173` from a phone on the same network:

1. Let the backend accept that origin: in **`backend/.env`** set `CORS_ALLOWED_ORIGINS` (comma separated), for example `CORS_ALLOWED_ORIGINS=http://localhost:5173,http://192.168.137.1:5173`, then `docker compose restart backend`. (The root `.env` does not pass this variable to the containers.)
2. Let Vite listen on the network: `server.host: true` in `frontend/vite.config.ts`, keeping `port: 5173` and `strictPort: true` (another port breaks CORS and sign-in), or run `npm run dev -- --host`.
3. In `frontend/.env`, point `VITE_API_URL` and `VITE_REVERB_HOST` at the laptop's IP instead of `localhost`, then restart `npm run dev`.
4. Port 5173 needs its own inbound firewall rule: `tools\open-firewall.ps1` opens only 8000 and 8080.

## 3.7 Before the demo

**Power first.** In testing, the laptop slipped into Windows **Modern Standby** (a sleep state that can start on lid close or idle, even plugged in) and froze Docker, so the scheduler stopped for about 5 minutes: no simulated readings, no offline checks, no verification. On the demo laptop:

- Plugged in, lid open. Control Panel > Power Options > "Choose what closing the lid does": **When I close the lid = Do nothing** (on battery and plugged in).
- Turn off sleep and hibernate once (no admin needed; the display may still turn off, that is harmless):

  ```powershell
  powercfg /change standby-timeout-ac 0
  powercfg /change standby-timeout-dc 0
  powercfg /change hibernate-timeout-ac 0
  powercfg /change hibernate-timeout-dc 0
  ```

- Screen lock off: Settings > Accounts > Sign-in options > "If you've been away, when should Windows require you to sign in again?" = **Never**, and no screen saver that shows the sign-in screen on resume.

If it happens anyway, admin pages show a red **Scheduler stalled** banner while the scheduler heartbeat is older than 90 s; it disappears by itself after wake-up, and readings resume within a minute.

Then run the checks and fix anything marked FAIL:

```powershell
docker compose exec backend php artisan namaa:doctor
```

It checks demo mode, `APP_DEBUG`, the database and migrations, the queue and scheduler heartbeats (including a stalled scheduler), Reverb, the vision service and its model, the camera, the real boards (posting in the last minute), the bench nodes, the last rejected device request, the node tokens and the free disk space. The same list is on `/admin/demo` (System health).

1. Docker Desktop running; `start-namaa.bat` done; `namaa:doctor` shows no FAIL.
2. Power settings above done; laptop plugged in, lid open, screen lock off.
3. `DEMO_MODE=true`; `APP_DEBUG=false` for the event.
4. Mobile Hotspot on (2.4 GHz); `tools\check-network.ps1` all OK; both sensor boards report (doctor: last reading a few seconds ago; Hardware page: online).
5. Camera: the C920 running as Camera 1 on the Hardware page (camera access allowed in the browser), or `CAMERA_URL` set and `/capture` OK for an ESP32 camera.
6. `data/samples/litter/` and `data/samples/clean/` have photos; try the `litter` scenario once.
7. NDVI is filled (`namaa:ndvi --days=90 --sync`; the map's vegetation layer shows colours).
8. Admin, worker and municipality accounts can sign in (worker on a phone or a second browser profile).
9. **Reset** on `/admin/demo` (or `namaa:scenario reset`), then walk through the typical run once. Reset puts back only the demo story: Al Adel, forced faults, and the open alerts and tasks of the simulated parks; healthy parks keep their state. **Full reset** (a separate button with a confirmation, or `namaa:scenario reset --full`) also puts the healthy parks back to their start; use it only when the simulated data looks wrong.

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
| CORS error in the browser | The backend must allow the page's origin: `http://localhost:5173` by default; for a phone add `http://<laptop-ip>:5173` to `CORS_ALLOWED_ORIGINS` in `backend/.env`, then `docker compose restart backend` (see 3.6). |
| `Port 5173 is already in use` | The dashboard always uses 5173 (another port breaks CORS and sign-in), so a second `npm run dev` stops with this error. Find the old one in PowerShell: `Get-NetTCPConnection -LocalPort 5173 \| Select-Object OwningProcess`, then `Stop-Process -Id <pid>`, and run `npm run dev` again. If the page stays black after new libraries were installed: `npm run dev -- --force`. |
| Everything slow | The project is inside OneDrive. Move it to `C:\dev`. |

When asking for help, share `docker compose ps` and `docker compose logs --tail 30 <part>`.

---

# 5. Working together

- `main` must always work. Branch (`feat/your-feature`), merge through a pull request someone else reviews.
- Never commit secrets: `.env`, `frontend/.env`, `backend/.env` and every `firmware/**/secrets.h` (e.g. `firmware/node-sensor/include/secrets.h`) are ignored by Git.
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
