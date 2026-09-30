<?php

return [
    // Device is ONLINE if its last poll is at most this old (DEVICE_PROTOCOL.md §3).
    'online_timeout_sec' => (int) env('DEVICE_ONLINE_TIMEOUT_SEC', 15),
    // Session start is refused if the device was last seen longer ago than this.
    'start_max_last_seen_sec' => (int) env('DEVICE_START_MAX_LAST_SEEN_SEC', 20),
    'poll_interval_sec' => (int) env('DEVICE_POLL_INTERVAL_SEC', 3),
    'registration_secret' => env('DEVICE_REGISTRATION_SECRET'),
    'transport' => env('DEVICE_TRANSPORT', 'http'),
];
