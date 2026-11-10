#!/usr/bin/env python3
"""NAMAA fake sensor board (stdlib only).

Speaks the same protocol as firmware/node-sensor:
  * HTTP mode (default): POST /api/device/readings with the node's Bearer token,
    applies `pump` and `interval_s` from the reply.
  * --serial: prints `NAMAA:{json}` lines on stdout (like the board in USB mode)
    and reads `NAMAA-REPLY:{json}` lines from stdin. Pipe it through
    tools/usb-bridge/usb_bridge.py --stdin.

The token is never printed. Examples:
  python fake_board.py --node-id 1 --token-file
  python fake_board.py --node-id 1 --token-file --dry --interval 5
  python fake_board.py --node-id 1 --token-file --offline-after 3 --resume 60
"""
import argparse
import datetime as dt
import json
import queue
import random
import sys
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path

FW_VERSION = "fake-board-1.0"
API_PATH = "/api/device/readings"
REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_TOKEN_FILE = REPO_ROOT / "backend" / "storage" / "app" / "private" / "node-tokens.txt"
BUFFER_MAX = 180  # same as the firmware ring buffer
FLUSH_PER_CYCLE = 10
REPLY_TIMEOUT_S = 10.0


def log(msg):
    # In --serial mode stdout is the data channel, so logs always go to stderr.
    print(time.strftime("%H:%M:%S") + " " + msg, file=sys.stderr, flush=True)


def read_token(path, node_id):
    """Returns the token of node_id from node-tokens.txt (tab separated:
    node id, node, park, source, token). Never prints it."""
    try:
        lines = Path(path).read_text(encoding="utf-8").splitlines()
    except OSError as e:
        sys.exit("cannot read token file %s: %s" % (path, e.strerror))
    for line in lines:
        cols = [c.strip() for c in line.split("\t")]
        if len(cols) >= 2 and cols[0].isdigit() and int(cols[0]) == node_id:
            token = cols[-1]
            if "|" in token:
                return token
    sys.exit("node %d not found in %s" % (node_id, path))


