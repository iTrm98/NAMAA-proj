# NAMAA demo stand

The demo stand is the only place with real sensor hardware. It is a site of its own
("NAMAA demo stand", `site_type = demo_stand`), never a park: it is left out of the city
map, park lists and counts, NDVI, Impact and the operator comparison. The board on the
stand is **Bench A, node #9**.

What the stand shows, live on **Tools > Live device**:

- soil moisture from a capacitive probe (raw value every 2 s, percent after calibration),
- a pump switched **by hand** from the dashboard (ON / OFF), with the relay state the board
  reports back,
- the litter camera (a C920 webcam on the laptop, through the vision service).

The pump is **manual only** on the stand. The board never waters on its own.

---

## 1. Parts

| # | Part | Notes |
|---|---|---|
| 1 | ESP32 DevKit (ESP32-WROOM-32), or ESP32-S3-DevKitC-1 N16R8 | One board. Pins differ per board, see section 2 |
| 1 | Capacitive soil moisture sensor v1.2 or v2.0 | 3 wires: VCC, GND, AOUT |
| 1 | 1-channel 5 V relay module | Usually active-LOW (the firmware default) |
| 1 | Mini 5 V submersible pump + tubing | Draws 100-300 mA |
| 1 | Separate 5 V supply for the pump | Power bank or USB adapter with a cut USB cable. **Never** power the pump from the board |
| 1 | Cup of water (pump reservoir) and a pot of soil | |
| 1 | Logitech C920 webcam | On a laptop USB port (camera for the litter step) |
| 1 | USB data cable for the board | Micro-USB (DevKit) or USB-C (S3) |
| ~10 | Dupont jumper wires (female-female / male-female) | |
| 1 | Optional: 1N4007 or 1N5819 diode across the pump | Stripe to pump +. Protects the relay contacts from motor spikes |

---

## 2. Wiring

All pins live in one place: `firmware/node-sensor/include/config.h`. The stand profile
(`STAND_PROFILE=1`) is what the `esp32` and `esp32s3` build environments flash.

### Classic ESP32 DevKit (env `esp32`)

| From | To (board) | Notes |
|---|---|---|
| Soil sensor VCC | 3V3 | 3.3 V keeps AOUT below 3.3 V (safe for the ADC) |
| Soil sensor GND | GND | |
| Soil sensor AOUT | **GPIO34** | ADC1_CH6, input-only. ADC1 keeps working while Wi-Fi is on (ADC2 does not) |
| Relay VCC | **VIN** (5 V from USB) | |
| Relay GND | GND | |
| Relay IN | **GPIO26** | Active-LOW by default |
| Status LED | **GPIO2** | The blue LED already on the board, nothing to wire |

### ESP32-S3-DevKitC-1 (env `esp32s3`)

| From | To (board) | Notes |
|---|---|---|
| Soil sensor VCC | 3V3 | |
| Soil sensor GND | GND | |
| Soil sensor AOUT | **GPIO4** | ADC1_CH3 |
| Relay VCC | **5V** pin (5 V from USB) | |
| Relay GND | GND | |
| Relay IN | **GPIO5** | Active-LOW by default |
| Status LED | **GPIO48** | The RGB LED on the board (DevKitC-1 v1.1 boards have it on GPIO38: build with `-DPIN_STATUS_LED=38`) |

### Pump circuit (both boards)

| From | To |
|---|---|
| Pump supply + (5 V) | Relay **COM** |
| Relay **NO** (normally open) | Pump + (red) |
| Pump − (black) | Pump supply − |
| Pump supply − | Board GND (all grounds common) |

Use **NO**, not NC: with NC the pump would run whenever the relay is off. The relay
contacts only switch the pump supply; no pump current goes through the board.

### Status LED

| LED | Meaning |
|---|---|
| Steady (S3: green) | Online: the server answered within the last 10 s |
| Fast blink (S3: red) | Offline: no server reply for 10 s (relay forced OFF) |
| Slow blink (S3: blue) | Pump ON |

