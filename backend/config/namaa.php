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
        'verify_timeout_seconds' => ['normal' => 3600, 'demo' => 180],
        // How often done tasks are checked.
        'verify_check_seconds' => ['normal' => 60, 'demo' => 10],
        // Safety cut-off: a pump on for longer than this is switched off.
        'pump_max_run_seconds' => ['normal' => 900, 'demo' => 180],
        // Simulator tick (one reading per simulated node).
        'simulator_tick_seconds' => ['normal' => 30, 'demo' => 5],
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
    ],

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
        // Story per node name; any other simulated sensor node is "healthy".
        'stories' => ['Sim 1' => 'drying'],
        // Moisture change per tick (%).
        'drying_per_tick' => 0.3,
        'pump_rise_per_tick' => 3.0,
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
        'reset_dry_pct' => 22.0,
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
