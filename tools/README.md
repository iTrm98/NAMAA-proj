# NAMAA tools

Helpers for running real or fake boards against the laptop. Python tools need Python 3.10+ on the host. Node tokens come from `backend/storage/app/private/node-tokens.txt` (written by the seeder, git-ignored); `--token-file` reads it without printing the token.

## fake-board/fake_board.py (stdlib only)

Acts like `firmware/node-sensor`: posts readings to `POST /api/device/readings`, applies `pump` and `interval_s` from the reply, prints one line per post.

```powershell
python tools\fake-board\fake_board.py --node-id 1 --token-file
python tools\fake-board\fake_board.py --node-id 1 --token-file --dry --interval 5
python tools\fake-board\fake_board.py --node-id 1 --token-file --offline-after 3 --resume 60
```

| Option | Meaning |
|---|---|
| `--host`, `--port` | API address (default `127.0.0.1:8000`) |
| `--node-id` | Node ID (required) |
| `--token-file [path]` / `--token` | Token from node-tokens.txt (by node ID) or given directly |
| `--features soil,dht,relay,flow` | Sensors the board has; missing ones are sent as `null` |
| `--dry`, `--wet` | Soil stays dry (~15%) or wet (~85%) |
| `--no-flow`, `--leak` | No flow while pumping / flow while the pump is off |
| `--offline-after N`, `--resume S` | Stop posting after N posts and buffer readings; after S seconds send the buffer with `recorded_at` |
| `--serial` | USB mode: `NAMAA:{json}` on stdout, `NAMAA-REPLY:{json}` from stdin (logs go to stderr) |
| `--interval`, `--count` | Seconds between reports before the first reply; stop after N readings |

## usb-bridge/usb_bridge.py

For a board in USB mode (`mode usb` on its serial console, or automatic after 10 failed Wi-Fi rounds). Reads `NAMAA:{json}` lines, adds `via: "usb"` and `recorded_at` (laptop UTC) when missing, posts with the node's token (picked by `node_id` from the token file) and writes the reply back as `NAMAA-REPLY:{json}`. Other board lines are echoed to the console (`--quiet` hides them). Reconnects when the board is unplugged. Close the Arduino/PlatformIO serial monitor first: only one program can open the port.

```powershell
pip install -r tools\usb-bridge\requirements.txt
python tools\usb-bridge\usb_bridge.py --auto --token-file        # finds the ESP32 by USB vendor id
python tools\usb-bridge\usb_bridge.py --port COM5 --token-file
python tools\fake-board\fake_board.py --node-id 1 --serial | python tools\usb-bridge\usb_bridge.py --stdin --token-file
```

`--stdin` needs no pyserial. With a one-way pipe the fake board gets no replies (it logs "no NAMAA-REPLY" and keeps going); the readings still reach the API. API address: `--host`, `--server-port`.

## check-network.ps1 (no admin)

```powershell
powershell -ExecutionPolicy Bypass -File tools\check-network.ps1
```

Lists IPv4 addresses per adapter (marks Mobile Hotspot, `192.168.137.1`), warns about networks set to Public, tests `http://<ip>:8000/api/health` and port 8080 on each address, checks inbound firewall rules for TCP 8000/8080, and prints OK/FAIL with a fix. Tests run from the laptop itself, so they pass even when the firewall blocks other devices; the firewall section covers that.

## open-firewall.ps1 (administrator)

```powershell
powershell -ExecutionPolicy Bypass -File tools\open-firewall.ps1
```

Adds (or updates) inbound allow rules "NAMAA backend" (TCP 8000) and "NAMAA reverb" (TCP 8080) on the Private profile. Safe to run again. Remove with `Remove-NetFirewallRule -DisplayName "NAMAA backend"` and `... "NAMAA reverb"`.

## Worker app on a phone

To open the worker app at `http://<laptop-ip>:5173` from a phone on the same network:

- Vite must listen on all interfaces: `server.host: true` in `frontend/vite.config.ts` (keep `strictPort: true`, so it never moves to another port), or `npm run dev -- --host`.
- The backend must allow that origin: `CORS_ALLOWED_ORIGINS` (read by `backend/config/cors.php`, comma separated, default `http://localhost:5173`), for example `http://localhost:5173,http://192.168.137.1:5173`. Then restart the backend container.
- The frontend's API and Reverb URLs must use the laptop IP, not `localhost`.
- Port 5173 also needs an inbound firewall rule on the laptop (open-firewall.ps1 only opens 8000 and 8080).