---

## 3. How the board behaves

- Every **2 s** it averages 10 ADC samples of the soil probe and posts the **raw** value
  (`moisture`) and the **actual** relay state (`pump_on`) to `POST /api/device/readings`.
  `interval_s` in the server reply wins (the server sends `2` for the stand).
- The reply's `pump` is the target set by the ON / OFF button. The board applies it within
  one report (under about 2 s) and reports the real relay state back; the dashboard shows
  the reported state.
- Safety on the board, even if the server says ON:
  - **OFF on boot**: the OFF level is written before the pin becomes an output, so the relay
    never clicks on at power-up. After a reboot the pump stays off until the server has
    said OFF once (a fresh ON is needed).
  - **OFF after 10 s without a successful server reply** (Wi-Fi gone, laptop asleep,
    firewall).
  - **Hard cap per run**: `pump_max_s` from the reply (server setting
    `namaa.stand.pump_max_seconds`, default 30), or 30 s if the server sends none. After a
    cut-off the pump stays off until the operator presses OFF and ON again.
- A `local_rule` in the reply is ignored on the stand: no automatic watering.

Build flags (in `platformio.ini` `build_flags`, or `-D...`):

| Flag | Default (stand) | Meaning |
|---|---|---|
| `RELAY_ACTIVE_HIGH` | `0` | `0`: IN=LOW closes the relay (most cheap modules). Set `1` for high-level-trigger modules |
| `STAND_PUMP_MAX_S` | `30` | Board-side cap per run when the server sends no `pump_max_s` |
| `STAND_LINK_TIMEOUT_S` | `10` | Relay OFF after this long without a server reply |
| `REPORT_INTERVAL_DEFAULT_S` | `2` | Report interval until (and after a failed) reply |
| `PIN_SOIL`, `PIN_RELAY`, `PIN_STATUS_LED` | see section 2 | Override a pin |

The field node profile (soil, DHT, flow, LCD, offline watering rule) is still there as
envs `esp32_field` and `esp32s3_field`; do not flash it on the stand.

---

## 4. Flashing

Needs PlatformIO (VS Code extension, or `pip install platformio`).

1. Create the node token (prints it **once**; keep it for step 2):

   ```powershell
   docker compose exec backend php artisan namaa:stand:rotate-token --dry-run   # shows what would change
   docker compose exec backend php artisan namaa:stand:rotate-token             # rotates and prints the token once
   ```

   Rotating makes the old token stop working at once (re-flash or `set node` afterwards).

2. Secrets. `include/secrets.h` holds the Wi-Fi and the token and is **never committed**
   (git-ignored; only `secrets.h.example` is tracked).

   ```powershell
   cd firmware\node-sensor
   copy include\secrets.h.example include\secrets.h
   ```

   Edit `include\secrets.h`: `WIFI_SSID_1` / `WIFI_PASS_1` (laptop hotspot),
   `WIFI_SSID_2` / `WIFI_PASS_2` (phone hotspot, optional), `SERVER_HOST "192.168.137.1"`,
   `SERVER_PORT 8000`, `NODE_ID 9`, `NODE_TOKEN "<token from step 1>"`.

3. Build and flash (close any other serial monitor first):

   ```powershell
   pio run -e esp32 -t upload -t monitor      # classic ESP32 DevKit
   pio run -e esp32s3 -t upload -t monitor    # ESP32-S3 (use the port marked "USB")
   ```

   If upload does not start on the classic DevKit, hold **BOOT** until "Connecting..."
   passes.

4. In the serial monitor type `show`: it must say `profile demo stand (manual pump)`,
   `node 9`, the server address, and `wifi connected`.

No reflash is needed to change settings; on the serial monitor (115200 baud):

```
set wifi1 "Hotspot name" "password"
set wifi2 "Phone hotspot" "password"
set host 192.168.137.1
set node 9 <token>
mode wifi | mode usb
show
reboot
```

