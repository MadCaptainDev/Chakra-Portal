<?php

return [

    /*
     * A shoot cannot leave for location, or start, until every item on its
     * kit list is ticked as picked up -- and a shoot with no kit list at all
     * cannot leave either. The switch exists for the day the rule is in the
     * way of a real shoot: set SHOOT_REQUIRE_KIT=false and re-cache config.
     */
    'require_kit' => (bool) env('SHOOT_REQUIRE_KIT', true),

    /*
     * Where footage is kept. The same names as the "SSD" property on the
     * Reel Planner in Notion, so the two never disagree about what a drive
     * is called.
     */
    'drives' => [
        'Annamalai SSD',
        'Keerthi SSD',
        "ARON's Drive",
        'Gokul Drive',
        'Local',
        'My Book',
    ],

    /* When "it is getting late" becomes an alert to the admins. */
    'late' => [
        // Not started this many minutes after the booked start.
        'start_minutes' => 15,
        // Still rolling this many minutes after the booked end.
        'overrun_minutes' => 20,
        // A shoot with no booked end is assumed to take this long.
        'default_hours' => 4,
        // Wrapped this many hours ago and the footage is not on a drive.
        'footage_hours' => 3,
        // Kit still out at this hour (24h, studio time) on the shoot's day.
        'kit_hour' => 21,
    ],

];
