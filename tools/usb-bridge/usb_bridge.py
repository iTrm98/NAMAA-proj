#!/usr/bin/env python3
"""NAMAA USB bridge: board serial port <-> Laravel API.

Reads `NAMAA:{json}` lines from a board in USB mode (or from stdin), posts each
one to POST /api/device/readings with the node's token, and writes the reply
back as `NAMAA-REPLY:{json}`. It sets `via: "usb"` and adds `recorded_at`
(host UTC) to readings that lack it. Other board lines are echoed to stderr.

  python usb_bridge.py --auto --token-file            # find the board by USB vendor id
  python usb_bridge.py --port COM5 --token-file
  python fake_board.py --node-id 1 --serial | python usb_bridge.py --stdin --token-file

--token-file looks the token up by the reading's node_id, so one bridge can
serve several boards' lines. The token is never printed. pyserial is only
needed for --port/--auto (pip install -r requirements.txt).
"""
import argparse
import datetime as dt
import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

API_PATH = "/api/device/readings"
REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_TOKEN_FILE = REPO_ROOT / "backend" / "storage" / "app" / "private" / "node-tokens.txt"
# USB vendor ids of ESP32 boards: Espressif native USB (S3), Silicon Labs CP210x,
# WCH CH340/CH9102, FTDI.
KNOWN_VIDS = [0x303A, 0x10C4, 0x1A86, 0x0403]


def log(msg):
    print(time.strftime("%H:%M:%S") + " " + msg, file=sys.stderr, flush=True)


def load_tokens(path):
    """node id -> token from node-tokens.txt (tab separated, token last)."""
    try:
        lines = Path(path).read_text(encoding="utf-8").splitlines()
    except OSError as e:
        sys.exit("cannot read token file %s: %s" % (path, e.strerror))
    tokens = {}
    for line in lines:
        cols = [c.strip() for c in line.split("\t")]
        if len(cols) >= 2 and cols[0].isdigit() and "|" in cols[-1]:
            tokens[int(cols[0])] = cols[-1]
    if not tokens:
        sys.exit("no node tokens found in %s" % path)
    return tokens


def utc_now_iso():
    return dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def post(url, token, body):
    data = json.dumps(body).encode()
    req = urllib.request.Request(url, data=data, method="POST", headers={
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": "Bearer " + token,
    })
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except (urllib.error.URLError, OSError) as e:
        return -1, str(getattr(e, "reason", e))


class Bridge:
    def __init__(self, a):
        self.url = "http://%s:%d%s" % (a.host, a.server_port, API_PATH)
        self.single = a.token
        self.tokens = None if a.token else load_tokens(a.token_file)
        self.quiet = a.quiet

    def token_for(self, node_id):
        if self.single:
            return self.single
        return self.tokens.get(node_id)

    def handle(self, line):
        """Returns the reply line to write back, or None."""
        line = line.strip()
        if not line.startswith("NAMAA:"):
            if line and not self.quiet:
                log("board| " + line[:200])
            return None
        try:
            body = json.loads(line[len("NAMAA:"):])
            node_id = int(body["node_id"])
        except (ValueError, KeyError, TypeError):
            log("bad NAMAA line ignored")
            return None
        token = self.token_for(node_id)
        if not token:
            log("node %d: no token in the token file, reading dropped" % node_id)
            return None
        body["via"] = "usb"
        stamped = "recorded_at" not in body or body["recorded_at"] is None
        if stamped:
            body["recorded_at"] = utc_now_iso()
        status, text = post(self.url, token, body)
        msg = "node %d moisture %s pump_on %s%s -> " % (
            node_id, body.get("moisture"), body.get("pump_on"), "" if stamped else " (buffered " + str(body["recorded_at"]) + ")")
        if status in (200, 201):
            try:
                reply = json.loads(text)
            except ValueError:
                log(msg + "%d reply not JSON" % status)
                return None
            log(msg + "%d pump=%s interval=%s" % (status, "on" if reply.get("pump") else "off", reply.get("interval_s")))
            return "NAMAA-REPLY:" + json.dumps(reply, separators=(",", ":")) + "\n"
        if status == -1:
            log(msg + "server unreachable (%s)" % text)
        else:
            try:
                err = str(json.loads(text).get("message", ""))[:100]
            except (ValueError, AttributeError):
                err = text[:100]
            log(msg + "%d %s" % (status, err))
        return None  # no reply: the board counts it as a failed post


def run_stdin(bridge):
    for line in sys.stdin:
        reply = bridge.handle(line)
        if reply:
            sys.stdout.write(reply)
            sys.stdout.flush()
    log("stdin closed")


def find_port(serial_list_ports):
    ports = list(serial_list_ports.comports())
    for vid in KNOWN_VIDS:
        for p in ports:
            if p.vid == vid:
                return p.device
    return None


def run_serial(bridge, a):
    try:
        import serial  # pyserial, only needed here
        import serial.tools.list_ports as list_ports
    except ImportError:
        sys.exit("pyserial is missing: pip install -r tools/usb-bridge/requirements.txt")
    waiting_logged = False
    while True:
        port = a.port
        if a.auto:
            port = find_port(list_ports)
        if not port:
            if not waiting_logged:
                log("no ESP32 USB port found, waiting (plug the board in)")
                waiting_logged = True
            time.sleep(2)
            continue
        try:
            ser = serial.Serial(port, a.baud, timeout=1)
        except (serial.SerialException, OSError) as e:
            if not waiting_logged:
                log("cannot open %s (%s), retrying" % (port, e))
                waiting_logged = True
            time.sleep(2)
            continue
        waiting_logged = False
        log("connected to %s at %d baud -> %s" % (port, a.baud, bridge.url))
        try:
            buf = b""
            while True:
                chunk = ser.readline()
                if not chunk:
                    continue
                buf += chunk
                if not buf.endswith(b"\n"):
                    continue
                line = buf.decode("utf-8", "replace")
                buf = b""
                reply = bridge.handle(line)
                if reply:
                    ser.write(reply.encode())
                    ser.flush()
        except (serial.SerialException, OSError) as e:
            log("lost %s (%s): reconnecting" % (port, e))
            try:
                ser.close()
            except Exception:
                pass
            time.sleep(2)


def main():
    p = argparse.ArgumentParser(description="NAMAA USB bridge (board serial <-> API)")
    src = p.add_mutually_exclusive_group(required=True)
    src.add_argument("--port", help="serial port, e.g. COM5 or /dev/ttyUSB0")
    src.add_argument("--auto", action="store_true", help="find the board by USB vendor id")
    src.add_argument("--stdin", action="store_true", help="read NAMAA: lines from stdin, write replies to stdout")
    p.add_argument("--baud", type=int, default=115200)
    p.add_argument("--host", default="127.0.0.1", help="API host (default 127.0.0.1)")
    p.add_argument("--server-port", type=int, default=8000, help="API port (default 8000)")
    tok = p.add_mutually_exclusive_group(required=True)
    tok.add_argument("--token", help="one node token for every line")
    tok.add_argument("--token-file", nargs="?", const=str(DEFAULT_TOKEN_FILE),
                     help="node-tokens.txt; the token is picked by node_id (default: backend/storage/app/private/node-tokens.txt)")
    p.add_argument("--quiet", action="store_true", help="do not echo the board's other serial lines")
    a = p.parse_args()
    bridge = Bridge(a)
    if a.stdin:
        log("bridge stdin -> %s" % bridge.url)
        run_stdin(bridge)
    else:
        run_serial(bridge, a)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
