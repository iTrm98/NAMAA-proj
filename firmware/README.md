# NAMAA firmware

| Folder | What | How |
|---|---|---|
| `node-sensor/` | Sensor node: soil moisture, DHT11/22, flow, water level, relay + pump, 16x2 LCD | PlatformIO project (Arduino framework). One code base for the classic ESP32 DevKit and the ESP32-S3-DevKitC-1 |
| `node-cam/` | Optional ESP32 camera board | Espressif's stock CameraWebServer, see [node-cam/README.md](node-cam/README.md). The main camera is a USB webcam (Logitech C920) in the browser |

Bring-up in short: plug the board into USB, flash it with one command, set Wi-Fi and the node token over the serial monitor, then watch its readings on the admin **Hardware** page (`/admin/hardware`). The network side (hotspot, firewall, order of steps) is in the root [README](../README.md#36-hardware-bring-up).

All commands below run from `firmware/node-sensor/` unless stated otherwise.

---

## 1. Which ESP32 do I have?

| Look at | Classic ESP32 DevKit | ESP32-S3-DevKitC-1 (N16R8) |
|---|---|---|
| Metal can | `ESP32-WROOM-32` (or `-32D`, `-32E`, `-32U`) | `ESP32-S3-WROOM-1` / `N16R8` |
| USB sockets | one (micro-USB or USB-C) | two USB-C, marked **USB** and **UART** (or **COM**) |
| USB chip | CP2102 (CP210x) or CH340 | built in (USB port) + CH343/CP210x (UART port) |
| PlatformIO env | `esp32` | `esp32s3` |

To be sure, ask the chip:

```powershell
pio device list                                   # lists COM ports with their USB ids
pio pkg exec -p tool-esptoolpy -- esptool.py --port COM5 chip_id
```

`chip_id` prints `Chip is ESP32-D0WD...` (classic, use `esp32`) or `Chip is ESP32-S3...` (use `esp32s3`). Instead of `pio pkg exec` you can also `pip install esptool` and run `python -m esptool --port COM5 chip_id`. If it says *Failed to connect*, hold **BOOT** while it tries (see 4).

USB vendor ids in `pio device list`: `303A` = ESP32-S3 native USB, `10C4` = CP210x, `1A86` = CH340/CH343.

---

## 2. Default pins

Every pin and every feature switch lives in [`node-sensor/include/config.h`](node-sensor/include/config.h). Any value can be changed for one board with `build_flags` in `platformio.ini`, for example `-DPIN_RELAY=23` or `-DFEATURE_FLOW=0`. The build stops with an error if an analog input is not on ADC1 or two functions share a pin.

### Classic ESP32 DevKit (`esp32`)

| Part | GPIO | Notes |
|---|---|---|
| Soil moisture (analog) | 34 | ADC1, input-only (fine for analog) |
| Water level (analog, optional) | 35 | ADC1, input-only |
| DHT11 / DHT22 data | 27 | 10K pull-up to 3.3 V |
| Flow sensor pulses | 26 | 5 V pulses: through a 10K + 20K divider |
| Relay IN (pump) | 25 | |
| LCD I2C SDA | 21 | |
| LCD I2C SCL | 22 | |

Never used: 0, 2, 5, 12, 15 (boot strapping), 6 to 11 (flash), 1 and 3 (USB serial). ADC1 = GPIO 32 to 39.

### ESP32-S3-DevKitC-1 N16R8 (`esp32s3`)

| Part | GPIO | Notes |
|---|---|---|
| Soil moisture (analog) | 4 | ADC1 |
| Water level (analog, optional) | 5 | ADC1 |
| DHT11 / DHT22 data | 16 | 10K pull-up to 3.3 V |
| Flow sensor pulses | 17 | 5 V pulses: through a 10K + 20K divider |
| Relay IN (pump) | 18 | |
| LCD I2C SDA | 8 | |
| LCD I2C SCL | 9 | |

Never used: 0, 3, 45, 46 (boot strapping), 26 to 32 (flash), 33 to 37 (octal PSRAM), 19 and 20 (USB), 43 and 44 (UART), 38 and 48 (RGB LED). ADC1 = GPIO 1 to 10.

**Analog inputs only on ADC1.** ADC2 does not work while Wi-Fi is on.

### Feature switches (in `config.h`)

| Flag | Default | Meaning |
|---|---|---|
| `FEATURE_SOIL` | 1 | Soil probe fitted |
| `FEATURE_DHT` | 1 | DHT fitted; `DHT_TYPE` 11 (default) or 22 |
| `FEATURE_RELAY` | 1 | Relay + pump fitted; `RELAY_ACTIVE_HIGH` 1 (default) or 0 for low-level trigger modules |
| `FEATURE_FLOW` | 1 | Flow sensor fitted; `FLOW_PULSES_PER_LITRE` 5880 (YF-S401); YF-S201 is about 450 |
| `FEATURE_WATER_LEVEL` | 0 | Analog tank level probe (off by default) |
| `FEATURE_LCD` | 1 | 16x2 I2C LCD at `0x27`, then `0x3F`; harmless if none is connected |

A switched-off or failed sensor is sent as `null`, never as a made-up value.

---

## 3. Wiring

**The 3.3 V rule:** every ESP32 pin is 3.3 V. A 5 V signal into a GPIO damages the chip over time. Power analog sensors from **3.3 V**, so their output can never exceed 3.3 V, and put a divider on any 5 V signal. The board's **5V / VIN** pin is only for small 5 V loads (relay module coil, LCD); the pump never runs from the board.

### Soil moisture (capacitive, analog)

| Sensor | Board |
|---|---|
| VCC | 3.3V |
| GND | GND |
| AOUT | soil pin (34 classic / 4 S3) |

### Water level (HW-038 style, analog, optional)

| Sensor | Board |
|---|---|
| + | 3.3V |
| - | GND |
| S | level pin (35 classic / 5 S3) |

Turn it on with `-DFEATURE_WATER_LEVEL=1`.

### DHT11 / DHT22

| Sensor | Board |
|---|---|
| VCC (+) | 3.3V |
| DATA (out) | DHT pin (27 classic / 16 S3), with a **10K resistor from DATA to 3.3V** |
| GND (-) | GND |

Most 3-pin DHT modules already have the pull-up on board; a bare 4-pin sensor needs it. For a DHT22 build with `-DDHT_TYPE=22`.

### Flow sensor (YF-S401 / YF-S201, 5 V)

The sensor runs on 5 V and its pulses are 5 V, so they go through a divider:

```
yellow (signal) ──10K──┬── flow pin (26 classic / 17 S3)
                       │
                      20K
                       │
                      GND
red ── 5V      black ── GND
```

5 V x 20K / (10K + 20K) = 3.3 V. Calibrate `FLOW_PULSES_PER_LITRE` with a measuring jug (YF-S401 about 5880, YF-S201 about 450).

### Relay + pump

| Relay module | Connect to |
|---|---|
| VCC / DC+ | 5V (VIN) on the board |
| GND / DC- | GND |
| IN | relay pin (25 classic / 18 S3) |
| COM | pump battery **+** |
| NO | pump **+** |
| | pump **-** to battery **-** |

The pump has **its own battery pack**; only the relay contacts switch it. If the relay clicks on when it should be off (and off when it should be on), the module is low-level triggered: build with `-DRELAY_ACTIVE_HIGH=0`. The server decides when the pump runs; the board only applies the reply (plus a safety cut-off after `max_run_s` without a fresh reply).

### 16x2 LCD with I2C backpack (optional)

| Backpack | Board |
|---|---|
| VCC | 5V (VIN) |
| GND | GND |
| SDA | SDA (21 classic / 8 S3) |
| SCL | SCL (22 classic / 9 S3) |

The PCF8574 backpack pulls SDA/SCL up to its own VCC. Most work fine, but to keep the 3.3 V rule strictly, remove the backpack's two pull-up resistors or use an I2C level shifter. Turn the blue contrast screw if the screen is blank. Line 1: network (Wi-Fi / USB / offline) and moisture %; line 2: pump state and seconds since the last successful post.

---

## 4. Install PlatformIO and flash

### Once

1. Python 3.10+ from https://python.org (tick "Add to PATH").
2. `pip install platformio` (or the PlatformIO extension in VS Code). Check: `pio --version`. If `pio` is not found, the Scripts folder is not on PATH: use `py -m platformio` in place of `pio` in every command below.
3. USB driver, only if no COM port appears when the board is plugged in:
   - CP210x: Silicon Labs "CP210x USB to UART Bridge VCP Drivers".
   - CH340 / CH343: WCH "CH341SER" / "CH343SER" driver.
   - The S3's **USB** port needs no driver.
4. Use a USB **data** cable (charge-only cables show no COM port).

### Secrets (optional)

```powershell
cd firmware\node-sensor
copy include\secrets.h.example include\secrets.h   # git-ignored; never commit it
```

Fill in `WIFI_SSID_1/PASS_1` (laptop Mobile Hotspot), `WIFI_SSID_2/PASS_2` (phone hotspot, backup), `SERVER_HOST` (`192.168.137.1` on the laptop hotspot), `SERVER_PORT` (8000), `NODE_ID` and `NODE_TOKEN`. Tokens are in `backend/storage/app/private/node-tokens.txt` (git-ignored; never copy a token into a tracked file).

Without `secrets.h` the build still works (with a warning) and the board boots with placeholders; you then set everything over serial (section 5). Settings typed over serial are saved on the board and win over `secrets.h` until `factory`.

### Build and flash

```powershell
cd firmware\node-sensor
pio run -e esp32                                  # build only (classic)
pio run -e esp32s3                                # build only (S3)
pio run -e esp32 -t upload                        # build and flash the classic DevKit
pio run -e esp32s3 -t upload --upload-port COM7   # pick the port if there are several
pio run -e esp32 -t upload -t monitor             # flash, then open the serial monitor
pio test -e native                                # unit tests on this computer (needs gcc on PATH)
```

- **S3:** plug into the port marked **USB** (logs and serial commands use it).
- **Failed to connect / Wrong boot mode:** hold **BOOT**, press and release **EN/RST**, start the upload, release BOOT when "Connecting..." turns into "Writing...". Afterwards press **EN/RST** once to run the new firmware.
- Close any serial monitor or the USB bridge first: only one program can open the port.

---

## 5. Serial monitor and commands

```powershell
pio device monitor -e esp32          # or -e esp32s3; 115200 baud, Ctrl+C to quit
```

Type a command and press Enter. Settings are saved on the board (NVS) and survive reboots and new firmware.

| Command | What it does |
|---|---|
| `help` | Lists the commands |
| `show` | Firmware version, board, mode, both Wi-Fi names, connection and RSSI, server address, node id, **token prefix only**, time, interval, failed posts, buffered readings, local rule, soil calibration, features, pins, pump state. Passwords and the full token are never printed |
| `set host <ip>` | Server address (the laptop), e.g. `set host 192.168.137.1` |
| `set port <n>` | Server port (default 8000) |
| `set wifi1 <ssid> <pass>` | Network 1 (laptop hotspot). Quote names with spaces: `set wifi1 "My Laptop" secret123` |
| `set wifi2 <ssid> <pass>` | Network 2 (phone hotspot, tried when 1 fails) |
| `set node <id> <token>` | Node id and device token (from the token file, or **New token** on the Hardware page) |
| `set cal <dry> <wet>` | Board-side soil calibration (raw values). Used only for the LCD % and offline watering; the server keeps its own |
| `mode wifi` | Report over Wi-Fi (default) |
| `mode usb` | Report through the USB bridge: readings print as `NAMAA:{json}`, replies are read as `NAMAA-REPLY:{json}` |
| `cal` | Prints raw soil and water level every second; `exit` stops |
| `reboot` | Restart |
| `factory` | Forget all saved settings (back to `secrets.h` defaults) and reboot |

First boot of a new board:

```
set wifi1 <hotspot-name> <hotspot-password>
set wifi2 <phone-hotspot-name> <phone-password>
set host 192.168.137.1
set node <id> <token>
reboot
show
```

After 10 failed Wi-Fi rounds with a USB serial connection the board switches to USB mode on its own (`show` says `usb (auto, Wi-Fi failed)`); `mode wifi` switches back.

---

## 6. Soil calibration

Raw values: dry air reads high (about 3000), water reads low (about 1300); every probe differs.

1. In the serial monitor type `cal`. Hold the probe in dry air and note `soil_raw`, then put it in a glass of water up to the line and note it again. Type `exit`.
2. On the board: `set cal <dry> <wet>`, e.g. `set cal 3050 1280`. This drives the LCD % and offline watering.
3. On the server: admin **Hardware** page, the node's card shows the live raw soil value. With the probe in dry air press **Use as dry**, in water press **Use as wet** (each asks for confirmation and is logged). The card shows a live % preview. This calibration drives the dashboard, alerts and pump decisions.

Do both: the server calibration is not sent to the board.

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| No COM port appears | Charge-only cable or missing driver | Data cable; CP210x / CH340 driver (section 4) |
| Upload: *Failed to connect* | Board not in download mode | Hold BOOT during "Connecting..."; close other serial programs |
| `show`: wifi not connected | Wrong name/password, 5 GHz network, hotspot off | `set wifi1 ...` again; the ESP32 needs **2.4 GHz** (Mobile hotspot settings > Band: 2.4 GHz) |
| Posts fail: connection refused / timeout | Wrong host, Windows firewall, backend down | `set host <laptop-ip>`; run `tools\check-network.ps1`; as admin run `tools\open-firewall.ps1`; `docker compose ps` |
| HTTP 401 | Wrong or revoked token | `set node <id> <token>` with the current token (Hardware page shows the rejection) |
| HTTP 403 | Token belongs to another node id | Check the id: `set node <right-id> <token>` |
| HTTP 422 | Bad payload (e.g. value out of range, `recorded_at` too old or in the future) | The Hardware page shows the first validation error; check NTP time (`show`) and sensor wiring |
| HTTP 429 | Too many posts | Normal after a long offline period; the buffer drains 10 readings per cycle |
| Soil or level stuck at 0 or 4095 | Wiring: signal not connected, sensor unpowered, or wrong pin | Check VCC/GND/AOUT; pin must be ADC1 (34/35 classic, 4/5 S3); `cal` shows the live value |
| Temperature/humidity `null` | DHT not answering | Check the pull-up, `DHT_TYPE` (11 vs 22), DATA pin |
| Flow always 0 | No divider ground, wrong pin, or no water | Check the 10K/20K divider; blow through the sensor and watch the value |
| Relay works the wrong way round | Low-level trigger module | Build with `-DRELAY_ACTIVE_HIGH=0` |
| LCD blank or blocks only | Contrast or address | Turn the contrast screw; address 0x27 and 0x3F are both tried |
| Board reboots in a loop | Weak USB power (relay + Wi-Fi peaks) or a hang (30 s watchdog) | Powered USB port or a better cable; read the log before the reset |

---

## 8. Parts compatibility

**Works as-is** (at most a build flag):

| Part | Flag |
|---|---|
| Capacitive soil moisture v1.2 / v2.0 (analog out) | none |
| Resistive soil probe (YL-69 / FC-28) on its **analog** out (AO) | none (calibrate; corrodes over weeks) |
| DHT11 | none |
| DHT22 / AM2302 | `-DDHT_TYPE=22` |
| YF-S401 flow | none |
| YF-S201 or other hall-effect flow sensor (pulse out) | `-DFLOW_PULSES_PER_LITRE=450` (or its own constant) |
| 1-channel 5 V relay module, high or low trigger | `-DRELAY_ACTIVE_HIGH=0` for low trigger |
| Multi-channel relay module (only channel 1 used) | none |
| HW-038 or other analog water level probe | `-DFEATURE_WATER_LEVEL=1` |
| 16x2 LCD with PCF8574 I2C backpack (0x27 or 0x3F) | none |
| Small 3-6 V DC pump on its own batteries | none (through the relay) |

**Needs code** (not supported yet):

| Part | Why |
|---|---|
| Digital-only soil or level modules (DO pin only) | Only wet/dry, no value; the firmware reads an analog value |
| I2C soil sensors (e.g. STEMMA soil, Chirp) | Different driver |
| Ultrasonic level (HC-SR04, JSN-SR04T) | Different driver; a 5 V echo pin also needs a divider |
| Float switch | Digital input, not handled |
| Other temperature/humidity sensors (SHT3x, BME280, DS18B20) | Different driver |
| OLED or 20x4 LCD | Different display code |
| Pumps or valves above what the relay contacts are rated for | Needs a bigger relay/contactor (hardware), plus its own supply |

Any part on this list or not: **3.3 V rule** first. Signals into the ESP32 must stay at or below 3.3 V; power the sensor from 3.3 V or divide its output.
