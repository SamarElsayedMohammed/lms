<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Lesson & Course Completion Threshold
    |--------------------------------------------------------------------------
    |
    | The default minimum watched coverage percentage required to complete a lesson.
    | Can be overridden per-course via $course->completion_threshold.
    | Completion is strictly based on server-validated watched coverage.
    | Position, end margin, or 'ended' events alone never grant completion.
    |
    */
    'completion_threshold' => (float) env('LESSON_COMPLETION_THRESHOLD', 95.0),

    /*
    |--------------------------------------------------------------------------
    | Anti-Cheat Playback Speed & Tolerance Rules
    |--------------------------------------------------------------------------
    |
    | Maximum legitimate playback speed accommodated by the platform (e.g. 2.0x).
    | Tolerances allow network jitter and minor playback rate variation without
    | opening time-inflation or seek-to-end idle exploits.
    |
    */
    'max_playback_speed' => 2.0,
    'speed_tolerance_multiplier' => 2.2, // 2.0x playback speed + 10% drift
    'network_latency_tolerance_seconds' => 4,
    'max_heartbeat_window_seconds' => 15,
];
