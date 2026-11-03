# API Contract

First draft, to be agreed by the team before building starts. Any change is written here first and announced.

## 1) Ingest a reading from a node

```
POST /api/readings
Authorization: Bearer <node-token>
Content-Type: application/json

{
  "node_id": 1,
  "moisture": 412,
  "flow": 0.8,
  "recorded_at": "2026-11-05T10:00:00Z"
}
```

- `moisture`: raw sensor value (calibration happens on the server).
- `flow`: litres per minute. Send `null` if there is no reading.
- Responses: `201` on success, `401` for a bad token, `422` for missing or invalid data.
- Local base URL: `http://<machine-IP>:<BACKEND_HOST_PORT>` (default port 8000, see `.env`).

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

## 6) Waste detections

```
POST /api/detections             (called by the camera capture script; Bearer token)
{ "park_id": 1, "camera_id": 1, "count": 4, "boxes": [[x,y,w,h,conf], ...], "image_path": "...", "detected_at": "..." }

GET  /api/detections?park_id=...&from=...&to=...
```

## 7) NDVI (vegetation health)

```
GET /api/parks/{id}/ndvi?from=...&to=...
```

Returns a list of `{ "date": "2026-11-05", "mean": 0.42, "cloud_free": true, "source": "sentinel-2" }`.
The vision service computes these; the backend stores them. Internal vision-service endpoints:

```
POST /ndvi     { "park_id": 1, "polygon": <GeoJSON>, "date_from": "...", "date_to": "..." }
POST /waste    multipart image -> { "count": 4, "boxes": [...] }
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
