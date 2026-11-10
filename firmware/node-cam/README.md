# Camera node (optional ESP32 camera)

**Main path: the Logitech C920 on the laptop, in the browser.** Camera 1 (King Abdullah Park) is set to browser capture: on the admin **Hardware** page its card has **Use this computer's camera**, which sends a frame every capture interval (10 s in demo mode, 5 min otherwise) through the same pipeline (people blurred, litter detection, alerts). See "Hardware bring-up" in the root [README](../../README.md#36-hardware-bring-up). The ESP32 camera below is an optional extra.

Board: **GOOUUU ESP32-S3-CAM v1.5**, camera **OV3660** (3 MP), USB through a CH340 (TTL) port. An AI-Thinker ESP32-CAM works the same way with `CAMERA_MODEL_AI_THINKER`.

The camera runs Espressif's stock **CameraWebServer** example. It does not send anything by itself: Laravel on the laptop fetches a photo from it on a schedule (`GET <camera>/capture`). No custom firmware is needed.

## Flash it

1. Arduino IDE: **File > Examples > ESP32 > Camera > CameraWebServer**, then **File > Save As** a copy.
2. In `board_config.h` (older versions: at the top of the `.ino`), enable only:
   ```cpp
   #define CAMERA_MODEL_ESP32S3_EYE
   ```
3. In the `.ino`, set the network:
   ```cpp
   const char *ssid = "your-hotspot";
   const char *password = "your-password";
   ```
   Do not commit your copy with the real password.
4. Tools: board **ESP32S3 Dev Module**, PSRAM **OPI PSRAM**, Partition Scheme **Huge APP** if the sketch is too big.
5. Upload through the port marked **TTL**.
6. Open the Serial Monitor (115200). After boot it prints `Camera Ready! Use 'http://192.168.x.x' to connect`.

### One network only

Unlike the sensor node (which tries the laptop hotspot first, then the phone hotspot, and can be changed over serial), the stock example joins **one** network, fixed at compile time. Flash it with the network you will use on the day (normally the laptop's Mobile Hotspot). To switch to the phone hotspot, change `ssid`/`password` and flash again, then update the camera address (below), because the IP changes with the network.

## Connect it to NAMAA

Put that address in the root `.env` (used by every camera node without its own address), then run `docker compose up -d`:

```
CAMERA_URL=http://192.168.x.x
```

A camera node can also have its own address and capture mode (`url` or `browser`) on the Hardware page; **Test camera now** takes one `/capture` and shows the image and timing without running detection.

## Check

- `http://<camera-IP>/` opens the camera's control page.
- `http://<camera-IP>/capture` returns one JPEG.
- `http://<camera-IP>:81/stream` is the live stream.

## Tips

- Remove the protective film from the lens.
- Use a solid 5 V supply: camera plus Wi-Fi draws current peaks that reset boards on weak USB ports.
- VGA or SVGA resolution is enough for waste detection and keeps photos small.
- A hotspot can give the camera a new IP after a restart. Check the Serial Monitor and update `CAMERA_URL` before the demo.
- If this board gives trouble, use the C920 in the browser (above): it is the main path anyway.