Values set this way are saved on the board and win over `secrets.h` until `factory`.

Unit tests of the hardware-free logic (relay level, stand pump safety, reply parsing):
`pio test -e native` (needs gcc/g++ on PATH).

---

## 5. Calibration (on the dashboard, no reflash)

Calibration is stored per node on the server (`raw_dry`, `raw_wet`):
`moisture % = clamp((raw_dry − raw) / (raw_dry − raw_wet) × 100, 0, 100)`.
Capacitive probes read **lower when wetter**. Until both values are recorded, the page
shows the raw value and "not calibrated".

1. Open **Tools > Live device**. The board must show Online and the raw value must update
   every 2 s.
2. **Dry**: hold the probe in the air (or push it into completely dry soil). Wait until the
   raw value is steady (about 10 s). Press **Record dry**.
3. **Wet**: put the probe in a glass of water up to the line on the probe, **never above
   it** (the electronics on top must stay dry). Wait until steady. Press **Record wet**.
4. Push the probe into the pot of soil. The percent should be between the two.

Typical raw values at 3.3 V: dry about 2,800-3,300, in water about 1,100-1,600. If dry and
wet come out equal (or very close), the probe is not connected or is on the wrong pin: the
server refuses that calibration.

---

## 6. Network

The board and the laptop must be on the same network. Order of preference:

1. **Laptop hotspot (first choice).** Windows Settings > Network > Mobile hotspot, band
   **2.4 GHz** (the ESP32 has no 5 GHz). The laptop is then `192.168.137.1`, which is the
   default `SERVER_HOST`. Name and password go into `WIFI_SSID_1` / `WIFI_PASS_1`.
2. **Phone hotspot (second).** Laptop and board both join the phone's hotspot (2.4 GHz /
   "maximise compatibility" on iPhone). Find the laptop's IP with `ipconfig` and set it on
   the board: `set host <laptop-ip>`. Use network 2 (`set wifi2 ...`) so the board tries it
   after the laptop hotspot.
3. **USB bridge (backup, no Wi-Fi).** Plug the board into the laptop. On the serial monitor
   type `mode usb` (the board also switches by itself after 10 failed Wi-Fi rounds), close
   the monitor, then:

   ```powershell
   pip install -r tools\usb-bridge\requirements.txt
   python tools\usb-bridge\usb_bridge.py --auto --token <token>
   ```

   The bridge posts the board's readings for it and writes the replies back, so the pump
   still works. The Live device page shows the connection as USB. `mode wifi` switches back.

Check everything at once (no admin needed):

```powershell
powershell -ExecutionPolicy Bypass -File tools\check-network.ps1
```

### Firewall

Windows must allow inbound **TCP 8000** (API, the board posts here) and **TCP 8080**
(Reverb websocket, the live dashboard). Once, as administrator:

```powershell
powershell -ExecutionPolicy Bypass -File tools\open-firewall.ps1
```

The rules are for the **Private** profile: set the hotspot / Wi-Fi network to Private
(Settings > Network > the network > Private), or the board cannot reach the laptop.

---

## 7. Fake board (rehearsals without the real board)

`tools/fake-board/fake_board.py` (Python 3.10+, standard library only) speaks the same
protocol as the firmware: it posts raw soil readings and the relay state as node #9 and
applies `pump` and `interval_s` from the reply, so the Live device page, the ON / OFF
button and calibration work without hardware. Stop the real board first (unplug it), or
both will post as node #9.

```powershell
python tools\fake-board\fake_board.py --node-id 9 --token <token> --features soil,relay --interval 2
python tools\fake-board\fake_board.py --node-id 9 --token <token> --features soil,relay --dry   # dry soil
```

