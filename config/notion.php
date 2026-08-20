<?php

return [

    /*
     * The integration token itself lives in the notion_settings table
     * (App\Models\NotionSetting::current()->api_key, encrypted), not here --
     * see that model. Rotating it is a person on Setup -> Notion, not an
     * env change plus a deploy.
     */

    'cache_ttl' => (int) env('NOTION_CACHE_TTL', 86400),

    /*
     * Chakra Productions' content-planning databases.
     *
     * `id` is only a fallback. The sync service resolves the real id by
     * searching the workspace for a database whose title contains
     * `name_contains`, because Notion ids change when a database is
     * duplicated or recreated — which already silently broke the Post
     * planner once. Property names/types are detected from the API payload
     * rather than configured here, for the same reason.
     */
    'databases' => [
        'youtube' => [
            'label' => 'YouTube',
            'name_contains' => 'content planner',
            'id' => '68032af9-aff5-82c1-a830-8139d96cbb6e',
        ],
        'reel' => [
            'label' => 'Reel',
            'name_contains' => 'reel planner',
            'id' => '2ba32af9-aff5-8063-af78-e65c7319f8b9',
        ],
        'post' => [
            'label' => 'Post',
            'name_contains' => 'post planner',
            'id' => '37d32af9-aff5-807b-be4b-de3d252f53e8',
        ],
        'story' => [
            'label' => 'Story',
            'name_contains' => 'story tracker',
            'id' => '37d32af9-aff5-8018-a74b-c397079c1df3',
        ],
        'shoot' => [
            'label' => 'Shoots',
            'name_contains' => 'shoots',
            'id' => '61632af9-aff5-8244-a7a9-0169f924c9d9',
        ],
    ],

    /*
     * Notion property names to look for, in priority order, for each local
     * column. Matching is exact first, then trimmed/case-insensitive — the
     * planners have properties literally named "Editor " and "" (yes, empty).
     */
    'properties' => [
        'venture' => ['Venture'],
        'status' => ['', 'Status', 'Stage'],
        'published_date' => ['Published Date'],
        'shoot_date' => ['Shoot Date'],
        'editor' => ['Editor', 'Posted by', 'Editor Name'],
        'tier' => ['Tier'],
        'post_type' => ['Post Type'],
        'ssd' => ['SSD'],
        'assigned_to' => ['Assigned To'],
        'effort_hours' => ['Effort Hours'],
        'insta_csv_link' => ['Insta CSV Link', 'Insta/YT CSV Link'],
        'yt_csv_link' => ['YT CSV Link'],
        'script' => ['Script'],
        'show_notes' => ['Show Notes'],
    ],

    /*
     * Notion property names for the Shoots database -- a separate map from
     * `properties` above, which is also read for the 4 content sources
     * (youtube/reel/post/story). A shoot-only name like "Location" has no
     * business being a candidate there.
     *
     * Alternate spellings exist because ContentSyncService::findProperty()'s
     * fuzzy pass is trimmed/case-insensitive but not whitespace-normalised
     * inside the string -- "Host / Model" and "Host/Model" are different
     * keys to it.
     */
    'shoot_properties' => [
        'status' => ['Status'],
        'client' => ['Client'],
        'team' => ['Team'],
        'host_model' => ['Host / Model', 'Host/Model', 'Host'],
        'location' => ['Location'],
        'shoot_date' => ['Date', 'Shoot Date'],
        'duration' => ['Duration'],
        'video_count' => ['No Of Videos', 'No of Videos'],
        'gear_needed' => ['Gear Needed'],
        'weather_forecast' => ['Weather Forecast'],
        'photo_url' => ['Photo'],
    ],

    /*
     * Board columns, in true production-pipeline order, keyed by source --
     * the Reel and Shoot pipelines have genuinely different status
     * vocabularies, so one shared list would not fit either well.
     *
     * `color` mirrors the Notion option colour so the board reads the same.
     * Note the real spelling difference, confirmed against the live
     * databases: the Reel planner's option is "Canceled" (one L); the
     * Shoots database's is "Cancelled" (two) -- both must match Notion's
     * actual option text character-for-character or that column renders
     * empty.
     */
    'boards' => [
        'reel' => [
            ['status' => 'Idea', 'color' => 'brown'],
            ['status' => 'To Be Shooted', 'label' => 'To Be Shot', 'color' => 'yellow'],
            ['status' => 'To Be Edited', 'color' => 'orange'],
            ['status' => 'Edit in Progress', 'color' => 'yellow'],
            ['status' => 'Under Review', 'color' => 'purple'],
            ['status' => 'Video Ready', 'color' => 'pink'],
            ['status' => 'Scheduled', 'color' => 'gray'],
            ['status' => 'Published', 'color' => 'green'],
            ['status' => 'Canceled', 'color' => 'red'],
        ],
        'shoot' => [
            ['status' => 'Planned', 'color' => 'brown'],
            ['status' => 'Shooting', 'color' => 'yellow'],
            ['status' => 'Editing', 'color' => 'orange'],
            ['status' => 'Review', 'color' => 'purple'],
            ['status' => 'Completed', 'color' => 'green'],
            ['status' => 'Cancelled', 'color' => 'red'],
        ],
    ],

];
