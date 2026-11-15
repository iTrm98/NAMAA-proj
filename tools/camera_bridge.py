r"""NAMAA USB camera bridge (Windows host, not Docker).

Docker Desktop on Windows cannot reach USB webcams, so this small server runs on the host
(in ml/.venv, where OpenCV is installed), grabs frames from the webcam in a background thread
and serves the latest one over HTTP. Laravel (in Docker) reads it through
http://host.docker.internal:8090/frame.jpg (CAMERA_URL).

    ml\.venv\Scripts\python.exe tools\camera_bridge.py --camera 1 --port 8090

    GET /frame.jpg  latest JPEG (503 when the camera gives no frames)
    GET /health     {"ok", "camera", "last_frame_age_ms"}

White balance, exposure and focus are locked at the values the camera settled on (DirectShow),
so the picture does not drift while the model looks at it. The log says which locks worked.
Bound to 0.0.0.0 so the Docker containers can reach it.
"""

from __future__ import annotations

import argparse
import json
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import cv2

JPEG_QUALITY = 90


class Grabber(threading.Thread):
    """Reads the camera as fast as it delivers and keeps the newest frame as JPEG bytes."""

    def __init__(self, cap: cv2.VideoCapture) -> None:
        super().__init__(daemon=True)
        self.cap = cap
        self._lock = threading.Lock()
        self._jpeg: bytes | None = None
        self._at = 0.0
        self._halt = threading.Event()

    def run(self) -> None:
        failures = 0
        while not self._halt.is_set():
            ok, frame = self.cap.read()
            if not ok or frame is None:
                failures += 1
                time.sleep(0.1 if failures < 50 else 1.0)
                continue
            failures = 0
            ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY])
            if ok:
                with self._lock:
                    self._jpeg, self._at = buf.tobytes(), time.monotonic()

    def latest(self) -> tuple[bytes | None, float | None]:
        """(jpeg, age in seconds) of the newest frame; age is None before the first frame."""
        with self._lock:
            if self._jpeg is None:
                return None, None
            return self._jpeg, time.monotonic() - self._at

    def stop(self) -> None:
        self._halt.set()


def open_camera(index: int, width: int, height: int) -> cv2.VideoCapture | None:
    cap = cv2.VideoCapture(index, cv2.CAP_DSHOW)
    if not cap.isOpened():
        cap.release()
        return None
    cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*"MJPG"))
    cap.set(cv2.CAP_PROP_FRAME_WIDTH, width)
    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, height)
    ok, _ = cap.read()
    if not ok:
        cap.release()
        return None
    return cap


def available_indexes(limit: int = 6) -> list[int]:
    found = []
    for i in range(limit):
        cap = cv2.VideoCapture(i, cv2.CAP_DSHOW)
        if cap.isOpened() and cap.read()[0]:
            found.append(i)
        cap.release()
    return found


def lock_controls(cap: cv2.VideoCapture) -> dict[str, bool]:
    """Freeze white balance, exposure and focus at what the camera auto-settled on. Returns what worked."""
    for _ in range(30):  # let the auto controls settle first
        cap.read()
    results: dict[str, bool] = {}

    def lock(name: str, auto_prop: int, manual_value: float, value_prop: int) -> None:
        current = cap.get(value_prop)
        cap.set(auto_prop, manual_value)
        if current is not None and current != -1:
            cap.set(value_prop, current)
        after = cap.get(value_prop)
        # DirectShow reports -1 / 0 for controls the driver does not expose.
        results[name] = bool(current not in (None, -1) and after != -1 and abs(after - current) < 1e-6 or
                             cap.get(auto_prop) == manual_value)

    lock("white balance", cv2.CAP_PROP_AUTO_WB, 0, cv2.CAP_PROP_WB_TEMPERATURE)
    lock("exposure", cv2.CAP_PROP_AUTO_EXPOSURE, 0.25, cv2.CAP_PROP_EXPOSURE)  # DirectShow: 0.25 = manual
    lock("focus", cv2.CAP_PROP_AUTOFOCUS, 0, cv2.CAP_PROP_FOCUS)
    return results


def make_handler(grabber: Grabber, camera_index: int):
    class Handler(BaseHTTPRequestHandler):
        def _send(self, code: int, body: bytes, ctype: str) -> None:
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self) -> None:  # noqa: N802
            path = self.path.split("?", 1)[0]
            jpeg, age = grabber.latest()
            if path == "/frame.jpg":
                if jpeg is None or age is None or age > 5:
                    self._send(503, b"no frame from the camera", "text/plain")
                else:
                    self._send(200, jpeg, "image/jpeg")
            elif path == "/health":
                fresh = age is not None and age < 5
                body = {"ok": fresh, "camera": camera_index, "last_frame_age_ms": None if age is None else int(age * 1000)}
                self._send(200 if fresh else 503, json.dumps(body).encode(), "application/json")
            else:
                self._send(404, b"not found", "text/plain")

        def log_message(self, *args) -> None:  # quiet: one line per request is noise
            pass

    return Handler


def main() -> int:
    ap = argparse.ArgumentParser(description="Serve a USB webcam's latest frame over HTTP.")
    ap.add_argument("--camera", type=int, default=1, help="camera index (default 1)")
    ap.add_argument("--port", type=int, default=8090)
    ap.add_argument("--width", type=int, default=1280)
    ap.add_argument("--height", type=int, default=720)
    args = ap.parse_args()

    cap = open_camera(args.camera, args.width, args.height)
    if cap is None:
        found = available_indexes()
        print(f"Cannot open camera index {args.camera}.")
        print(f"Indexes that open: {found if found else 'none (is another program using the camera?)'}")
        if found:
            print(f"Try: --camera {found[0]}")
        return 1

    w, h = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH)), int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    print(f"Camera {args.camera} open at {w}x{h}.")
    for name, ok in lock_controls(cap).items():
        print(f"  {name}: {'locked' if ok else 'not supported by the driver (left on auto)'}")

    grabber = Grabber(cap)
    grabber.start()
    server = ThreadingHTTPServer(("0.0.0.0", args.port), make_handler(grabber, args.camera))
    print(f"Serving http://localhost:{args.port}/frame.jpg and /health (Ctrl+C to stop).")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        grabber.stop()
        cap.release()
    return 0


if __name__ == "__main__":
    sys.exit(main())
