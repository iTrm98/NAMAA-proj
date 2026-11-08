# Camera node (ESP32-S3-CAM)

Board: **GOOUUU ESP32-S3-CAM v1.5**, camera **OV3660** (3 MP), USB through a CH340 (TTL) port.

The camera runs Espressif's stock **CameraWebServer** example. It does not send anything by itself: Laravel on the laptop fetches a photo from it on a schedule (`docs/api.md`, section 6). No custom firmware is needed.

## Flash it

1. Arduino IDE: **File > Examples > ESP32 > Camera > CameraWebServer**, then **File > Save As** a copy.
2. In `board_config.h` (older versions: at the top of the `.ino`), enable only:
   ```cpp
   #define CAMERA_MODEL_ESP32S3_EYE
   ```
3. In the `.ino`, set your hotspot:
   ```cpp
   const char *ssid = "your-hotspot";
   const char *password = "your-password";
   ```
   Do not commit your copy with the real password.
4. Tools: board **ESP32S3 Dev Module**, PSRAM **OPI PSRAM**, Partition Scheme **Huge APP** if the sketch is too big.
5. Upload through the port marked **TTL**.
6. Open the Serial Monitor (115200). After boot it prints `Camera Ready! Use 'http://192.168.x.x' to connect`.

## Connect it to NAMAA

Put that address in the root `.env`, then run `docker compose up -d`:

```
CAMERA_URL=http://192.168.x.x
```

## Check

- `http://<camera-IP>/` opens the camera's control page.
- `http://<camera-IP>/capture` returns one JPEG.
- `http://<camera-IP>:81/stream` is the live stream.

## Tips

- Remove the protective film from the lens.
- Use a solid 5 V supply: camera plus Wi-Fi draws current peaks that reset boards on weak USB ports.
- VGA or SVGA resolution is enough for waste detection and keeps photos small.
- A phone hotspot can give the camera a new IP after a restart. Check the Serial Monitor and update `CAMERA_URL` before the demo.
- Backup camera: Logitech C920 (with Nawaf).
