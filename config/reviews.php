<?php

return [
    /*
     * Master switch for "a video is Video ready only after its cut is
     * approved" (App\Support\ReviewGate). The day-to-day switch is in Review
     * settings on /reviews; this one exists for tests and emergencies.
     */
    'enforce' => (bool) env('REVIEW_GATE', true),
];
