<?php

/*
| NAMAA settings: thresholds and timings live here, nowhere else.
| Values marked (proposed) are starting values, to be tuned after testing
| with the real boards.
*/

return [

    // DEMO_MODE=true in the root .env: boards report every 5 s instead of 30 s.
    'demo_mode' => (bool) env('DEMO_MODE', false),

    // Seconds until the board's next report (sent as interval_s).
    'interval_s' => [
        'normal' => 30,
        'demo' => 5,
    ],

    // A node without a reading for this long is offline (proposed).
    'offline_minutes' => 5,

    // Device route rate limit, per node.
    'device_readings_per_minute' => 120,

    // Login rate limit, per email + IP.
    'login_attempts_per_minute' => 5,

];