`--host` / `--port` change the API address (default `127.0.0.1:8000`); `--dry-value` and
`--wet-value` set the simulated raw range. `--token-file` reads the token from
`backend/storage/app/private/node-tokens.txt` instead, but only works if that file holds the
current stand token (after `namaa:stand:rotate-token` use `--token`). The fake board
follows `pump` from the server; it does not copy the real board's 10 s / max-run cut-offs
(the server's own auto-off still applies).

(`php artisan namaa:simulate` is the scheduler's simulator for the park nodes; it is not
the stand.)

---

## 8. Five-minute pre-demo checklist

1. `start-namaa.bat` has run; `docker compose ps` shows the containers up.
2. Laptop hotspot on (2.4 GHz), network set to **Private**; `tools\check-network.ps1`
   shows OK for 8000 and 8080.
3. Pump supply charged and switched on; pump **under water** in the cup; tube end in the
   pot (not on the laptop).
4. Board plugged in: status LED steady (not fast blinking).
5. **Tools > Live device**: "Online", last reading a few seconds ago, raw value changing
   when you touch the probe.
6. Calibration done (a percent is shown, not "not calibrated"); if the probe was moved to
   new soil, the percent looks plausible.
7. Press **ON**: relay clicks within about 2 s, pump runs, state shows ON with the
   countdown. Press **OFF**: pump stops, state shows OFF.
8. C920 plugged in; the camera frame shows on the Live device page.
9. Fake board **not** running (unless the real board is out of the demo).

---

## 9. Troubleshooting

### Board offline

- Serial monitor, `show`:
  - `wifi not connected`: wrong SSID / password, or the hotspot is on 5 GHz. Fix with
    `set wifi1 ...`; switch the hotspot to 2.4 GHz.
  - `post failed: -1 no connection`: wrong host or the firewall blocks 8000. Check
    `set host`, network profile Private, run `tools\open-firewall.ps1`.
  - `401 wrong token`: token rotated or mistyped. `set node 9 <token>` (or rotate again and
    re-enter it).
  - `403 node id does not match the token`: `set node 9 <token>` with the stand's token.
  - `422`: payload rejected; check the backend log
    (`docker compose logs --tail 30 backend`).
- No Wi-Fi at all: use the USB bridge (section 6).
- The dashboard says Offline but the board logs `posted (201)`: the websocket is blocked
  (TCP 8080) or the page is stale; reload the page, check 8080 in `check-network.ps1`.

### Relay clicks but the pump does not run

- Pump wired to **NC** instead of **NO**, or COM not on the pump supply +.
- Pump supply off or empty; many power banks switch off at low current. Use a USB
  adapter, or a power bank with an "always on" / low-current mode.
- Pump not under water, or an air lock: submerge it fully and shake it.
- Grounds: the pump supply − must go to the pump −, and to the board GND.

### Relay never clicks, or is ON when it should be OFF

- Inverted: the module is high-level trigger. Rebuild with `-DRELAY_ACTIVE_HIGH=1`.
- Some active-LOW modules powered at 5 V do not fully release when the 3.3 V pin is HIGH
  (IN LED glows dimly). Use a module with optocoupler and JD-VCC jumper, or one rated for
  3.3 V logic.
- Wrong pin: `show` lists `relay=26` (classic) or `relay=5` (S3).
- Pump stopped by itself after 30 s: that is the safety cap. Press OFF, then ON.
- Pump stopped and the LED blinks fast: no server reply for 10 s (see Board offline).

### Readings stuck

- Raw value fixed at `0` or `4095`: probe unplugged or on the wrong pin (`show` lists
  `soil=34` classic, `soil=4` S3). Re-seat AOUT.
- Raw value never changes: the probe is still in the same spot; touch it or dip it in
  water. Type `cal` on the serial monitor to print the raw value every second
  (`exit` stops).
- Percent stuck at 0 or 100: calibration is wrong (for example dry and wet recorded the
  wrong way round). Record dry and wet again.
- Last reading time does not move: board offline (above), or the board hung: press
  **EN / RST** (the watchdog also reboots it after 30 s). The pump is off after any reboot.
