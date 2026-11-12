<?php

/*
| NAMAA settings: thresholds and timings live here, nowhere else.
| Values marked (proposed) are starting values, to be tuned after testing
| with the real boards.
|
| Timings have a normal and a demo value. Code reads them through
| App\Support\Namaa::timing('offline_seconds'), never by checking demo_mode.
*/

return [

    // DEMO_MODE=true in the root .env: short timings for a live demo.
    'demo_mode' => (bool) env('DEMO_MODE', false),

    // Every timing in seconds: [normal, demo].
    'timings' => [
        // Seconds until the board's next report (sent as interval_s).
        'interval_s' => ['normal' => 30, 'demo' => 5],
        // A sensor node without a reading for this long is offline (proposed).
        'offline_seconds' => ['normal' => 300, 'demo' => 30],
        // How often the offline check runs.
        'offline_check_seconds' => ['normal' => 60, 'demo' => 10],
        // Task verification: the alert's condition must stay cleared for this
        // continuous window of readings after Done ...
        'verify_window_seconds' => ['normal' => 600, 'demo' => 60],
        // ... within this time after Done, otherwise "not verified".
        // Litter tasks, demo only: this many clean camera frames in a row verify the task
        // instead of the window (0 = use the window).
        'verify_clean_frames' => ['normal' => 0, 'demo' => 3],
        'verify_timeout_seconds' => ['normal' => 3600, 'demo' => 180],
        // How often done tasks are checked.
        'verify_check_seconds' => ['normal' => 60, 'demo' => 10],
        // Safety cut-off: a pump on for longer than this is switched off.
        // Same in demo mode since Sprint 3.1: the simulated parks irrigate with the
        // real-time pump model (a session takes about 12 min), which 180 s would cut off.
        'pump_max_run_seconds' => ['normal' => 900, 'demo' => 900],
        // Simulator tick (one reading per simulated node).
        'simulator_tick_seconds' => ['normal' => 30, 'demo' => 5],
        // Relay test on the hardware page: the pump runs this long (same in demo mode).
        'relay_test_seconds' => ['normal' => 10, 'demo' => 10],
        // How often the pump safety check runs.
        'pump_check_seconds' => ['normal' => 30, 'demo' => 10],

        // Offline: map first, task later. The alert opens at offline_seconds,
        // can get a task after this long offline ...
        'offline_task_delay_seconds' => ['normal' => 1800, 'demo' => 120],
        // ... and rises to high after this long offline (or at once when the
        // park also has an open dry / heat_stress alert).
        'offline_long_seconds' => ['normal' => 21600, 'demo' => 300],

        // sensor_fault "stuck": the raw moisture value is exactly the same for
        // this long while readings keep arriving.
        'stuck_seconds' => ['normal' => 3600, 'demo' => 120],

        // blockage: pump on and low flow for this long (2 min = 4 readings
        // at 30 s; demo 15 s = 3 readings at 5 s).
        'blockage_seconds' => ['normal' => 120, 'demo' => 15],

        // Response time per priority: a task gets due_at = now + this.
        'response_urgent_seconds' => ['normal' => 3600, 'demo' => 120],      // 1 h
        'response_high_seconds' => ['normal' => 21600, 'demo' => 240],       // 6 h
        'response_normal_seconds' => ['normal' => 172800, 'demo' => 360],    // 48 h
        'response_low_seconds' => ['normal' => 604800, 'demo' => 600],       // weekly review
        // How often overdue open tasks are escalated.
        'escalation_check_seconds' => ['normal' => 60, 'demo' => 10],

        // Camera: a frame from CAMERA_URL/capture this often (only when CAMERA_URL is set).
        'camera_capture_seconds' => ['normal' => 300, 'demo' => 10],
        // litter_repeated: 3 litter frames within this window ...
        'litter_repeat_window_seconds' => ['normal' => 3600, 'demo' => 180],
        // ... or a litter alert still open after this long.
        'litter_open_long_seconds' => ['normal' => 86400, 'demo' => 180],
    ],

    // Rules engine (see the decision table). Counts are readings of one node, newest included.
    'rules' => [
        // Readings in a row needed to raise, per rule (dry 2; the others 3).
        'confirm_readings' => 3,
        'dry_confirm_readings' => 2,
        // over on a node without pump and flow sensor (Model B): wet soil may be rain
        // or a neighbour's irrigation, so it needs a longer streak.
        'over_no_pump_confirm_readings' => 6,
        // Readings in a row needed to resolve dry, over, leak, sensor_fault, low_battery, blockage.
        'resolve_readings' => 2,
        // Hysteresis for noisy soil sensors (points of moisture %): dry / heat_stress
        // resolve only at moisture_min + margin, over only at moisture_max - margin,
        // and task verification starts its window there. They still raise / break
        // at moisture_min / moisture_max, so noise around a limit cannot flap.
        'moisture_recovery_margin' => 5,
        // Flow below this (L/min) counts as "no flow" (sensor noise floor, proposed).
        'flow_zero_lpm' => 0.1,
        // Raw moisture at or beyond these ADC values is a broken or unplugged sensor.
        'fault_raw_low' => 50,
        'fault_raw_high' => 4090,
        // Heat stress: an open dry alert becomes heat_stress at this temperature (°C).
        'heat_stress_temp_c' => 40,
        // Low battery (field version): raise below, resolve above (%), 2 readings each.
        'battery_low_pct' => 20,
        'battery_ok_pct' => 25,
        'battery_confirm_readings' => 2,
        // Blockage (field version): pump on and flow below this share of the node's
        // expected flow; resolves above the second share.
        'blockage_low_ratio' => 0.5,
        'blockage_ok_ratio' => 0.7,
        // Litter (camera frames of one camera, consecutive): raise after 2 litter
        // frames, resolve after 2 clean frames. A frame has litter when /waste
        // found at least 1 item above its confidence threshold.
        'litter_confirm_frames' => 2,
        'litter_resolve_frames' => 2,
        // litter_repeated: this many litter frames within litter_repeat_window_seconds,
        // or this many items in one frame (or the alert open for litter_open_long_seconds).
        'litter_repeated_frames' => 3,
        'litter_repeated_items' => 5,
        // NDVI drop: the latest value this share below the average of the previous
        // ndvi_baseline_days, with at least ndvi_min_history values; resolves within ndvi_recover_ratio.
        'ndvi_drop_ratio' => 0.10,
        'ndvi_recover_ratio' => 0.05,
        'ndvi_baseline_days' => 28,
        'ndvi_min_history' => 3,
    ],

    // Impact, water (Sprint 3.1): NAMAA's pump time against a fixed irrigation
    // schedule, over the same measured time.
    //   ASSUMPTION, to confirm with the Amanah or the operator: a park on a
    //   timer is watered 2 sessions a day of 20 minutes. Override per park id:
    //   'parks' => [4 => ['sessions_per_day' => 3, 'minutes_per_session' => 15]].
    'water_baseline' => [
        'default' => ['sessions_per_day' => 2, 'minutes_per_session' => 20],
        'parks' => [],
    ],
    // NDVI: below this many valid Sentinel-2 pixels (10 m, about 2000 m2) in the outline the
    // value is "too small to measure reliably": shown as such, never used by ndvi_drop.
    'ndvi' => ['min_pixels' => 20],

    'impact' => [
        // A gap between two readings of the pump node longer than this is not
        // measured time (simulator stopped, laptop off, board down).
        'max_gap_seconds' => 90,
        // The water saving of a park is shown only with at least this many completed
        // irrigation cycles (pump on, then off) and this many measured hours.
        'min_cycles' => 1,
        'min_measured_hours' => 24,
        // Readings before this (UTC) are left out of the water comparison: before
        // Sprint 3.1 the simulator never let the healthy parks dry, so they never
        // needed water. Null = no limit.
        'water_since' => env('IMPACT_WATER_SINCE', '2026-11-10 06:36:13'),
    ],

    // One-off cleanups (Sprint 3.1). The scheduler gap fix (RepeatsWithinMinute stops at :50)
    // was saved at 02:56:10 UTC on Nov 10, 2026 and took effect on the next scheduler minute.
    'cleanup' => [
        'false_offline_before' => '2026-11-10 02:57:00',
    ],

    // Camera node (ESP32-S3-CAM running CameraWebServer). Empty URL = camera off.
    // Park outlines (php artisan namaa:parks:outlines): OpenStreetMap through Overpass.
    'overpass_url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
    'parks_seed_path' => base_path('../data/seed/parks.json'),

    'camera' => [
        'url' => env('CAMERA_URL', ''),
        'timeout_seconds' => 5,
        // Photos older than this are pruned daily, except frames attached to an alert.
        'retention_days' => 7,
        'disk' => 'local',
        // Sample photos for the litter scenario: {path}/litter and {path}/clean
        // (default data/samples, mounted read-only into the containers).
        'samples_path' => null,
    ],

    // Vision service (Python, Docker). Only Laravel calls it.
    'vision' => [
        'url' => env('VISION_SERVICE_URL', 'http://vision:8001'),
        'waste_timeout_seconds' => 30,
        'ndvi_timeout_seconds' => 120,
    ],

    // Where node tokens are kept for the person setting up the boards (never committed).
    'node_tokens_file' => storage_path('app/private/node-tokens.txt'),

    // Device route rate limit, per node.
    'device_readings_per_minute' => 120,

    // Login rate limit, per email + IP.
    'login_attempts_per_minute' => 5,

    // One-click demo sign-in (only when demo_mode is true). Fixed accounts:
    // the request can only pick the role, never an email.
    'demo_accounts' => [
        'admin' => 'admin@namaa.test',
        'worker' => 'worker1@namaa.test',
    ],
    'demo_login_per_minute' => 10,

    // Simulator and scenarios: simulated sensor nodes by name (fixed in the demo seed).
    'simulator' => [
        // Soil physics in real time (per hour / minute, the same with or without
        // DEMO_MODE; a reading every 5 s does not dry the soil faster). Sprint 3.1
        // report, Phase C, has the reasoning. Proposed values for Makkah in November,
        // sensor at about 10 cm in sandy park soil, in points of the sensor's 0-100 %:
        //  - drying: mean 1.5 %/h (about 36 points a day), following the heat of
        //    the day: rate = mean x (1 + swing x cos(2 pi (hour - 14) / 24)), so
        //    0.3 %/h at 02:00 and 2.7 %/h at 14:00 (Asia/Riyadh);
        //  - pump on: +1.8 % per minute (one automatic session from 25 % to the
        //    middle of the range, 47.5 %, takes about 12.5 minutes).
        'drying_per_hour' => 1.5,
        'drying_daily_swing' => 0.8,
        'pump_rise_per_minute' => 1.8,
        // Stage mode: the node a scenario puts on stage (Al Adel after `reset` or
        // `dry`) changes fast, so the watering story fits in a minute. It ends once
        // the pump has watered the node and is off again.
        'stage_drying_per_second' => 0.06,     // about -0.3 % per 5 s reading
        'stage_pump_rise_per_second' => 0.6,   // about +3 % per 5 s reading
        // A longer gap since the node's last reading (simulator stopped, laptop off)
        // is not simulated: the soil continues from where it was.
        'max_step_seconds' => 90,
        // Sensor noise on each reported value (the soil itself changes smoothly).
        'reading_noise_pct' => 0.3,
        // Simulated field version (solar + battery): battery % drops about 1 % per
        // 10 min and the "solar panel" keeps it at or above battery_floor_pct.
        'battery_nodes' => ['Sim 1', 'Sim 2', 'Sim 3'],
        'battery_drop_per_second' => 1 / 600,
        'battery_floor_pct' => 40.0,
        // Expected pump flow (L/min) set on simulated pump nodes by the scenarios.
        'expected_flow_l_min' => 2.0,
    ],
    'scenario' => [
        'dry_node' => 'Sim 1',      // Al Adel
        'leak_node' => 'Sim 2',     // Alkhatateen
        'offline_node' => 'Sim 3',  // Alhilal
        'reset_dry_pct' => 35.0,   // Al Adel after reset: above its 25 % minimum; only `dry` pushes it below
        'reset_healthy_pct' => 52.0,
        'heat_node' => 'Sim 1',     // Al Adel
        'heat_temp_c' => 42.0,
        'battery_node' => 'Sim 1',  // Al Adel
        'battery_pct' => 15.0,
        'reset_battery_pct' => 98.0,
        'blockage_node' => 'Sim 1', // Al Adel
        'blockage_flow_ratio' => 0.33,
    ],

];
