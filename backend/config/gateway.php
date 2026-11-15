<?php

/*
| The NAMAA Gateway (the board network that runs the stand zones). The Gateway pushes
| telemetry and alerts to POST /api/namaa/ingest; the server only sends commands back.
*/
return [
    'url' => rtrim((string) env('GATEWAY_URL', ''), '/'),
    // Zone B camera (zone A uses CAMERA_URL); copied onto the Zone B camera node by namaa:stand:setup.
    'zone_b_camera_url' => rtrim((string) env('ZONE_B_CAMERA_URL', ''), '/'),
    // Optional: when set, the ingest route needs "Authorization: Bearer <token>".
    'ingest_token' => (string) env('NAMAA_INGEST_TOKEN', ''),
    'timeout' => 2,
    'offline_seconds' => 30,
    'queue' => 'gateway',
    // At most one stored reading per zone in this many seconds.
    'reading_every_seconds' => 2,
    'timeline_size' => 20,
    'test_pump_seconds' => 3,
];