def utc_iso(ts):
    return dt.datetime.fromtimestamp(ts, dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Sim:
    """Very small soil/pump/flow model."""

    def __init__(self, a):
        self.a = a
        self.f = set(x.strip() for x in a.features.split(",") if x.strip())
        if a.dry:
            self.pct = 15.0
        elif a.wet:
            self.pct = 85.0
        else:
            self.pct = 45.0
        self.pump = False
        self.boot = time.time()

    def step(self, dt_s):
        if "relay" in self.f and self.pump:
            self.pct += 0.08 * dt_s  # watering raises moisture
        else:
            self.pct -= 0.005 * dt_s  # slow drying
        if self.a.dry:
            self.pct = min(self.pct, 20.0)
        if self.a.wet:
            self.pct = max(self.pct, 80.0)
        self.pct = max(0.0, min(100.0, self.pct))

    def reading(self):
        a, f = self.a, self.f
        r = {"node_id": a.node_id}
        if "soil" in f:
            raw = a.dry_value - (self.pct / 100.0) * (a.dry_value - a.wet_value)
            r["moisture"] = int(max(0, min(4095, raw + random.uniform(-15, 15))))
        else:
            r["moisture"] = None
        if "flow" in f:
            if a.no_flow:
                flow = 0.0
            elif self.pump:
                flow = 1.5 + random.uniform(-0.1, 0.1)
            elif a.leak:
                flow = 0.6 + random.uniform(-0.05, 0.05)
            else:
                flow = 0.0
            r["flow"] = round(flow, 2)
        else:
            r["flow"] = None
        if "dht" in f:
            r["temperature"] = round(33.0 + random.uniform(-0.5, 0.5), 1)
            r["humidity"] = round(25.0 + random.uniform(-1.0, 1.0), 1)
        else:
            r["temperature"] = None
            r["humidity"] = None
        r["pump_on"] = self.pump if "relay" in f else None
        r["water_level"] = None
        return r


class SerialLink:
    """stdout/stdin as the board's USB serial port."""

    def __init__(self):
        self.q = queue.Queue()
        threading.Thread(target=self._reader, daemon=True).start()

    def _reader(self):
        for line in sys.stdin:
            line = line.strip()
            if line.startswith("NAMAA-REPLY:"):
                self.q.put(line[len("NAMAA-REPLY:"):])
        self.q.put(None)  # stdin closed

    def send(self, body, wait):
        if wait:
            # Like the firmware: replies that nobody waited for (buffered sends) are ignored.
            while True:
                try:
                    if self.q.get_nowait() is None:
                        self.q.put(None)
                        break
                except queue.Empty:
                    break
        sys.stdout.write("NAMAA:" + json.dumps(body, separators=(",", ":")) + "\n")
        sys.stdout.flush()
        if not wait:
            return 0, None
        try:
            raw = self.q.get(timeout=REPLY_TIMEOUT_S)
        except queue.Empty:
            return -1, "no NAMAA-REPLY"
        if raw is None:
            self.q.put(None)
            return -1, "stdin closed"
        return 201, raw


def http_post(url, token, body):
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


def short_error(text):
    try:
        j = json.loads(text)
        return str(j.get("message", ""))[:80]
    except (ValueError, AttributeError):
        return (text or "")[:80]


def main():
    p = argparse.ArgumentParser(description="NAMAA fake sensor board")
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=8000)
    p.add_argument("--node-id", type=int, required=True)
    g = p.add_mutually_exclusive_group()
    g.add_argument("--token", help="node token (prefer --token-file: it keeps the token out of shell history)")
    g.add_argument("--token-file", nargs="?", const=str(DEFAULT_TOKEN_FILE),
                   help="read the node's token from node-tokens.txt (default: backend/storage/app/private/node-tokens.txt)")
    p.add_argument("--features", default="soil,dht,relay,flow", help="comma list of soil,dht,relay,flow")
    p.add_argument("--dry", action="store_true", help="soil stays dry (about 15%%)")
    p.add_argument("--wet", action="store_true", help="soil stays wet (about 85%%)")
    p.add_argument("--no-flow", action="store_true", help="flow 0 even with the pump on (blocked pipe)")
    p.add_argument("--leak", action="store_true", help="flow while the pump is off (leak)")
    p.add_argument("--offline-after", type=int, metavar="N", help="go offline after N posts (readings are buffered)")
    p.add_argument("--resume", type=float, metavar="S",
                   help="with --offline-after: come back after S seconds and send the buffer with recorded_at")
    p.add_argument("--serial", action="store_true", help="USB mode: NAMAA: lines on stdout, NAMAA-REPLY: on stdin")
    p.add_argument("--interval", type=float, default=30.0, help="seconds between reports until the server sends interval_s")
    p.add_argument("--count", type=int, default=0, help="stop after this many live readings (0 = forever)")
    p.add_argument("--dry-value", type=int, default=3000, help="raw ADC value of dry soil")
    p.add_argument("--wet-value", type=int, default=1300, help="raw ADC value of wet soil")
    a = p.parse_args()
    if a.dry and a.wet:
        p.error("--dry and --wet exclude each other")
    if a.resume is not None and a.offline_after is None:
        p.error("--resume needs --offline-after")

    token = None
    if not a.serial:
        if a.token:
            token = a.token
        elif a.token_file:
            token = read_token(a.token_file, a.node_id)
        else:
            p.error("HTTP mode needs --token or --token-file")
    url = "http://%s:%d%s" % (a.host, a.port, API_PATH)
    link = SerialLink() if a.serial else None
    via = "usb" if a.serial else "wifi"
    log("fake board node %d -> %s (%s), features %s, token %s" % (
        a.node_id, "stdout" if a.serial else url, via, a.features,
        "from file" if a.token_file else ("given" if token else "bridge")))

    sim = Sim(a)
    interval = a.interval
    backlog = []
    posts = 0
    offline_since = None
    n = 0
    last = time.time()

    def send(body, wait=True):
        if link:
            return link.send(body, wait)
        return http_post(url, token, body)

    while True:
        now = time.time()
        sim.step(now - last)
        last = now
        n += 1
        r = sim.reading()
        r["uptime_s"] = int(now - sim.boot)
        r["fw_version"] = FW_VERSION
        r["via"] = via
        if not a.serial:
            r["rssi"] = random.randint(-70, -50)

        offline = False
        if a.offline_after is not None and posts >= a.offline_after:
            if offline_since is None:
                offline_since = now
                log("going offline (buffering readings)")
            if a.resume is None or now - offline_since < a.resume:
                offline = True
            elif offline_since is not None and a.resume is not None:
                log("back online: %d buffered readings to send" % len(backlog))
                a.offline_after = None

        if offline:
            r["_taken"] = now
            backlog.append(r)
            if len(backlog) > BUFFER_MAX:
                backlog.pop(0)
            if "relay" in sim.f and sim.pump:
                sim.pump = False  # no fresh reply: the guard turns the pump off
            log("#%d offline: buffered (%d), moist %.1f%%" % (n, len(backlog), sim.pct))
        else:
            status, text = send(r)
            posts += 1
            line = "#%d moist %.1f%% raw %s pump %s flow %s -> " % (
                n, sim.pct, r["moisture"], "on" if sim.pump else "off", r["flow"])
            if status in (200, 201):
                try:
                    rep = json.loads(text)
                    if "relay" in sim.f:
                        sim.pump = bool(rep.get("pump", False))
                    if rep.get("interval_s"):
                        interval = max(1, min(3600, int(rep["interval_s"])))
                    line += "%d pump=%s interval=%ss%s" % (
                        status, "on" if rep.get("pump") else "off", int(interval),
                        " local_rule" if rep.get("local_rule") else "")
                except (ValueError, TypeError):
                    line += "%d reply not understood" % status
                log(line)
                # Send the backlog oldest first, a few per cycle.
                for _ in range(min(FLUSH_PER_CYCLE, len(backlog))):
                    b = dict(backlog[0])
                    b["recorded_at"] = utc_iso(b.pop("_taken"))
                    bs, bt = send(b, wait=not a.serial)
                    if bs in (200, 201, 422, 0) or (bs in (401, 403)):
                        backlog.pop(0)
                        log("  buffered %s -> %s%s" % (b["recorded_at"], bs if bs else "printed",
                                                       "" if bs in (200, 201, 0) else " " + short_error(bt)))
                    else:
                        log("  buffered send failed (%s): retry later" % bs)
                        break
            else:
                if status == -1:
                    line += "no connection (%s)" % text
                else:
                    line += "%d %s" % (status, short_error(text))
                if status not in (401, 403, 422):
                    r["_taken"] = now
                    backlog.append(r)
                    if len(backlog) > BUFFER_MAX:
                        backlog.pop(0)
                    line += " (buffered)"
                interval = a.interval
                if "relay" in sim.f:
                    sim.pump = False
                log(line)

        if a.count and n >= a.count:
            break
        time.sleep(interval)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
