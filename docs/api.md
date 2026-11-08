# API Contract

First draft, to be agreed by the team before building starts. Any change is written here first and announced.

## 1) Ingest a reading from a sensor node

```
POST /api/readings
Authorization: Bearer <node-token>
Content-Type: application/json

{
  "node_id": 1,
  "moisture": 2850,
  "flow": 0.8,
  "temperature": 31.0,
  "humidity": 22.0,
  "pump_on": false,
  "water_level": 1900
}
```

| Field | Type | Meaning |
|---|---|---|
| `node_id` | int | Must match the token's node. |
| `moisture` | int | Raw capacitive soil reading (0-4095). Calibration to % happens on the server. |
| `flow` | float or null | Litres per minute from the YF-S401 (pulses / 5,880 per litre). `null` on Model B. |
| `temperature` | float or null | DHT11, degrees C. `null` if the read failed (NaN). |
| `humidity` | float or null | DHT11, % relative humidity. |
| `pump_on` | bool or null | The relay state the board actually has. `null` on Model B. |
| `water_level` | int or null | Raw HW-038 tank reading (optional). |

- The board has no clock: the server stamps `recorded_at` on arrival.
- Response `201`: `{ "pump": true | false }`. The board switches the relay (GPIO18) to match. The server decides; the board never turns the pump on by itself.
- Errors: `401` bad token, `422` missing or invalid data.
- Local base URL: `http://<laptop-IP>:<BACKEND_HOST_PORT>` (default port 8000, see `.env`).

## 2) Latest status of every park

```
GET /api/parks
```

Returns the list of parks with coordinates (`lat`, `lng`), the latest reading and its status (`ok` / `dry` / `over` / `offline`).

## 3) Reading history for one park

```
GET /api/parks/{id}/readings?from=...&to=...
```

## 4) Alerts

```
GET /api/alerts?status=open
```

## 5) Tasks (maintenance)

```
GET   /api/tasks?status=open&park_id=...
POST  /api/tasks                 { "park_id": 1, "alert_id": 12, "title": "...", "assignee": "...", "priority": "high" }
PATCH /api/tasks/{id}            { "status": "in_progress" | "done", "note": "..." }
```

- Statuses: `open` -> `in_progress` -> `done`. Closing a task stores `closed_at`.
- Once a task is `done`, the server checks whether readings returned to normal and sets `verified` (true/false) plus `time_to_resolve_minutes`. This is the step-7 proof of value.

## 6) Camera photos and waste detections

The camera node (GOOUUU ESP32-S3-CAM, OV3660) runs the stock **CameraWebServer** example. It does not call the server; Laravel fetches photos from it.

```
Laravel scheduler (every 5 min)
  -> GET  {CAMERA_URL}/capture              JPEG from the camera, over Wi-Fi
  -> save the photo in storage
  -> POST http://vision:8001/waste          multipart image
  <- { "count": 4, "boxes": [[x,y,w,h,conf], ...] }
  -> save a detection; raise an alert if litter is found in 2 photos in a row
```

- `CAMERA_URL` comes from the root `.env`. Empty means the camera is off.
- If the camera does not answer, the job logs it and tries again next cycle.

```
GET /api/detections?park_id=...&from=...&to=...
```

Returns `{ "id", "park_id", "count", "boxes": [[x,y,w,h,conf], ...], "image_url", "captured_at" }`.

## 7) NDVI (vegetation health)

```
GET /api/parks/{id}/ndvi?from=...&to=...
```

Returns a list of `{ "date": "2026-11-05", "mean": 0.42, "cloud_free": true, "source": "sentinel-2" }`.
The vision service computes these; the backend stores them. Internal vision-service endpoints:

```
POST /ndvi     { "park_id": 1, "polygon": <GeoJSON>, "date_from": "...", "date_to": "..." }
POST /waste    multipart image -> { "count": 4, "boxes": [...] }   (called by Laravel only)
```

## 8) Recommendations (rules engine)

```
GET /api/parks/{id}/recommendations
```

Returns `{ "park_id": 1, "issue": "dry_soil", "probable_cause": "...", "action": "...", "priority": "high", "signals": {...} }`.
Rules live in the backend as a decision table (signals -> probable cause -> action); the table is written in `docs/decision-table.md` (not yet written).

## 9) Data labelling

Every park, node and reading carries `source`: `real` or `simulated`. The dashboard shows it, so the demo never passes seeded data off as measured data.

## Open questions

- [ ] Is park status (dry / wet) computed on the server or the dashboard? (Suggested: server)
- [ ] Reporting interval from the node (Suggested: every 30 to 60 seconds)
- [ ] What counts as "offline"? (Suggested: no reading for more than 5 minutes)
