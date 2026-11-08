# NAMAA | Team Workspace

Last updated: Nov 7, 2026 · Demo: Nov 18 and 19

---

## Team and roles

| Name | Role | Working on |
|---|---|---|
| | | |
| | | |
| | | |
| | | |

---

## The idea in brief

**NAMAA:** a smart platform for managing and monitoring parks and green spaces in Makkah.
It connects the parks in one dashboard and catches plant decline, irrigation problems and waste build-up early, instead of relying on field rounds.

**Components:**
- Satellite imagery + historical data → detect changes in vegetation cover
- IoT sensors → soil moisture and water flow
- Computer vision → waste detection
- Smart analytics → spot anomalies and rank priorities
- Geographic dashboard → park status, alerts, maintenance tasks, impact measurement
- (Later) smart irrigation control

**Goal:** raise operating efficiency, cut waste, and turn monitoring data into decisions that get executed.

---

## Project progress

| Phase | Status | Notes |
|---|---|---|
| 1. Setup (roles, parts, environment) | ⬜ | |
| 2. Sensor node (ESP32 + moisture + flow) | ⬜ | |
| 3. Server and database | ⬜ | |
| 4. Core dashboard (live readings + map) | ⬜ | |
| 5. Alerts + tasks | ⬜ | |
| 6. Satellite layer (NDVI) | ⬜ | |
| 7. Waste detection (computer vision) | ⬜ | |
| 8. Irrigation control (optional) | ⬜ | |
| 9. Full integration and testing | ⬜ | |
| 10. Pitch and rehearsals | ⬜ | |

⬜ not started · 🟦 in progress · ✅ done · 🟥 blocked

**This week:**
- Done:
- In progress:
- Blocked by:

---

## Master plan

The order is deliberate: foundation first, extras after. If time runs short, we cut from the bottom.

### Phase 1: Setup (Oct 29 to 31)
- [ ] Assign roles and an owner for each part (hardware, server, dashboard, data, vision, pitch)
- [ ] Parts list: ESP32, soil moisture sensor, water flow sensor, relay or valve, wires, power supply, ESP32-S3-CAM (camera node)
- [ ] Order parts now, with a local Makkah shop as backup if delivery is late
- [x] One code repository (GitHub `NAMAA-proj`) with the folder split
- [ ] Pick a test site (university campus or any park we can access)
- [x] Pick the tech stack: Laravel 13 (PHP 8.5), MySQL 8.4, React 19 + TypeScript, Python 3.12 FastAPI

### Phase 2: Sensor node (Nov 1 to 6)
- [ ] Read the moisture sensor and print values
- [ ] Calibrate (dry soil, wet soil, water)
- [ ] Read the flow sensor
- [ ] Send readings over Wi-Fi (HTTP or MQTT) at a fixed interval
- [ ] Assemble the node in an enclosure and run it for hours
- [ ] Second node as a backup

### Phase 3: Server (Nov 3 to 8)
- [ ] Receive and store readings
- [ ] API for latest status and history
- [ ] Seed data for several fake parks (to show the full network)
- [ ] Alert rules: dry, over-watered, abnormal flow, node offline

### Phase 4: Dashboard (Nov 5 to 11)
- [ ] Map with park locations, each colored by status
- [ ] Park detail page (live readings, time chart)
- [ ] Alerts list
- [ ] Tasks page: create a task from an alert, assign it, track it to closure
- [ ] Impact indicators: water saved, response time, issues resolved

### Phase 5: Satellite layer (Nov 6 to 12)
- [ ] Fetch free Sentinel-2 imagery for at least one site
- [ ] Compute NDVI and plot it over time
- [ ] Detect abnormal change (sudden drop in greenness)
- [ ] Show the result on the map as a layer
- [ ] Technical note: free imagery resolution is about 10 m (our understanding, needs confirmation). Fine for large parks; small ones need a test

### Phase 6: Waste detection (Nov 8 to 13)
- [ ] Collect or download a waste image set (public dataset + our own photos)
- [ ] Try a ready-made model (such as YOLO) on the images
- [ ] Run it on an image or video and send the result to the server
- [ ] Show a waste report on the dashboard with its photo

### Phase 7: Irrigation control (optional, Nov 10 to 14)
- [ ] Relay or small valve switched from the server
- [ ] Simple rule: moisture below a threshold turns irrigation on, another threshold turns it off
- [ ] Compare water used against a fixed-schedule baseline

### Phase 8: Integration and testing (Nov 12 to 15)
- [ ] Run everything together end to end
- [ ] Run it continuously for hours and log failures
- [ ] Fallback mode that runs on recorded data if the internet or a sensor fails
- [ ] Screenshots and a short video of the prototype working

### Phase 9: Pitch (Nov 15 to 17)
- [ ] Story: problem, solution, live demo, impact, next step
- [ ] Slides
- [ ] Minute-by-minute live demo script
- [ ] Rehearsal 1, rehearsal 2 with the real hardware
- [ ] Likely judge questions and answers

### Nov 18 and 19: Demo days

### Cut line (if time runs short, cut from the bottom up)
1. Irrigation control
2. Waste detection
3. Satellite layer
4. Tasks (alerts only)

What we never cut: **a working sensor node + a dashboard showing its live readings + alerts.**

---

## Task tracker (we fill this in ourselves)

| # | Task | Owner | Due | Status | Notes |
|---|---|---|---|---|---|
| 1 | | | | ⬜ | |
| 2 | | | | ⬜ | |
| 3 | | | | ⬜ | |
| 4 | | | | ⬜ | |
| 5 | | | | ⬜ | |
| 6 | | | | ⬜ | |
| 7 | | | | ⬜ | |
| 8 | | | | ⬜ | |
| 9 | | | | ⬜ | |
| 10 | | | | ⬜ | |
| 11 | | | | ⬜ | |
| 12 | | | | ⬜ | |

---

## How we improve it

Drop any improvement idea here. Never delete anything; move to the plan what we decide to build.

| Idea | Impact | Difficulty | Build it? |
|---|---|---|---|
| Tie smart irrigation to the weather forecast (no watering before rain) | | | |
| Use treated water and measure its quality with sensors | | | |
| Predict thirst before it shows on the plant | | | |
| Mobile app for field workers to receive tasks | | | |
| Automatic weekly report for each park | | | |
| Scoring system for each park's condition | | | |
| | | | |
| | | | |

---

## Free thinking space

Write anything here. Ideas, questions, links, sketches, doubts, anything.

-
-
-
-
-

---

## Quick notes

- Nov 2, 6:00 to 7:00 PM (remote): workshop on prototype testing
- First day of the hackathon: pitching and marketing workshops (in person)
- Judging criteria: we still don't have them in writing
